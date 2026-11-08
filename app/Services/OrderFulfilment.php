<?php

namespace App\Services;

use App\Models\ClientOrder;
use App\Models\ClientOrderItem;
use App\Models\InventoryHandover;
use App\Models\OrderCollection;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\StockMovement;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Customer orders for a client's own stock. This EXTENDS the existing order-commission module (it creates the same ClientOrder and
 * uses the same commission rate snapshot and invoice); it adds order lines, stock reservation, delivery status and the customer money
 * collected on the client's behalf.
 *
 *   pending → confirmed (stock reserved, oldest lot first) → dispatched → delivered | failed;   cancelled from pending/confirmed.
 *
 * Only a DELIVERED order earns commission (on what was really delivered and not returned). A failed or cancelled order earns nothing,
 * and its stock goes back to available. Delivery and collection are separate events: money counts only when a collection is recorded.
 */
class OrderFulfilment
{
    /**
     * @param  array{reference: string, ordered_on: string, customer_name?: ?string, customer_phone?: ?string, customer_address?: ?string, items: list<array{product_id: int, quantity: string, unit_price: int}>}  $data
     */
    public static function create(Project $project, array $data, User $user): ClientOrder
    {
        if ($data['items'] === []) {
            throw new RuntimeException('Add at least one product.');
        }

        return DB::transaction(function () use ($project, $data, $user) {
            $total = 0;
            $items = [];
            foreach ($data['items'] as $row) {
                $product = SupplierProduct::findOrFail($row['product_id']);
                // Only stock this project may sell can be put on an order: the client's own lots for the project (or allocated to it) and the custody handed to it.
                if (Inventory::stockFor($project->client_id, $product->id, $project->id)->isEmpty()) {
                    throw new RuntimeException($product->label().' has no stock available to this project, so it cannot be ordered.');
                }
                // The quantity is limited too: never more than this project is entitled to (confirming re-checks this under the lot lock).
                $wanted = collect($data['items'])->where('product_id', $row['product_id'])->reduce(fn ($c, $r) => bcadd($c, (string) $r['quantity'], 2), '0');
                Inventory::allocate($project->client_id, $product->id, $wanted, $project->id);
                if (bccomp($row['quantity'], '0', 2) <= 0 || $row['unit_price'] <= 0) {
                    throw new RuntimeException('Every product needs a quantity and a selling price above zero.');
                }
                $discount = (int) ($row['unit_discount'] ?? 0);
                if ($discount < 0 || $discount >= $row['unit_price']) {
                    throw new RuntimeException('A discount must be above zero and below the selling price.');
                }
                if ($discount > 0 && trim((string) ($data['discount_reason'] ?? '')) === '') {
                    throw new RuntimeException('Give the reason for the discount.');
                }
                $item = ['supplier_product_id' => $product->id, 'description' => $product->label(), 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price'], 'unit_discount' => $discount];
                $total += (new ClientOrderItem($item))->amount();
                $items[] = $item;
            }
            $order = OrderCommission::record($project, ['reference' => $data['reference'], 'ordered_on' => $data['ordered_on'], 'net_value' => $total], $user);
            $order->update(['fulfilment' => 'stock', 'delivery_status' => 'pending', 'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null, 'customer_address' => $data['customer_address'] ?? null, 'sales_channel' => $data['sales_channel'] ?? null,
                'payment_method' => $data['payment_method'] ?? null, 'delivery_method' => $data['delivery_method'] ?? null, 'discount_reason' => ($data['discount_reason'] ?? null) ?: null,
                'delivery_charge' => max(0, (int) ($data['delivery_charge'] ?? 0))]);
            $order->items()->createMany($items);
            Audit::log('order.created', 'order '.$order->id, $order->reference.' '.$total);

            return $order->refresh();
        });
    }

    /**
     * Run one order step under the order's row lock. On MySQL the transaction reads COMMITTED data: under the default REPEATABLE READ, the order is read (and a
     * snapshot taken) BEFORE the lot lock is won, so after waiting for another order the stock sums would be stale and two orders could take the same units.
     */
    private static function locked(ClientOrder $order, \Closure $work): mixed
    {
        if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction(fn () => $work(ClientOrder::whereKey($order->id)->lockForUpdate()->with(['items', 'project.client'])->firstOrFail()));
    }

    private static function expect(ClientOrder $o, string ...$states): void
    {
        if (! $o->isStock() || ! in_array($o->delivery_status, $states, true)) {
            throw new RuntimeException('This order is '.($o->delivery_status ?? 'not a stock order').'; that step is not available now.');
        }
    }

    /** Reserve the stock: each requested product is taken from the client's oldest lots first, and the exact source lot is kept on the order line. */
    public static function confirm(ClientOrder $order, User $user, ?int $locationId = null): void
    {
        self::locked($order, function (ClientOrder $o) use ($user, $locationId) {
            self::expect($o, 'pending');
            $clientId = $o->project->client_id;
            // Serialise with every other order and allocation touching the same lots (always in id order, so two confirmations cannot deadlock): the
            // project's entitlement is then read and used under one lock, and two projects can never take the same units.
            $lotIds = SourcingLotLine::whereIn('supplier_product_id', $o->items->pluck('supplier_product_id'))
                ->whereHas('lot', fn ($q) => $q->where('ownership', 'client')->whereHas('project', fn ($p) => $p->where('client_id', $clientId)))->pluck('sourcing_lot_id')->unique()->sort()->values();
            SourcingLot::whereIn('id', $lotIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($o->items as $request) {
                $plan = Inventory::allocate($clientId, (int) $request->supplier_product_id, (string) $request->quantity, (int) $o->project_id);
                $first = true;
                foreach ($plan as [$line, $qty, $custody]) {
                    // A custody row keeps its handover on the order line, so every later step moves the same units and nothing of another client or project.
                    $item = $first ? tap($request)->update(['quantity' => $qty, 'sourcing_lot_line_id' => $line->id, 'inventory_handover_id' => $custody?->id])
                        : $o->items()->create(['supplier_product_id' => $request->supplier_product_id, 'description' => $request->description, 'quantity' => $qty, 'unit_price' => $request->unit_price, 'unit_discount' => $request->unit_discount, 'sourcing_lot_line_id' => $line->id, 'inventory_handover_id' => $custody?->id]);
                    $first = false;
                    Inventory::move($line, 'reserve', ['available' => bcmul($qty, '-1', 2), 'reserved' => $qty], 'res:'.$item->id, $item, $user, null, null, $locationId); // an explicit location reserves only the units standing there
                }
            }
            $o->update(['delivery_status' => 'confirmed', 'confirmed_at' => now()]);
            Audit::log('order.confirmed', 'order '.$o->id, 'stock reserved');
        });
    }

    /** The goods are picked and packed. The stock stays reserved (nothing has left the warehouse), so this moves no quantity. */
    public static function pack(ClientOrder $order, User $user): void
    {
        self::locked($order, function (ClientOrder $o) use ($user) {
            self::expect($o, 'confirmed');
            $o->update(['delivery_status' => 'packed', 'packed_at' => now()]);
            Audit::log('order.packed', 'order '.$o->id, 'by '.$user->name);
        });
    }

    /** Packing is optional: an order can be dispatched straight after it is confirmed. */
    public static function dispatch(ClientOrder $order, User $user, ?string $courier = null, ?string $tracking = null): void
    {
        self::locked($order, function (ClientOrder $o) use ($user, $courier, $tracking) {
            self::expect($o, 'confirmed', 'packed');
            foreach ($o->items as $item) {
                Inventory::move($item->line, 'dispatch', ['reserved' => bcmul((string) $item->quantity, '-1', 2), 'dispatched' => (string) $item->quantity], 'dsp:'.$item->id, $item, $user);
            }
            $o->update(['delivery_status' => 'dispatched', 'dispatched_at' => now(), 'courier' => $courier ?: $o->courier, 'tracking_reference' => $tracking ?: $o->tracking_reference]);
            Audit::log('order.dispatched', 'order '.$o->id);
        });
    }

    /**
     * Delivery confirmed. $delivered maps item id → quantity actually delivered (default: all); the rest returns to available stock.
     * Commission is calculated now, on what was delivered, with the rate snapshotted at this moment.
     *
     * @param  array<int, string>  $delivered
     */
    public static function deliver(ClientOrder $order, array $delivered, User $user): void
    {
        self::locked($order, function (ClientOrder $o) use ($delivered, $user) {
            self::expect($o, 'dispatched');
            $total = 0;
            foreach ($o->items as $item) {
                $qty = (string) ($delivered[$item->id] ?? $item->quantity);
                if (bccomp($qty, '0', 2) < 0 || bccomp($qty, (string) $item->quantity, 2) > 0) {
                    throw new RuntimeException('Delivered quantity of '.$item->description.' must be between 0 and '.$item->quantity.'.');
                }
                $rest = bcsub((string) $item->quantity, $qty, 2);
                if (bccomp($qty, '0', 2) > 0) {
                    Inventory::move($item->line, 'deliver', ['dispatched' => bcmul($qty, '-1', 2), 'delivered' => $qty], 'dlv:'.$item->id, $item, $user);
                }
                if (bccomp($rest, '0', 2) > 0) {
                    // Units the courier brings back are in custody again but NOT sellable until they are inspected.
                    Inventory::move($item->line, 'fail', ['dispatched' => bcmul($rest, '-1', 2), 'returned' => $rest], 'fail:'.$item->id, $item, $user, 'Not delivered: returned to custody, awaiting inspection');
                }
                $item->update(['delivered_quantity' => $qty]);
                $total += (int) $item->refresh()->deliveredAmount();
            }
            if ($total <= 0) {
                $o->update(['delivery_status' => 'failed']);
                OrderCommission::cancel($o);
                Audit::log('order.failed', 'order '.$o->id, 'nothing delivered');

                return;
            }
            $o->update(['delivery_status' => 'delivered', 'net_value' => $total, 'delivered_at' => now()]);
            self::earnCommission($o->refresh());
            Audit::log('order.delivered', 'order '.$o->id, 'value '.$total);
        });
    }

    /** Commission on the delivered value, rate snapshotted now; billed through the client's invoice (unless the standing policy already did). */
    private static function earnCommission(ClientOrder $o): void
    {
        OrderCommission::markSuccessful($o);
        $o->refresh();
        if ($o->commission > 0 && ! $o->billed_invoice_id) {
            OrderCommission::bill($o, null, null);
        }
    }

    /**
     * The courier could not deliver: the units come back into custody and wait for inspection (they are sellable again only once inspected as usable),
     * and no commission is earned.
     */
    public static function fail(ClientOrder $order, User $user): void
    {
        self::locked($order, function (ClientOrder $o) use ($user) {
            self::expect($o, 'dispatched');
            foreach ($o->items as $item) {
                Inventory::move($item->line, 'fail', ['dispatched' => bcmul((string) $item->quantity, '-1', 2), 'returned' => (string) $item->quantity], 'fail:'.$item->id, $item, $user, 'Delivery failed: returned to custody, awaiting inspection');
            }
            $o->update(['delivery_status' => 'failed']);
            OrderCommission::cancel($o);
            Audit::log('order.failed', 'order '.$o->id);
        });
    }

    public static function cancel(ClientOrder $order, User $user): void
    {
        self::locked($order, function (ClientOrder $o) use ($user) {
            self::expect($o, 'pending', 'confirmed', 'packed');
            if (in_array($o->delivery_status, ['confirmed', 'packed'], true)) {
                foreach ($o->items as $item) {
                    Inventory::move($item->line, 'release', ['reserved' => bcmul((string) $item->quantity, '-1', 2), 'available' => (string) $item->quantity], 'rel:'.$item->id, $item, $user, 'Order cancelled');
                }
            }
            $o->update(['delivery_status' => 'cancelled']);
            OrderCommission::cancel($o);
            Audit::log('order.cancelled', 'order '.$o->id);
        });
    }

    /**
     * A customer sent delivered goods back. The units wait for inspection; they are NOT sellable until inspected. Commission follows what
     * stays delivered. Refunding the customer is a separate Finance matter, so a return is refused while it would leave more collected
     * than the order is now worth, or when the commission has already been paid or netted (no silent rewriting of settled money).
     *
     * @param  array<int, string>  $returned  item id → quantity
     */
    public static function customerReturn(ClientOrder $order, array $returned, User $user): void
    {
        self::locked($order, function (ClientOrder $o) use ($returned, $user) {
            self::expect($o, 'delivered');
            foreach ($o->items as $item) {
                $qty = (string) ($returned[$item->id] ?? '0');
                if (bccomp($qty, '0', 2) <= 0) {
                    continue;
                }
                if (bccomp(bcadd((string) $item->returned_quantity, $qty, 2), (string) $item->delivered_quantity, 2) > 0) {
                    throw new RuntimeException('Cannot return more of '.$item->description.' than was delivered.');
                }
                $item->update(['returned_quantity' => bcadd((string) $item->returned_quantity, $qty, 2)]);
                Inventory::move($item->line, 'return', ['delivered' => bcmul($qty, '-1', 2), 'returned' => $qty], 'ret:'.$item->id.':'.$item->returned_quantity, $item, $user, 'Customer return');
            }
            $o->refresh()->load('items');
            $value = $o->deliveredValue();
            if ((int) $o->collections()->sum('amount') - OrderRefunds::paid($o) > $value) {
                throw new RuntimeException('More money was collected than the order is now worth: refund the customer first (Customer Refunds), then record the return.');
            }
            // The commission base is the delivered value less any paid refund that already lowered it, so a return never undoes (or repeats) that correction.
            $base = max(0, $value - OrderRefunds::baseReduction($o));
            if ($o->billed_invoice_id && $o->commission > 0 && $base !== (int) $o->net_value) {
                OrderCommission::reduceInvoiced($o, $base > 0 ? $base : null, 'Customer return'); // refuses once the commission was paid or netted
            } elseif ($o->commission > 0 && ! $o->billed_invoice_id) {
                $o->update(['commission' => OrderCommission::commission($base, (int) $o->rate_bps)]);
            }
            $o->update(['net_value' => $base]);
            Audit::log('order.returned', 'order '.$o->id, 'delivered value now '.$value);
        });
    }

    /** Units of this order line that came back (customer return or failed delivery) and have not been inspected yet. */
    public static function awaitingInspection(ClientOrderItem $item): string
    {
        return bcadd((string) StockMovement::where('client_order_item_id', $item->id)->sum('d_returned'), '0', 2);
    }

    /**
     * After inspection the returned units are either usable again (available) or written off as damaged. Only the units THIS order line brought back can be
     * inspected, and each only once: the line is locked, so two submissions cannot inspect the same units twice.
     */
    public static function inspectReturn(ClientOrderItem $item, string $usable, string $damaged, User $user): void
    {
        DB::transaction(function () use ($item, $usable, $damaged, $user) {
            $item = ClientOrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $total = bcadd($usable, $damaged, 2);
            if (bccomp($total, '0', 2) <= 0 || bccomp($usable, '0', 2) < 0 || bccomp($damaged, '0', 2) < 0) {
                throw new RuntimeException('Enter how many returned units are usable and how many are damaged.');
            }
            if (bccomp($total, self::awaitingInspection($item), 2) > 0) {
                throw new RuntimeException('Only '.self::awaitingInspection($item).' unit(s) of this order line are waiting for inspection; '.$total.' were entered.');
            }
            Inventory::move($item->line, 'inspect', ['returned' => bcmul($total, '-1', 2), 'available' => $usable, 'damaged' => $damaged],
                'insp:'.$item->id.':'.(StockMovement::where('client_order_item_id', $item->id)->where('type', 'inspect')->count() + 1), $item, $user, 'Return inspected');
            Audit::log('inventory.return_inspected', 'order item '.$item->id, "usable $usable damaged $damaged");
        });
    }

    /**
     * Customer money collected for a delivered order, held for the client. Dr cash / Cr client funds — it is the CLIENT'S money, not
     * company revenue; only the commission and storage fees later netted from it become company income.
     *
     * @return OrderCollection|null null when this exact submission was already recorded
     */
    public static function collect(ClientOrder $order, int $amount, string $date, ?string $reference, string $key, ?int $accountId, User $user): ?OrderCollection
    {
        if (OrderCollection::where('idempotency_key', $key)->exists()) {
            return null;
        }
        try {
            return DB::transaction(function () use ($order, $amount, $date, $reference, $key, $accountId, $user) {
                $o = ClientOrder::whereKey($order->id)->lockForUpdate()->with(['items.line.lot', 'project'])->firstOrFail();
                self::expect($o, 'delivered');
                $custodyOk = fn ($i) => $i->inventory_handover_id !== null && InventoryHandover::whereKey($i->inventory_handover_id)->where('client_id', $o->project->client_id)->where('project_id', $o->project_id)->exists();
                if ($o->items->contains(fn ($i) => $i->line?->lot?->ownership !== 'client' && ! $custodyOk($i))) {
                    throw new RuntimeException('Only stock stated as client-owned can be settled to a client. State who owns the stock first.');
                }
                if ($amount <= 0) {
                    throw new RuntimeException('Amount must be greater than zero.');
                }
                if (AccountingService::isClosed($date)) {
                    throw new RuntimeException('That month is already closed; record it in an open month.');
                }
                $left = $o->deliveredValue() - ((int) $o->collections()->sum('amount') - OrderRefunds::paid($o));
                if ($amount > $left) {
                    throw new RuntimeException('Only '.Money::format(max(0, $left)).' of this order is still to be collected.');
                }
                $collection = OrderCollection::create(['client_order_id' => $o->id, 'amount' => $amount, 'collected_on' => $date, 'reference' => $reference,
                    'financial_account_id' => $accountId, 'idempotency_key' => $key, 'created_by' => $user->id]);
                Ledger::post($date, 'Customer collection held for client — order '.$o->reference, 'order_collection', $collection->id, [
                    ['cash', null, $amount, 0, AccountingService::activeAccountId($accountId)],
                    ['client.funds', null, 0, $amount],
                ], $user->id);
                Audit::log('order.collected', 'order '.$o->id, (string) $amount);

                return $collection;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** @return array{delivered: int, collected: int, outstanding: int, state: string} */
    public static function collectionState(ClientOrder $o): array
    {
        $delivered = $o->delivery_status === 'delivered' ? $o->deliveredValue() : 0;
        $collected = (int) $o->collections()->sum('amount') - OrderRefunds::paid($o); // money still held after refunds paid back

        return ['delivered' => $delivered, 'collected' => $collected, 'outstanding' => max(0, $delivered - $collected),
            'state' => $delivered === 0 ? '—' : ($collected >= $delivered ? 'Collected' : ($collected > 0 ? 'Part collected' : 'Awaiting collection'))];
    }

    public static function today(): string
    {
        return BusinessDate::today();
    }
}
