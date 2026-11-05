<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Invoice;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\RevenueAdjustment;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The client-safe view returned by the client information API. READ ONLY: it reuses the existing services and tables (invoices, payments, the stock
 * ledger via ProjectInventory, orders, refunds, commission) and never writes, posts, moves stock or approves anything. Every figure is an explicit
 * allowlisted field; nothing is serialised from a model, and supplier names, unit costs, staff, partner or company-wide figures are never included.
 * Totals are computed over the client's whole dataset; the lists beside them are bounded and say so.
 */
class ClientInformation
{
    public const LIST_LIMIT = 25;

    public const PRODUCT_LIMIT = 50;

    /** @return array<string, mixed> */
    public static function for(Client $client): array
    {
        $projects = $client->projects()->orderBy('id')->get();
        $projectIds = $projects->pluck('id');

        return [
            'sb_number' => $client->sb_number,
            'client' => ['name' => $client->name, 'status' => $client->isArchived() ? 'archived' : 'active',
                'projects' => self::bounded($projects->map(fn ($p) => ['project_id' => $p->id, 'name' => $p->name, 'status' => $p->status->value,
                    'currency' => $p->currency, 'started_on' => $p->started_on?->toDateString(), 'archived' => $p->isArchived()]), self::LIST_LIMIT)],
            'financial' => self::financial($projects, $projectIds),
            'inventory' => self::inventory($client),
            'sales' => self::sales($client),
            'profitability' => self::profitability($client, $projects),
        ];
    }

    /** @return array<string, mixed> */
    private static function financial(Collection $projects, Collection $projectIds): array
    {
        $currencyOf = $projects->pluck('currency', 'id');
        $invoices = Invoice::whereIn('project_id', $projectIds)->orderByDesc('issued_on')->orderByDesc('id')->get();
        $payments = Payment::whereIn('project_id', $projectIds)->orderByDesc('paid_on')->orderByDesc('id')->get();
        $adjustments = RevenueAdjustment::query()->join('payments', 'payments.id', '=', 'revenue_adjustments.payment_id')->where('revenue_adjustments.status', 'verified')
            ->whereIn('payments.project_id', $projectIds)->selectRaw('payments.project_id project_id, revenue_adjustments.kind kind, SUM(revenue_adjustments.amount) amount')
            ->groupBy('payments.project_id', 'revenue_adjustments.kind')->get();

        $by = [];
        foreach ($currencyOf->unique() as $currency) {
            $in = fn (Collection $rows) => $rows->filter(fn ($r) => $currencyOf[$r->project_id] === $currency);
            $inv = $in($invoices);
            $pay = $in($payments);
            $adj = $adjustments->filter(fn ($r) => $currencyOf[$r->project_id] === $currency);
            $refunded = (int) $adj->where('kind', 'refund')->sum('amount');
            $by[$currency] = [
                'currency' => $currency,
                'total_invoiced_minor' => (int) $inv->sum('total'), 'total_paid_on_invoices_minor' => (int) $inv->sum('amount_paid'),
                'outstanding_minor' => (int) $inv->sum(fn (Invoice $i) => $i->balance()),
                'total_received_minor' => (int) $pay->sum('amount'), 'total_refunded_minor' => $refunded, 'net_collections_minor' => (int) $pay->sum('amount') - $refunded,
                'customer_advances_held_minor' => (int) $adj->where('kind', 'advance')->sum('amount') - (int) $adj->where('kind', 'recognition')->sum('amount'),
            ];
        }
        ksort($by);
        $single = count($by) === 1 ? array_values($by)[0] : null;

        return ($single ?? ['currency' => null, 'mixed_currencies' => count($by) > 1])
            + ['by_currency' => array_values($by),
                'invoices' => self::bounded($invoices->map(fn (Invoice $i) => [
                    'reference' => $i->number, 'issued_on' => $i->issued_on->toDateString(), 'due_on' => $i->due_on?->toDateString(), 'project_id' => $i->project_id,
                    'currency' => $currencyOf[$i->project_id], 'total_minor' => (int) $i->total, 'paid_minor' => (int) $i->amount_paid, 'outstanding_minor' => $i->balance(), 'status' => $i->status->value,
                ]), self::LIST_LIMIT),
                'payments' => self::bounded($payments->map(fn (Payment $p) => [
                    'reference' => $p->reference, 'paid_on' => $p->paid_on->toDateString(), 'amount_minor' => (int) $p->amount, 'currency' => $currencyOf[$p->project_id],
                    'method' => $p->method->value, 'project_id' => $p->project_id, 'status' => 'recorded',
                ]), self::LIST_LIMIT),
                'notes' => ['Invoice totals already include any discount or adjustment on the invoice; none is recorded separately.']];
    }

    /** @return array<string, mixed> */
    private static function inventory(Client $client): array
    {
        $rows = ProjectInventory::positions(['client' => $client->id]);
        $products = $rows->groupBy(fn ($r) => implode('|', [$r['project']?->id ?? 'none', $r['line']->supplier_product_id ?? 'x'.$r['line']->description, $r['unit'], $r['ownership']]))->map(function (Collection $group) {
            $first = $group->first();
            $sum = fn (string $k) => $group->reduce(fn ($c, $r) => bcadd($c, (string) $r['b'][$k], 2), '0');
            $accepted = $group->reduce(fn ($c, $r) => bcadd($c, (string) ($r['accepted'] ?? '0'), 2), '0');
            $received = $group->reduce(fn ($c, $r) => bcadd($c, (string) ($r['own_slice'] ? $r['line']->received_quantity : '0'), 2), '0');

            return [
                'project_id' => $first['project']?->id, 'product_id' => $first['line']->supplier_product_id, 'sku' => $first['line']->product?->sku,
                'name' => $first['line']->description, 'unit' => $first['unit'],
                'received' => $received, 'accepted' => $accepted, 'available' => $sum('available'), 'reserved' => $sum('reserved'), 'dispatched' => $sum('dispatched'),
                'delivered' => $sum('delivered'), 'returned' => $sum('returned'), 'damaged_or_quarantined' => $sum('damaged'),
                'remaining' => bcadd(bcadd($sum('available'), $sum('reserved'), 2), $sum('returned'), 2),
                'ownership' => $first['ownership'], 'ownership_verified' => $first['ownership'] !== 'unstated',
                'valuation_status' => $first['valuation'], 'shared_lot' => $first['attribution'] === 'shared',
            ];
        })->values();
        $totals = ProjectInventory::totals($rows);

        return ['products' => self::bounded($products, self::PRODUCT_LIMIT),
            'totals_by_unit' => $totals['units'],
            'note' => 'Quantities are never added across different units of measure. Stock on a lot whose ownership is not verified is shown with ownership_verified=false.'];
    }

    /** @return Collection<int, ClientOrder> */
    private static function orders(Client $client): Collection
    {
        return ClientOrder::where('fulfilment', 'stock')->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->with(['items.line.lot', 'collections', 'project'])->orderByDesc('ordered_on')->orderByDesc('id')->get();
    }

    /** @return array<string, mixed> */
    private static function sales(Client $client): array
    {
        $orders = self::orders($client);
        $currency = (string) config('finance.currency');
        $inCurrency = $orders->filter(fn ($o) => $o->project->currency === $currency);
        $live = $inCurrency->where('delivery_status', '!=', 'cancelled');
        $refunds = OrderRefund::where('status', 'paid')->whereIn('client_order_id', $inCurrency->pluck('id'))->get();
        $returnedValue = (int) $inCurrency->sum(fn (ClientOrder $o) => $o->items->sum(fn ($i) => Money::multiply((string) $i->returned_quantity, $i->netUnitPrice())));
        $delivered = $inCurrency->where('delivery_status', 'delivered');
        $net = self::netVerifiedSales($delivered, $refunds);

        return [
            'currency' => $currency,
            'orders_total' => $inCurrency->count(), 'orders_delivered' => $delivered->count(), 'orders_cancelled' => $inCurrency->where('delivery_status', 'cancelled')->count(),
            'gross_order_value_minor' => (int) $live->sum(fn (ClientOrder $o) => $o->items->sum(fn ($i) => $i->amount())),
            'delivered_value_minor' => (int) $delivered->sum(fn (ClientOrder $o) => $o->deliveredValue()),
            'returns_value_minor' => $returnedValue, 'total_refunded_minor' => (int) $refunds->sum('amount'),
            'net_sales_minor' => $net['net'], 'sales_reductions_from_refunds_minor' => $net['reduction'],
            'commission_minor' => (int) $inCurrency->where('status', 'successful')->sum('commission'),
            'orders' => self::bounded($inCurrency->map(fn (ClientOrder $o) => ['reference' => $o->reference, 'ordered_on' => $o->ordered_on->toDateString(), 'project_id' => $o->project_id,
                'status' => SalesStatus::of($o), 'net_value_minor' => (int) $o->net_value, 'commission_minor' => (int) $o->commission]), self::LIST_LIMIT),
            'excluded_other_currency_orders' => $orders->count() - $inCurrency->count(),
        ];
    }

    /**
     * Delivered value (already net of discounts and returned units) less paid refunds that reduce the sale (price reductions, cancellations).
     * A refund of returned goods is not deducted again: the return already lowered the delivered value.
     *
     * @param  Collection<int, ClientOrder>  $delivered
     * @param  Collection<int, OrderRefund>  $refunds
     * @return array{net: int, reduction: int}
     */
    private static function netVerifiedSales(Collection $delivered, Collection $refunds): array
    {
        $reduction = (int) $refunds->whereIn('client_order_id', $delivered->pluck('id'))->where('commission_treatment', 'reduce')->sum('amount');

        return ['net' => (int) $delivered->sum(fn (ClientOrder $o) => $o->deliveredValue()) - $reduction, 'reduction' => $reduction];
    }

    /**
     * Client profit: net verified sales - cost of the units sold - client-borne delivery costs - client-borne operating expenses - Sellbees commission.
     * The ERP records the first, second and last of these. It has NO record of client-borne delivery or operating costs, so a profit figure is never given:
     * the result is "incomplete" with the exact reasons, and the recorded components are shown separately and labelled as not being profit.
     * The cost of units sold is what the client was billed for the lot's goods (the lot line's approved price), never the supplier price and never
     * with freight added again; it is used only for client-owned lots whose price was approved.
     *
     * @return array<string, mixed>
     */
    private static function profitability(Client $client, Collection $projects): array
    {
        $currency = (string) config('finance.currency');
        $orders = self::orders($client)->filter(fn ($o) => $o->project->currency === $currency);
        $delivered = $orders->where('delivery_status', 'delivered');
        $refunds = OrderRefund::where('status', 'paid')->whereIn('client_order_id', $delivered->pluck('id'))->get();
        $missing = [];

        if ($projects->contains(fn ($p) => $p->currency !== $currency)) {
            $missing[] = 'Projects in a currency other than '.$currency.' are not included in this calculation.';
        }
        if ($delivered->isEmpty()) {
            $missing[] = 'No delivered end-customer sales are recorded.';
        }
        $net = self::netVerifiedSales($delivered, $refunds);

        $cost = 0;
        foreach ($delivered as $order) {
            foreach ($order->items as $item) {
                $netUnits = bcsub((string) $item->delivered_quantity, (string) $item->returned_quantity, 2);
                $line = $item->line;
                if ($line === null) {
                    $missing[] = 'Order '.$order->reference.': a line has no stock lot, so its product cost is unknown.';
                } elseif ($line->lot->ownership !== 'client') {
                    $missing[] = 'Order '.$order->reference.': the stock came from a lot whose ownership is '.($line->lot->ownership ?? 'not verified').', so no client product cost can be attributed.';
                } elseif ($line->approved_price === null) {
                    $missing[] = 'Order '.$order->reference.': the lot price billed to the client for '.$line->description.' is not approved.';
                } else {
                    $cost += Money::multiply($netUnits, (int) $line->approved_price);
                }
            }
        }
        foreach ($refunds as $refund) {
            $treatment = $refund->commission_treatment;
            $neutral = $treatment === 'keep' && in_array($refund->classification, ['return_of_goods', 'delivery_fee'], true);
            if ($treatment !== 'reduce' && ! $neutral) {
                $missing[] = 'Refund #'.$refund->id.' ('.$refund->classification.') has no confirmed financial treatment.';
            }
        }
        $missing[] = 'Client-borne delivery costs are not recorded in the ERP.';
        $missing[] = 'Client-borne operating expenses are not recorded in the ERP.';

        $commission = (int) $orders->where('status', 'successful')->sum('commission');

        return [
            'status' => 'incomplete', 'currency' => $currency, 'net_profit_minor' => null, 'missing_data' => array_values(array_unique($missing)),
            'recorded_components' => [
                'label' => 'Recorded components only. Not a profit figure: client-borne delivery and operating costs are missing.',
                'net_verified_sales_minor' => $net['net'], 'cost_of_units_sold_minor' => $cost, 'sellbees_commission_minor' => $commission,
                'realized' => true, 'estimates_included' => false,
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, included: int, total: int, truncated: bool, limit: int}
     */
    private static function bounded(Collection $items, int $limit): array
    {
        return ['items' => $items->take($limit)->values()->all(), 'included' => min($limit, $items->count()), 'total' => $items->count(), 'truncated' => $items->count() > $limit, 'limit' => $limit];
    }
}
