<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientExpense;
use App\Models\ClientExpenseAllocation;
use App\Models\ClientOrder;
use App\Models\ClientOrderItem;
use App\Models\ClientProfitDeclaration;
use App\Models\OrderRefund;
use App\Models\SourcingAllocation;
use App\Models\SourcingLotLine;
use App\Models\StockMovement;
use App\Models\StorageCharge;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * CLIENT profit (not Sellbees profit), read-only, from existing ERP records, for one currency at a time (currencies are never added together and no
 * exchange rate is invented; a converted figure is unavailable until an approved rate source and policy exist).
 *
 *   net verified sales                    units that actually LEFT Sellbees' control on the delivery date (the stock ledger's append-only 'deliver' movements), less units
 *                                         returned on their return date, at the discounted price, less paid refunds (by payment date) that reduce the sale
 *   - sold-unit acquisition cost          units sold in the period x the client's approved ALL-IN price for that exact lot line (product, inbound freight, handling and
 *                                         Sellbees margin included; Sellbees' own inventory cost is a different thing and is not used)
 *   - customer compensation               paid goodwill refunds
 *   - additional client-borne expenses    approved client expenses charged separately (by expense date); fees already INCLUDED in the acquisition price are listed, never deducted again
 *   - Sellbees commission and service fees  the order's commission earned on the period's delivered value, plus storage charges billed for the period
 *   = client net profit or loss
 *
 * An order is not dated by when it was created. A delivered item with no delivery movement (historical data) has no evidenced date: it is counted only in the
 * undated "to date" view, and any dated period reports the attribution as incomplete. "complete" needs nothing unresolved AND a current Finance declaration
 * whose fingerprint covers only that scope, currency and period (later orders in other periods do not disturb it).
 */
class ClientProfitability
{
    /** @return list<string> the currencies of the client's projects, the reporting currency first */
    public static function currencies(Client $client): array
    {
        $default = (string) config('finance.currency');
        $found = $client->projects()->distinct()->pluck('currency')->all();

        return array_values(array_unique([$default, ...$found]));
    }

    /**
     * @return array<string, mixed>
     */
    public static function calculate(Client $client, ?int $projectId = null, ?string $from = null, ?string $to = null, ?string $currency = null): array
    {
        $currency ??= (string) config('finance.currency');
        $all = $client->projects()->get();
        $scope = $all->when($projectId, fn ($c) => $c->where('id', $projectId))->where('currency', $currency)->values();
        $scopeIds = $scope->pluck('id');
        $allProjectIds = $all->pluck('id');
        $missing = [];
        if ($projectId !== null && $scope->isEmpty()) {
            $missing[] = 'The project does not belong to this client in '.$currency.'.';
        }
        $limited = $from !== null || $to !== null;
        $inPeriod = fn (?string $date) => $date === null ? ! $limited : (($from === null || $date >= $from) && ($to === null || $date <= $to));

        $orders = ClientOrder::where('fulfilment', 'stock')->whereIn('project_id', $scopeIds)->where('delivery_status', '!=', 'cancelled')
            ->with(['items.line.lot', 'items.line.product'])->orderBy('id')->get();
        $movements = StockMovement::whereIn('client_order_item_id', $orders->flatMap(fn ($o) => $o->items->pluck('id')))->whereIn('type', ['deliver', 'return'])
            ->get(['client_order_item_id', 'type', 'occurred_on', 'd_delivered'])->groupBy('client_order_item_id');

        $acquisition = [];
        $unitCost = fn (SourcingLotLine $line): array => $acquisition[$line->id] ??= self::acquisition($line, $allProjectIds);

        $products = [];
        $sales = 0;
        $cost = 0;
        $commission = 0;
        $fingerprint = ['currency' => $currency, 'events' => [], 'orders' => [], 'refunds' => [], 'expenses' => []];
        foreach ($orders as $order) {
            $events = [];   // [date|null, signed quantity, item]
            foreach ($order->items as $item) {
                $rows = $movements->get($item->id, collect());
                $deliveredBy = $rows->where('type', 'deliver')->groupBy(fn ($m) => substr((string) $m->occurred_on, 0, 10))->map(fn ($g) => $g->reduce(fn ($c, $m) => bcadd($c, (string) $m->d_delivered, 2), '0'));
                $returnedBy = $rows->where('type', 'return')->groupBy(fn ($m) => substr((string) $m->occurred_on, 0, 10))->map(fn ($g) => $g->reduce(fn ($c, $m) => bcsub($c, (string) $m->d_delivered, 2), '0'));
                foreach ($deliveredBy as $date => $qty) {
                    $events[] = [$date, $qty, $item];
                }
                foreach ($returnedBy as $date => $qty) {
                    $events[] = [$date, bcmul($qty, '-1', 2), $item];
                }
                $undatedDelivered = bcsub((string) $item->delivered_quantity, (string) $deliveredBy->reduce(fn ($c, $q) => bcadd($c, $q, 2), '0'), 2);
                $undatedReturned = bcsub((string) $item->returned_quantity, (string) $returnedBy->reduce(fn ($c, $q) => bcadd($c, $q, 2), '0'), 2);
                if (bccomp($undatedDelivered, '0', 2) > 0) {
                    $events[] = [null, $undatedDelivered, $item];
                }
                if (bccomp($undatedReturned, '0', 2) > 0) {
                    $events[] = [null, bcmul($undatedReturned, '-1', 2), $item];
                }
            }
            if ($events === []) {
                continue;
            }
            $hasUndated = collect($events)->contains(fn ($e) => $e[0] === null);
            if ($hasUndated && $limited) {
                $missing[] = 'Order '.$order->reference.': the delivery date is not evidenced in the stock ledger, so its sale cannot be attributed to a period.';
            }
            $value = fn (array $e) => Money::multiply(ltrim($e[1], '-'), $e[2]->netUnitPrice()) * (bccomp($e[1], '0', 2) < 0 ? -1 : 1);
            $relevant = false;
            foreach ($events as $e) {
                if (! $inPeriod($e[0])) {
                    continue;
                }
                $relevant = true;
                [$date, $qty, $item] = $e;
                $line = $item->line;
                $revenue = $value($e);
                $eventCost = null;
                if ((int) $item->unit_price <= 0) {
                    $missing[] = 'Order '.$order->reference.': "'.$item->description.'" has no verified selling price.';
                }
                if ($line === null) {
                    $missing[] = 'Order '.$order->reference.': "'.$item->description.'" is not linked to a stock lot line, so its cost cannot be allocated.';
                } else {
                    $a = $unitCost($line);
                    if (isset($a['reason'])) {
                        $missing[] = 'Order '.$order->reference.': '.$a['reason'];
                    } else {
                        $abs = (int) bcadd(bcdiv(bcmul(ltrim($qty, '-'), (string) $a['amount'], 4), (string) $a['qty'], 4), '0.5', 0);
                        $eventCost = bccomp($qty, '0', 2) < 0 ? -$abs : $abs;
                        $cost += $eventCost;
                    }
                }
                $sales += $revenue;
                $key = $order->project_id.'|'.($line?->supplier_product_id ?? 'd'.$item->description).'|'.($line?->unit ?? 'pcs');
                $row = &$products[$key];
                $row ??= ['project_id' => $order->project_id, 'product_id' => $line?->supplier_product_id, 'sku' => $line?->product?->sku, 'name' => $item->description, 'unit' => $line?->unit ?? 'pcs',
                    'units_sold' => '0', 'net_sales_minor' => 0, 'sold_cost_minor' => 0, 'cost_verified' => true, 'allocated_expenses_minor' => 0];
                $row['units_sold'] = bcadd($row['units_sold'], $qty, 2);
                $row['net_sales_minor'] += $revenue;
                $row['sold_cost_minor'] += $eventCost ?? 0;
                $row['cost_verified'] = $row['cost_verified'] && $eventCost !== null;
                unset($row);
                $fingerprint['events'][] = [$item->id, $date, $qty, (int) $item->unit_price, (int) $item->unit_discount, $line?->id, $a['amount'] ?? null, $a['qty'] ?? null];
            }
            if (! $relevant) {
                continue;
            }
            if ($order->status !== 'successful') {
                $missing[] = 'Order '.$order->reference.' has delivered units but its commission has not been confirmed (status '.$order->status.').';
            } else {
                $commission += self::commissionShare($order, $events, $value, $from, $to);
            }
            $fingerprint['orders'][] = [$order->id, $order->status, (int) $order->commission];
        }

        // Paid refunds, dated by payment: reducing ones lower the sale; goodwill is a cost; refunds of returned goods or delivery fees are already reflected.
        $refunds = OrderRefund::where('status', 'paid')->whereIn('client_order_id', $orders->pluck('id'))
            ->when($from, fn ($q, $v) => $q->whereDate('paid_on', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('paid_on', '<=', $v))->get();
        $reduction = 0;
        $compensation = 0;
        foreach ($refunds as $refund) {
            $fingerprint['refunds'][] = [$refund->id, (int) $refund->amount, $refund->commission_treatment, $refund->classification];
            if ($refund->commission_treatment === 'reduce') {
                $reduction += (int) $refund->amount;
            } elseif ($refund->commission_treatment === 'keep' && $refund->classification === 'goodwill') {
                $compensation += (int) $refund->amount;
            } elseif (! ($refund->commission_treatment === 'keep' && in_array($refund->classification, ['return_of_goods', 'delivery_fee'], true))) {
                $missing[] = 'Refund #'.$refund->id.' ('.$refund->classification.') has no confirmed financial treatment.';
            }
        }

        // Client expenses by expense date. Paid and unpaid both count (an accrued cost is a cost); only charges made separately are deducted.
        $expenses = ClientExpense::where('client_id', $client->id)->where('responsible_party', 'client')->whereNotIn('status', ['reversed', 'rejected'])
            ->when($from, fn ($q, $v) => $q->whereDate('spent_on', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('spent_on', '<=', $v))->with('allocations')->orderBy('id')->get()
            ->filter(fn ($e) => $e->allocations->contains(fn ($a) => $scopeIds->contains($a->project_id)))->values();
        $pending = $expenses->whereIn('status', ['entered', 'reviewed'])->where('charge_treatment', 'additional');
        if ($pending->isNotEmpty()) {
            $missing[] = $pending->count().' client expense(s) are still awaiting review or approval.';
        }
        $expenseTotal = 0;
        $unpaid = 0;
        $included = 0;
        $byCategory = [];
        foreach ($expenses->where('status', 'approved') as $expense) {
            $rows = $expense->allocations->filter(fn (ClientExpenseAllocation $a) => $scopeIds->contains($a->project_id));
            $amount = (int) $rows->sum('amount');
            if ($expense->charge_treatment === 'included_in_acquisition') {
                $included += $amount;   // already inside the billed acquisition cost: shown, never deducted again

                continue;
            }
            $expenseTotal += $amount;
            $unpaid += $expense->paid_status === 'unpaid' ? $amount : 0;
            $byCategory[$expense->category] = ($byCategory[$expense->category] ?? 0) + $amount;
            foreach ($rows->whereNotNull('supplier_product_id') as $a) {
                foreach ($products as &$p) {
                    if ($p['project_id'] === $a->project_id && $p['product_id'] === $a->supplier_product_id) {
                        $p['allocated_expenses_minor'] += $a->amount;
                        break;
                    }
                }
                unset($p);
            }
        }
        foreach ($expenses as $expense) {
            $fingerprint['expenses'][] = [$expense->id, $expense->status, $expense->charge_treatment, $expense->paid_status, $expense->category, $expense->allocations->map(fn ($a) => [$a->project_id, $a->client_order_id, $a->supplier_product_id, $a->amount])->all()];
        }

        $storage = (int) StorageCharge::where('client_id', $client->id)->where('status', 'billed')->whereNotNull('invoice_id')->whereHas('lot', fn ($l) => $l->whereIn('project_id', $scopeIds))
            ->when($from, fn ($q, $v) => $q->where('period', '>=', substr($v, 0, 7)))->when($to, fn ($q, $v) => $q->where('period', '<=', substr($v, 0, 7)))->sum('amount');
        $fingerprint['storage'] = $storage;
        $fees = $commission + $storage;

        [$unsoldCost, $unsoldUnverified] = self::unsold($client, $scopeIds, $unitCost, $currency === (string) config('finance.currency'));

        $net = $sales - $reduction;
        $profit = $net - $cost - $compensation - $expenseTotal - $fees;
        $print = hash('sha256', json_encode($fingerprint));

        $declaration = self::declarationFor($client, $projectId, $from, $to, $currency);
        $declarationValid = $declaration !== null && $declaration->fingerprint === $print;

        if ($missing === [] && $products === [] && $reduction === 0 && $compensation === 0) {
            $status = 'no_sales';
        } else {
            if ($missing === [] && ! $declarationValid) {
                $missing[] = $declaration === null
                    ? 'Finance has not declared that all client-borne expenses are recorded for this period.'
                    : 'The Finance declaration is out of date: transactions changed after it was made.';
            }
            $status = $missing === [] ? 'complete' : 'incomplete';
        }

        return [
            'status' => $status, 'currency' => $currency, 'period' => ['from' => $from, 'to' => $to], 'project_id' => $projectId,
            'net_profit_minor' => $status === 'complete' ? $profit : null,
            'missing_data' => array_values(array_unique($missing)),
            'figures' => [
                'basis' => $status === 'complete' ? 'verified' : 'recorded components only; not a profit figure',
                'net_sales_minor' => $net, 'delivered_sales_minor' => $sales, 'sales_reduced_by_refunds_minor' => $reduction,
                'sold_product_cost_minor' => $cost, 'customer_compensation_minor' => $compensation, 'client_borne_expenses_minor' => $expenseTotal,
                'unpaid_client_expenses_minor' => $unpaid, 'fees_included_in_acquisition_minor' => $included,
                'expenses_by_category_minor' => $byCategory, 'sellbees_commission_minor' => $commission, 'sellbees_service_fees_minor' => $storage,
                'realized' => true, 'estimates_included' => false, 'sale_date_basis' => 'actual delivery and return dates from the stock ledger',
            ],
            'unsold_inventory' => ['cost_minor' => $unsoldCost, 'lines_without_verified_cost' => $unsoldUnverified, 'note' => 'An asset at the client\'s acquisition cost; not an expense.'],
            'products' => array_values($products),
            'declaration' => ['status' => $declaration === null ? 'none' : ($declarationValid ? 'valid' : 'out_of_date'), 'declared_at' => $declaration?->declared_at?->toDateString()],
            'fingerprint' => $print,
            'zero_categories_needed' => array_values(array_diff(array_keys(ClientExpense::CATEGORIES), array_keys($byCategory))),
        ];
    }

    /**
     * The part of an order's commission earned in the period: the commission is spread over the order's delivered value by date, so the shares of
     * successive periods add up to exactly the order's commission.
     *
     * @param  list<array{0: ?string, 1: string, 2: ClientOrderItem}>  $events
     */
    private static function commissionShare(ClientOrder $order, array $events, \Closure $value, ?string $from, ?string $to): int
    {
        $total = (int) array_sum(array_map($value, $events));
        if ($total <= 0 || (int) $order->commission <= 0) {
            return 0;
        }
        if ($from === null && $to === null) {
            return (int) $order->commission;
        }
        $through = fn (?string $limit, bool $inclusive) => (int) array_sum(array_map($value, array_filter($events, fn ($e) => $e[0] !== null && ($limit === null || ($inclusive ? $e[0] <= $limit : $e[0] < $limit)))));
        $share = fn (int $cum) => (int) bcadd(bcdiv(bcmul((string) $order->commission, (string) $cum, 4), (string) $total, 4), '0.5', 0);

        return $share($through($to, true)) - ($from === null ? 0 : $share($through($from, false)));
    }

    /**
     * @param  Collection<int, int>  $clientProjectIds
     * @return array{amount: int, qty: string}|array{reason: string}
     */
    private static function acquisition(SourcingLotLine $line, Collection $clientProjectIds): array
    {
        $name = '"'.$line->description.'"';
        if ($line->lot->ownership !== 'client') {
            return ['reason' => $name.' came from a lot whose ownership is '.($line->lot->ownership ?? 'not verified').', so no client acquisition cost can be attributed.'];
        }
        if ($line->approved_price === null) {
            return ['reason' => 'the all-in price billed to the client for '.$name.' is not approved.'];
        }
        $allocations = SourcingAllocation::where('sourcing_lot_line_id', $line->id)->where('kind', 'sale')->whereIn('project_id', $clientProjectIds)->whereNotNull('billed_invoice_id')->get();
        if ($allocations->isEmpty()) {
            return ['reason' => $name.' was never billed to the client, so its acquisition cost is not verified.'];
        }
        if ($allocations->contains(fn ($a) => (int) $a->unit_price !== (int) $line->approved_price)) {
            return ['reason' => 'the billed price for '.$name.' differs from the approved price.'];
        }
        $qty = $allocations->reduce(fn ($c, $a) => bcadd($c, (string) $a->quantity, 2), '0');
        if (bccomp($qty, '0', 2) <= 0) {
            return ['reason' => 'the billed quantity for '.$name.' is zero.'];
        }

        return ['amount' => (int) $allocations->sum(fn ($a) => $a->amount()), 'qty' => $qty];
    }

    /**
     * Remaining units of client-owned stock at their acquisition cost.
     *
     * @param  Collection<int, int>  $scopeIds
     * @return array{0: int, 1: int}
     */
    private static function unsold(Client $client, Collection $scopeIds, \Closure $unitCost, bool $includeShared): array
    {
        $cost = 0;
        $unverified = 0;
        foreach (ProjectInventory::positions(['client' => $client->id]) as $row) {
            $projectId = $row['project']?->id;
            if (! $row['own_slice'] || $row['ownership'] !== 'client' || ($projectId === null ? ! $includeShared : ! $scopeIds->contains($projectId))) {
                continue;
            }
            $remaining = bcadd(bcadd($row['b']['available'], $row['b']['reserved'], 2), $row['b']['returned'], 2);
            if (bccomp($remaining, '0', 2) <= 0) {
                continue;
            }
            $a = $unitCost($row['line']);
            if (isset($a['reason'])) {
                $unverified++;

                continue;
            }
            $cost += (int) bcadd(bcdiv(bcmul($remaining, (string) $a['amount'], 4), (string) $a['qty'], 4), '0.5', 0);
        }

        return [$cost, $unverified];
    }

    public static function declarationFor(Client $client, ?int $projectId, ?string $from, ?string $to, ?string $currency = null): ?ClientProfitDeclaration
    {
        $currency ??= (string) config('finance.currency');
        $default = (string) config('finance.currency');

        return ClientProfitDeclaration::where('client_id', $client->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->where('currency', $currency)->when($currency === $default, fn ($q) => $q->orWhereNull('currency')))
            ->when($projectId, fn ($q, $v) => $q->where('project_id', $v), fn ($q) => $q->whereNull('project_id'))
            ->when($from, fn ($q, $v) => $q->whereDate('period_from', $v), fn ($q) => $q->whereNull('period_from'))
            ->when($to, fn ($q, $v) => $q->whereDate('period_to', $v), fn ($q) => $q->whereNull('period_to'))
            ->orderByDesc('id')->first();
    }
}
