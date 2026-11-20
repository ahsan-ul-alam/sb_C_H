<?php

namespace App\Services;

use App\Models\ClientOrderItem;
use App\Models\InventoryHandover;
use App\Models\Project;
use App\Models\SourcingArrivalEvent;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SourcingLotLine;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Edit lot": an admin changes a client lot at ANY stage — Processing, In order or Completed, after the goods were received too. The admin controls
 * the lot; the stage never locks it.
 *
 * Every figure the system shows is calculated from the lot's own records (LotFinancials, ProjectEconomics, the project report, the client portal),
 * so changing them is enough for profit, project profitability and the reports to follow. Only the records that would otherwise disagree with the
 * corrected values are reconciled, in the same transaction, through the existing inventory code:
 *
 *   - quantity after receiving: the receipt movements are brought to the corrected quantity (Inventory::syncReceipt posts only the difference) and
 *     the arrival record follows; a reduction of units that were already sold, reserved or moved out is refused with an explanation;
 *   - stock location after receiving: the units still available are moved with the existing stock transfer;
 *   - received date and delivery note / GRN: the arrival record and the line;
 *   - supplier or product after receiving: the line is relabelled (its stock is the line's stock, so nothing is received again); refused once units
 *     of it were sold, reserved or handed over, because those records name the original product.
 *
 * Nothing here records, rewrites or reverses a payment, an expense, an invoice or a journal entry: payments are managed separately in the existing
 * financial screens. Each edit is delta-based, so repeating it changes nothing ("Nothing was changed."), and the lot row is locked for the whole
 * edit, so simultaneous edits apply one after the other on the current values. Every field's before → after is written to the activity history.
 */
class ClientLotCorrection
{
    public const STAGE_STATUS = ['processing' => 'approved', 'in_order' => 'in_progress', 'completed' => 'completed'];

    /**
     * @param  array{supplier_id: int, supplier_product_id: int, project_id: int, quantity: string, unit_cost: int, client_budget: int, delivery_charge: int, stage: string, arrived_on?: ?string, reference?: ?string, location_id?: ?int, notes?: ?string, reason?: ?string}  $data
     * @return list<string> what was changed, in plain words
     */
    public static function apply(SourcingLot $lot, array $data, User $user): array
    {
        if (! $user->can('sourcing.manage')) {
            throw new RuntimeException('You are not allowed to edit lots.');
        }
        if (! preg_match('/^[1-9]\d{0,9}$/', $data['quantity'])) {
            throw new RuntimeException('Quantity must be a whole number greater than zero.');
        }
        if ($data['unit_cost'] <= 0 || $data['client_budget'] <= 0) {
            throw new RuntimeException('The unit base cost and the client budget must be above zero.');
        }
        if ($data['delivery_charge'] < 0) {
            throw new RuntimeException('The delivery charge cannot be negative (enter 0 for free delivery).');
        }
        if (! array_key_exists($data['stage'], self::STAGE_STATUS)) {
            throw new RuntimeException('Choose Processing, In order or Completed.');
        }

        return DB::transaction(function () use ($lot, $data, $user) {
            $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (! $lot->hasClientBudget()) {
                throw new RuntimeException('Only a lot made on the simple client-lot form can be edited here; manage this lot from the detailed view.');
            }
            $line = $lot->lines()->firstOrFail();
            $before = LotFinancials::for($lot);
            $changes = [];

            self::project($lot, (int) $data['project_id'], $changes);
            self::product($line, (int) $data['supplier_id'], (int) $data['supplier_product_id'], $changes);
            self::quantityAndCost($line, $data['quantity'], $data['unit_cost'], $user, $changes);
            if ($line->refresh()->received_quantity !== null) {
                self::receiving($line, $data, $user, $changes);
            }
            if ((int) $lot->client_budget !== $data['client_budget']) {
                $changes[] = 'client budget '.Money::format((int) $lot->client_budget).' → '.Money::format($data['client_budget']);
                $lot->update(['client_budget' => $data['client_budget']]);
            }
            self::delivery($lot, $data['delivery_charge'], $user, $changes);
            self::stage($lot, $line->refresh(), $data['stage'], $user, $changes);
            $notes = trim((string) ($data['notes'] ?? ''));
            if ($notes !== trim((string) $lot->notes)) {
                $changes[] = 'notes updated';
                $lot->update(['notes' => $notes !== '' ? $notes : null]);
            }

            if ($changes === []) {
                throw new RuntimeException('Nothing was changed.');
            }
            // The indicative unit price and the lot's snapshot follow the corrected figures, so every report reads the same numbers.
            $unit = intdiv((int) $lot->client_budget, max(1, (int) $line->quantity));
            $line->update(['sell_price' => $unit, 'approved_price' => $unit]);
            $after = LotFinancials::for($lot->refresh());
            $lot->update(['approved_cost' => $after['landed'], 'approved_revenue' => (int) $lot->client_budget]);
            InventoryValuation::syncLot($lot->id);

            $reason = trim((string) ($data['reason'] ?? ''));
            Audit::log('sourcing.client_lot.edited', 'lot '.$lot->number, implode('; ', $changes).' | profit '.Money::format($before['profit']).' → '.Money::format($after['profit']).($reason !== '' ? ' | note: '.$reason : ''));

            return $changes;
        });
    }

    /** @param  list<string>  $changes */
    private static function project(SourcingLot $lot, int $projectId, array &$changes): void
    {
        if ($projectId === (int) $lot->project_id) {
            return;
        }
        $project = Project::whereKey($projectId)->whereNull('archived_at')->first() ?? throw new RuntimeException('Choose an active client project.');
        $changes[] = 'project '.($lot->project?->name ?? '—').' → '.$project->name;
        $lot->update(['project_id' => $project->id]);
    }

    /**
     * Supplier and product. The line's stock is the line's own, so relabelling it never receives anything again. Once units of it were sold,
     * reserved, delivered or handed over, those records name the original product, so the change is refused with the way to correct it.
     *
     * @param  list<string>  $changes
     */
    private static function product(SourcingLotLine $line, int $supplierId, int $productId, array &$changes): void
    {
        if ($supplierId === (int) $line->supplier_id && $productId === (int) $line->supplier_product_id) {
            return;
        }
        $supplier = Supplier::find($supplierId) ?? throw new RuntimeException('Choose a supplier.');
        $product = SupplierProduct::where('supplier_id', $supplier->id)->find($productId) ?? throw new RuntimeException('Choose a product supplied by '.$supplier->name.'.');
        if ($line->received_quantity !== null && self::consumed($line)) {
            throw new RuntimeException('Units of '.$line->description.' were already sold, reserved or handed over, and those records name this product. Keep the product, '
                .'or create a new lot for the correct product and remove these units with a stock adjustment.');
        }
        if ($supplierId !== (int) $line->supplier_id) {
            $changes[] = 'supplier '.($line->supplier?->name ?? '—').' → '.$supplier->name;
        }
        if ($productId !== (int) $line->supplier_product_id) {
            $changes[] = 'product '.$line->description.' → '.$product->label();
        }
        $line->update(['supplier_id' => $supplier->id, 'supplier_product_id' => $product->id, 'description' => $product->label(), 'unit' => $product->unit ?: $line->unit]);
    }

    /**
     * Quantity and unit base cost. After receiving, the stock follows the corrected quantity through the existing receipt sync (only the difference is
     * posted, so repeating the edit posts nothing), and the arrival record carries the same total.
     *
     * @param  list<string>  $changes
     */
    private static function quantityAndCost(SourcingLotLine $line, string $quantity, int $unitCost, User $user, array &$changes): void
    {
        $oldQty = (string) $line->quantity;
        $newQty = bcadd($quantity, '0', 2);
        $qtyChanged = bccomp($oldQty, $newQty, 2) !== 0;
        if ((int) $line->unit_price !== $unitCost) {
            $changes[] = 'unit base cost '.Money::format((int) $line->unit_price).' → '.Money::format($unitCost);
            $line->update(['unit_price' => $unitCost]);
        }
        if (! $qtyChanged) {
            return;
        }
        $changes[] = 'quantity '.self::qty($oldQty).' → '.self::qty($newQty);
        $shortfall = bcsub($oldQty, (string) $line->usable_quantity, 2); // units accepted short at arrival stay short
        $usable = bccomp(bcsub($newQty, $shortfall, 2), '0', 2) > 0 ? bcsub($newQty, $shortfall, 2) : $newQty;
        if ($line->received_quantity === null) {
            $line->update(['quantity' => $newQty, 'usable_quantity' => $usable]);

            return;
        }
        $delta = bcsub($usable, (string) $line->usable_quantity, 2);
        $available = Inventory::balances($line)['available'];
        if (bccomp($delta, '0', 2) < 0 && bccomp(bcadd($available, $delta, 2), '0', 2) < 0) {
            throw new RuntimeException('Only '.self::qty($available).' of '.$line->description.' are still in stock (the rest were sold, reserved or moved out), so the quantity cannot go down by '
                .self::qty(ltrim($delta, '-')).'. Lower it by at most '.self::qty($available).', or correct the orders first.');
        }
        $line->update(['quantity' => $newQty, 'usable_quantity' => $usable, 'received_quantity' => bcadd((string) $line->received_quantity, bcsub($newQty, $oldQty, 2), 2)]);
        $event = SourcingArrivalEvent::where('sourcing_lot_line_id', $line->id)->latest('id')->first();
        $event?->update(['received_quantity' => bcadd((string) $event->received_quantity, bcsub($newQty, $oldQty, 2), 2), 'accepted_quantity' => bcadd((string) $event->accepted_quantity, $delta, 2)]);
        Inventory::syncReceipt($line, $user, $event?->id, $event?->stock_location_id);
    }

    /**
     * Received date, delivery note / GRN and stock location of a received lot. A new location moves the units still available there with the existing
     * stock transfer (out of the old place, into the new one), so the stock total never changes.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $changes
     */
    private static function receiving(SourcingLotLine $line, array $data, User $user, array &$changes): void
    {
        $event = SourcingArrivalEvent::where('sourcing_lot_line_id', $line->id)->latest('id')->first();
        $arrivedOn = ($data['arrived_on'] ?? null) ?: $line->arrived_on?->toDateString();
        if ($arrivedOn !== null && $arrivedOn !== $line->arrived_on?->toDateString()) {
            if ($arrivedOn > today()->toDateString()) {
                throw new RuntimeException('The received date cannot be in the future.');
            }
            $changes[] = 'received date '.($line->arrived_on?->format('j M Y') ?? '—').' → '.date('j M Y', strtotime($arrivedOn));
            $line->update(['arrived_on' => $arrivedOn]);
            $event?->update(['arrived_on' => $arrivedOn]);
        }
        $reference = trim((string) ($data['reference'] ?? ''));
        if ($reference !== '' && $event !== null && $reference !== $event->reference) {
            if (SourcingArrivalEvent::where('sourcing_lot_line_id', $line->id)->where('reference', $reference)->whereKeyNot($event->id)->exists()) {
                throw new RuntimeException('Delivery note '.$reference.' is already recorded for this product.');
            }
            $changes[] = 'delivery note / GRN '.$event->reference.' → '.$reference;
            $event->update(['reference' => $reference]);
            $line->update(['acceptance_reference' => $reference]);
        }
        $locationId = (int) ($data['location_id'] ?? 0);
        $from = $event?->stock_location_id;
        if ($locationId > 0 && $locationId !== (int) $from) {
            $to = StockLocation::where('is_active', true)->findOrFail($locationId);
            $movable = StockLocations::availableAt($line, $from);
            if (bccomp($movable, '0', 2) > 0) {
                $transfer = StockLocations::request($line, null, $from, $to->id, $movable, 'Lot edit: stock location corrected', $user);
                StockLocations::complete($transfer, $user);
            }
            $changes[] = 'stock location '.(StockLocation::whereKey($from)->value('name') ?? '—').' → '.$to->name.(bccomp($movable, '0', 2) > 0 ? ' ('.self::qty($movable).' moved)' : '');
            $event?->update(['stock_location_id' => $to->id]);
        }
    }

    /**
     * The delivery charge is the lot's Delivery cost: changed, added or removed. A cost the admins have already paid separately keeps its payment
     * record untouched (it is never rewritten here) and only its amount on the lot follows.
     *
     * @param  list<string>  $changes
     */
    private static function delivery(SourcingLot $lot, int $amount, User $user, array &$changes): void
    {
        $replaced = $lot->costs()->whereNotNull('replaces_id')->pluck('replaces_id')->all();
        $costs = $lot->costs()->where('kind', 'actual')->where('category', 'Delivery')->whereNotIn('id', $replaced)->orderBy('id')->get();
        $old = (int) $costs->sum('amount');
        if ($old === $amount) {
            return;
        }
        $last = $costs->last();
        $others = $old - (int) ($last?->amount ?? 0);
        if ($last === null) {
            SourcingLotCost::create(['sourcing_lot_id' => $lot->id, 'category' => 'Delivery', 'description' => 'Delivery charge', 'amount' => $amount,
                'kind' => 'actual', 'is_unexpected' => false, 'incurred_on' => today()->toDateString(), 'created_by' => $user->id]);
        } elseif ($amount < $others) {
            throw new RuntimeException('This lot has several delivery costs; the delivery charge cannot be below the earlier ones ('.Money::format($others).').');
        } elseif ($amount - $others === 0 && $last->expense_id === null) {
            $last->delete();
        } else {
            $last->update(['amount' => $amount - $others]);
        }
        $changes[] = 'delivery charge '.Money::format($old).' → '.Money::format($amount);
    }

    /**
     * The stage as chosen. Completed means the goods were received, so a lot is completed with "Mark Completed" (which receives them) the first time;
     * after that it can be moved between the stages freely without moving stock or money. A lot whose books were closed keeps that when it stays Completed.
     *
     * @param  list<string>  $changes
     */
    private static function stage(SourcingLot $lot, SourcingLotLine $line, string $stage, User $user, array &$changes): void
    {
        if ($stage === $lot->stage()) {
            return;
        }
        if ($stage === 'completed' && $line->received_quantity === null) {
            throw new RuntimeException('Use Mark Completed to receive the goods; the lot becomes Completed then.');
        }
        $status = self::STAGE_STATUS[$stage];
        $attributes = ['status' => $status, 'settled_at' => null, 'settled_by' => null, 'cancelled_at' => null, 'cancel_reason' => null];
        if ($status !== 'approved' && $lot->ordered_at === null) {
            $attributes += ['ordered_at' => now(), 'ordered_by' => $user->id];
        }
        $changes[] = 'stage '.$lot->stageLabel().' → '.SourcingLot::STAGES[$stage];
        $lot->update($attributes);
    }

    /** Whether units of the line already left the shelf or were promised: sold, reserved, delivered, returned, damaged, handed over or on an order. */
    private static function consumed(SourcingLotLine $line): bool
    {
        $b = Inventory::balances($line);
        foreach (['reserved', 'dispatched', 'delivered', 'returned', 'damaged'] as $bucket) {
            if (bccomp($b[$bucket], '0', 2) !== 0) {
                return true;
            }
        }

        return ClientOrderItem::where('sourcing_lot_line_id', $line->id)->exists() || InventoryHandover::where('sourcing_lot_line_id', $line->id)->exists();
    }

    private static function qty(string $q): string
    {
        return rtrim(rtrim($q, '0'), '.');
    }
}
