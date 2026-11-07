<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientExpense;
use App\Models\ClientExpenseAllocation;
use App\Models\ClientOrder;
use App\Models\ClientProfitDeclaration;
use App\Models\OrderRefund;
use App\Models\SourcingAllocation;
use App\Models\SourcingLotLine;
use App\Models\StorageCharge;
use Illuminate\Support\Collection;

/**
 * CLIENT profit (not Sellbees profit), read-only, from existing ERP records:
 *
 *   net verified delivered sales          delivered less returned units at the discounted price, less paid refunds that reduce the sale
 *   - sold-unit acquisition cost          units sold x the client's verified acquisition cost per unit of that exact lot line
 *   - customer compensation               paid goodwill refunds (a cost to the client; refunds of returned goods are not deducted twice)
 *   - client-borne operating expenses     approved client expenses allocated to the scope and period
 *   - Sellbees commission and service fees on those sales (commission on the orders, storage charges billed for the period)
 *   = client net profit or loss
 *
 * The acquisition cost is the amount the client was billed for that lot line (allocation to the client's project with an invoice), and only when the lot is
 * client-owned and its price was approved and matches the billed price. It is all-in (product, inbound freight and Sellbees pricing), so freight is never
 * added again. Unsold units keep their cost as an asset and are never expensed. A figure is called "complete" only when nothing is unresolved AND Finance has
 * declared the scope and period complete with the data unchanged since; otherwise "incomplete" with every reason, and no profit figure is given.
 */
class ClientProfitability
{
    /**
     * @return array<string, mixed>
     */
    public static function calculate(Client $client, ?int $projectId = null, ?string $from = null, ?string $to = null): array
    {
        $currency = (string) config('finance.currency');
        $projects = $client->projects()->when($projectId, fn ($q) => $q->whereKey($projectId))->get();
        $allProjectIds = $client->projects()->pluck('id');
        $scopeIds = $projects->pluck('id');
        $missing = [];
        if ($projectId !== null && $projects->isEmpty()) {
            $missing[] = 'The project does not belong to this client.';
        }
        if ($projects->contains(fn ($p) => $p->currency !== $currency)) {
            $missing[] = 'Projects in a currency other than '.$currency.' are not included.';
        }
        $scopeIds = $projects->where('currency', $currency)->pluck('id');

        $orders = ClientOrder::where('fulfilment', 'stock')->whereIn('project_id', $scopeIds)->where('delivery_status', '!=', 'cancelled')
            ->when($from, fn ($q, $v) => $q->whereDate('ordered_on', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('ordered_on', '<=', $v))
            ->with(['items.line.lot', 'items.line.product', 'project'])->orderBy('id')->get();
        $inFlight = $orders->filter(fn ($o) => $o->delivery_status !== 'delivered' && $o->delivery_status !== 'failed');
        foreach ($inFlight as $order) {
            $missing[] = 'Order '.$order->reference.' is '.($order->delivery_status ?? 'not delivered').': its sale is not final yet.';
        }
        $delivered = $orders->where('delivery_status', 'delivered')->values();
        $refunds = OrderRefund::where('status', 'paid')->whereIn('client_order_id', $delivered->pluck('id'))->get();

        // Acquisition cost per exact lot line, with the reason when it cannot be verified.
        $acquisition = [];
        $unitCost = function (SourcingLotLine $line) use (&$acquisition, $allProjectIds): array {
            return $acquisition[$line->id] ??= self::acquisition($line, $allProjectIds);
        };

        $products = [];
        $sales = 0;
        $cost = 0;
        $fingerprint = ['orders' => [], 'refunds' => [], 'expenses' => [], 'allocations' => []];
        foreach ($delivered as $order) {
            $itemFingerprint = [];
            if ($order->status !== 'successful') {
                $missing[] = 'Order '.$order->reference.' is delivered but its commission has not been confirmed (status '.$order->status.').';
            }
            foreach ($order->items as $item) {
                $netUnits = bcsub((string) $item->delivered_quantity, (string) $item->returned_quantity, 2);
                $revenue = $item->deliveredAmount();
                if ((int) $item->unit_price <= 0) {
                    $missing[] = 'Order '.$order->reference.': "'.$item->description.'" has no verified selling price.';
                }
                $line = $item->line;
                $itemCost = null;
                if ($line === null) {
                    $missing[] = 'Order '.$order->reference.': "'.$item->description.'" is not linked to a stock lot line, so its cost cannot be allocated.';
                } else {
                    $a = $unitCost($line);
                    if (isset($a['reason'])) {
                        $missing[] = 'Order '.$order->reference.': '.$a['reason'];
                    } else {
                        $itemCost = (int) bcadd(bcdiv(bcmul($netUnits, (string) $a['amount'], 4), (string) $a['qty'], 4), '0.5', 0);
                        $cost += $itemCost;
                    }
                }
                $sales += $revenue;
                $key = $order->project_id.'|'.($line?->supplier_product_id ?? 'd'.$item->description).'|'.($line?->unit ?? 'pcs');
                $row = &$products[$key];
                $row ??= ['project_id' => $order->project_id, 'product_id' => $line?->supplier_product_id, 'sku' => $line?->product?->sku, 'name' => $item->description, 'unit' => $line?->unit ?? 'pcs',
                    'units_sold' => '0', 'net_sales_minor' => 0, 'sold_cost_minor' => 0, 'cost_verified' => true, 'allocated_expenses_minor' => 0];
                $row['units_sold'] = bcadd($row['units_sold'], $netUnits, 2);
                $row['net_sales_minor'] += $revenue;
                $row['sold_cost_minor'] += $itemCost ?? 0;
                $row['cost_verified'] = $row['cost_verified'] && $itemCost !== null;
                unset($row);
                $itemFingerprint[] = [$item->id, (string) $item->delivered_quantity, (string) $item->returned_quantity, (int) $item->unit_price, (int) $item->unit_discount, $line?->id];
            }
            $fingerprint['orders'][] = [$order->id, $order->status, (int) $order->net_value, (int) $order->commission, $itemFingerprint];
        }

        // Paid refunds: reducing ones lower the sale; goodwill is a cost; refunds of returned goods or delivery fees are already reflected; anything else is unresolved.
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

        // Client expenses in scope and period.
        $expenses = ClientExpense::where('client_id', $client->id)->where('responsible_party', 'client')->whereNotIn('status', ['reversed', 'rejected'])
            ->when($from, fn ($q, $v) => $q->whereDate('spent_on', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('spent_on', '<=', $v))->with('allocations')->orderBy('id')->get();
        $pending = $expenses->whereIn('status', ['entered', 'reviewed']);
        if ($pending->isNotEmpty()) {
            $missing[] = $pending->count().' client expense(s) are still awaiting review or approval.';
        }
        $approved = $expenses->where('status', 'approved');
        $expenseTotal = 0;
        $byCategory = [];
        foreach ($approved as $expense) {
            $rows = $expense->allocations->filter(fn (ClientExpenseAllocation $a) => $scopeIds->contains($a->project_id));
            foreach ($rows as $a) {
                $expenseTotal += $a->amount;
                $byCategory[$expense->category] = ($byCategory[$expense->category] ?? 0) + $a->amount;
                if ($a->supplier_product_id) {
                    foreach ($products as &$p) {
                        if ($p['project_id'] === $a->project_id && $p['product_id'] === $a->supplier_product_id) {
                            $p['allocated_expenses_minor'] += $a->amount;
                            break;
                        }
                    }
                    unset($p);
                }
            }
            $fingerprint['expenses'][] = [$expense->id, $expense->category, $rows->map(fn ($a) => [$a->project_id, $a->client_order_id, $a->supplier_product_id, $a->amount])->all()];
        }

        $commission = (int) $delivered->where('status', 'successful')->sum('commission');
        $storage = (int) StorageCharge::where('client_id', $client->id)->where('status', 'billed')->whereNotNull('invoice_id')
            ->when($projectId, fn ($q) => $q->whereHas('lot', fn ($l) => $l->where('project_id', $projectId)))
            ->when($from, fn ($q, $v) => $q->where('period', '>=', substr($v, 0, 7)))->when($to, fn ($q, $v) => $q->where('period', '<=', substr($v, 0, 7)))->sum('amount');
        $fingerprint['storage'] = $storage;
        $fees = $commission + $storage;

        [$unsoldCost, $unsoldUnverified] = self::unsold($client, $projectId, $unitCost);
        $fingerprint['unsold'] = [$unsoldCost, $unsoldUnverified];

        $net = $sales - $reduction;
        $profit = $net - $cost - $compensation - $expenseTotal - $fees;
        $print = hash('sha256', json_encode($fingerprint));
        $hasSales = $delivered->isNotEmpty() && $sales > 0;

        $declaration = self::declarationFor($client, $projectId, $from, $to);
        $declarationValid = $declaration !== null && $declaration->fingerprint === $print;

        if ($missing === [] && ! $hasSales) {
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
                'expenses_by_category_minor' => $byCategory, 'sellbees_commission_minor' => $commission, 'sellbees_service_fees_minor' => $storage,
                'realized' => true, 'estimates_included' => false,
            ],
            'unsold_inventory' => ['cost_minor' => $unsoldCost, 'lines_without_verified_cost' => $unsoldUnverified, 'note' => 'An asset at the client\'s acquisition cost; not an expense.'],
            'products' => array_values($products),
            'declaration' => ['status' => $declaration === null ? 'none' : ($declarationValid ? 'valid' : 'out_of_date'), 'declared_at' => $declaration?->declared_at?->toDateString()],
            'fingerprint' => $print,
            'zero_categories_needed' => array_values(array_diff(array_keys(ClientExpense::CATEGORIES), array_keys($byCategory))),
        ];
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
            return ['reason' => 'the lot price billed to the client for '.$name.' is not approved.'];
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
     * @return array{0: int, 1: int}
     */
    private static function unsold(Client $client, ?int $projectId, \Closure $unitCost): array
    {
        $cost = 0;
        $unverified = 0;
        foreach (ProjectInventory::positions(['client' => $client->id, 'project' => $projectId]) as $row) {
            if (! $row['own_slice'] || $row['ownership'] !== 'client') {
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

    public static function declarationFor(Client $client, ?int $projectId, ?string $from, ?string $to): ?ClientProfitDeclaration
    {
        return ClientProfitDeclaration::where('client_id', $client->id)->whereNull('revoked_at')
            ->when($projectId, fn ($q, $v) => $q->where('project_id', $v), fn ($q) => $q->whereNull('project_id'))
            ->when($from, fn ($q, $v) => $q->whereDate('period_from', $v), fn ($q) => $q->whereNull('period_from'))
            ->when($to, fn ($q, $v) => $q->whereDate('period_to', $v), fn ($q) => $q->whereNull('period_to'))
            ->orderByDesc('id')->first();
    }
}
