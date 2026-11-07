<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\ClientProfitDeclaration;
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
        $default = (string) config('finance.currency');
        $others = collect(ClientProfitability::currencies($client))->reject(fn ($c) => $c === $default)->values();

        return self::salesIn($client, $default) + ['other_currencies' => $others->map(fn ($c) => self::salesIn($client, $c))->all(),
            'note' => 'Each currency is reported separately in its own units; currencies are never added together and no exchange rate is assumed.'];
    }

    /** @return array<string, mixed> */
    private static function salesIn(Client $client, string $currency): array
    {
        $orders = self::orders($client);
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
     * Client profit for the client to date, plus each project and each Finance-declared period, all from ClientProfitability (one calculation engine).
     * "complete" only with a current Finance declaration; otherwise "incomplete" with the exact reasons and a null net profit.
     *
     * @return array<string, mixed>
     */
    private static function profitability(Client $client, Collection $projects): array
    {
        $compact = fn (array $r) => ['project_id' => $r['project_id'], 'period' => $r['period'], 'status' => $r['status'], 'net_profit_minor' => $r['net_profit_minor'], 'missing_data' => $r['missing_data'], 'figures' => $r['figures']];
        $all = ClientProfitability::calculate($client);
        $periods = ClientProfitDeclaration::where('client_id', $client->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNotNull('project_id')->orWhereNotNull('period_from')->orWhereNotNull('period_to'))
            ->orderByDesc('id')->limit(12)->get();

        return [
            'status' => $all['status'], 'currency' => $all['currency'], 'net_profit_minor' => $all['net_profit_minor'], 'missing_data' => $all['missing_data'],
            'client_net_sales_minor' => $all['figures']['net_sales_minor'], 'sold_product_cost_minor' => $all['figures']['sold_product_cost_minor'],
            'client_borne_expenses_minor' => $all['figures']['client_borne_expenses_minor'], 'customer_compensation_minor' => $all['figures']['customer_compensation_minor'],
            'sellbees_commission_and_fees_minor' => $all['figures']['sellbees_commission_minor'] + $all['figures']['sellbees_service_fees_minor'],
            'unsold_inventory_cost_minor' => $all['unsold_inventory']['cost_minor'], 'unsold_lines_without_verified_cost' => $all['unsold_inventory']['lines_without_verified_cost'],
            'figures_basis' => $all['figures']['basis'], 'declaration' => $all['declaration'], 'products' => self::bounded(collect($all['products']), self::PRODUCT_LIMIT),
            'by_project' => self::bounded($projects->map(fn ($p) => $compact(ClientProfitability::calculate($client, $p->id))), self::LIST_LIMIT),
            'declared_periods' => $periods->map(fn ($d) => $compact(ClientProfitability::calculate($client, $d->project_id, $d->period_from?->toDateString(), $d->period_to?->toDateString(), $d->currency)))->all(),
            // Other currencies are calculated on their own, in their own units. Converted profitability needs an approved rate source and policy.
            'other_currencies' => collect(ClientProfitability::currencies($client))->reject(fn ($c) => $c === $all['currency'])->map(fn ($c) => $compact(ClientProfitability::calculate($client, null, null, null, $c)))->values()->all(),
            'converted_profitability' => ['status' => 'unavailable', 'reason' => 'No approved exchange-rate source and conversion policy exist; currencies are not added together.'],
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
