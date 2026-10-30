<?php

namespace App\Services;

use App\Models\EmergencyFundRequest;
use App\Models\SourcingLot;
use App\Support\Costing;
use App\Support\Money;

/**
 * Single source of truth for every sourcing number. Nothing here is stored: it is recomputed from lines, costs,
 * allocations, payments and fund requests, so the figures can never drift or be counted twice.
 *
 * Recognition used by Main Finance (which is cash-basis for income): client money is income when ClientHub receives it,
 * and sourcing outflows become Expense records when PAID. These figures are the lot's accrual view (cost incurred,
 * sales agreed) and are reported next to — never added to — those cash postings.
 */
class LotFinancials
{
    /**
     * @return array<string, mixed>
     */
    public static function for(SourcingLot $lot): array
    {
        $lot->load(['lines.supplier', 'costs.expense', 'allocations.project.client', 'allocations.invoice', 'payments.expense', 'payments.corrections', 'fundRequests']);

        $replaced = $lot->costs->pluck('replaces_id')->filter()->all();
        $current = $lot->costs->reject(fn ($c) => in_array($c->id, $replaced, true));
        $sharedTotal = (int) $current->whereNull('sourcing_lot_line_id')->sum('amount');
        $estimateOriginal = (int) $lot->costs->where('kind', 'estimate')->sum('amount');

        $purchases = $lot->lines->mapWithKeys(fn ($l) => [$l->id => $l->purchaseCost()])->all();
        $weights = $lot->allocation_basis === 'quantity'
            ? $lot->lines->mapWithKeys(fn ($l) => [$l->id => (int) bcmul((string) $l->quantity, '100', 0)])->all()
            : $purchases;
        $shared = Costing::allocateShared($sharedTotal, $weights);

        $lines = [];
        foreach ($lot->lines as $line) {
            $purchase = $purchases[$line->id];
            $direct = (int) $current->where('sourcing_lot_line_id', $line->id)->sum('amount');
            $landed = $purchase + $direct + ($shared[$line->id] ?? 0);
            $usable = (string) $line->usable_quantity;
            $price = $line->clientPrice();

            $sales = $lot->allocations->where('kind', 'sale')->where('sourcing_lot_line_id', $line->id);
            $allocatedQty = '0.00';
            $allocatedValue = 0;
            foreach ($sales as $a) {
                $allocatedQty = bcadd($allocatedQty, (string) $a->quantity, 2);
                $allocatedValue += $a->amount();
            }
            $unallocatedQty = bccomp($usable, $allocatedQty, 2) > 0 ? bcsub($usable, $allocatedQty, 2) : '0.00';
            $unallocatedValue = $price !== null ? Money::multiply($unallocatedQty, $price) : 0;
            $damagedQty = bcsub((string) $line->quantity, $usable, 2);
            $revenue = $allocatedValue + $unallocatedValue;

            $lines[$line->id] = [
                'line' => $line,
                'purchase' => $purchase,
                'direct' => $direct,
                'shared' => $shared[$line->id] ?? 0,
                'landed' => $landed,
                'unit_cost' => Costing::unitCost($landed, $usable),
                'additional_per_unit' => Costing::unitCost($landed - $purchase, $usable),
                'break_even' => Costing::breakEven($landed, $usable),
                'damaged_qty' => $damagedQty,
                'damage_loss' => bccomp((string) $line->quantity, '0', 2) > 0 ? (int) bcdiv(bcmul((string) $landed, $damagedQty, 2), (string) $line->quantity, 0) : 0,
                'price' => $price,
                'allocated_qty' => $allocatedQty,
                'unallocated_qty' => $unallocatedQty,
                'revenue' => $revenue,
                'profit' => $revenue - $landed,
                'unit_profit' => $price !== null ? bcsub((string) $price, Costing::unitCost($landed, $usable), 4) : null,
            ];
        }

        $purchase = array_sum($purchases);
        $additional = (int) $current->sum('amount');
        $landed = $purchase + $additional;
        $salesValue = (int) array_sum(array_column($lines, 'revenue'));
        $recoveries = (int) $lot->allocations->where('kind', 'recovery')->sum(fn ($a) => $a->amount());
        $revenue = $salesValue + $recoveries;
        $profit = $revenue - $landed;
        $unexpected = (int) $current->where('is_unexpected', true)->sum('amount');

        // Emergency analysis: margin first absorbs unexpected cost (net of agreed client recovery); only the rest is a loss.
        $originalMargin = max(0, $salesValue - ($landed - $unexpected));
        $unexpectedNet = max(0, $unexpected - $recoveries);
        $absorbed = min($originalMargin, $unexpectedNet);
        $uncoveredLoss = max(0, -$profit);
        $fund = fn (string $status) => (int) $lot->fundRequests->where('status', $status)->sum('amount');
        $fundLive = (int) $lot->fundRequests->whereIn('status', EmergencyFundRequest::LIVE)->sum('amount');

        $paid = (int) $lot->payments->sum(fn ($p) => $p->effectiveAmount());
        $supplierOutstanding = max(0, $purchase - $paid);
        $costsUnpaid = (int) $current->where('kind', 'actual')->reject(fn ($c) => $c->isPaid())->sum('amount');

        $billedByInvoice = $lot->allocations->whereNotNull('billed_invoice_id')->groupBy('billed_invoice_id');
        $billed = 0;
        $received = 0;
        foreach ($billedByInvoice as $allocations) {
            $part = (int) $allocations->sum(fn ($a) => $a->amount());
            $invoice = $allocations->first()->invoice;
            $billed += $part;
            // Pro-rata share of what the invoice has collected (floor) — an invoice can mix several charges.
            $received += $invoice && $invoice->total > 0 ? min($part, intdiv($invoice->amount_paid * $part, $invoice->total)) : 0;
        }

        return [
            'lines' => $lines,
            'purchase' => $purchase,
            'additional' => $additional,
            'shared_total' => $sharedTotal,
            'landed' => $landed,
            'estimate_landed' => $purchase + $estimateOriginal,
            'estimate_additional' => $estimateOriginal,
            'estimates_open' => (int) $current->where('kind', 'estimate')->sum('amount'),
            'sales_value' => $salesValue,
            'recoveries' => $recoveries,
            'revenue' => $revenue,
            'profit' => $profit,
            'margin_on_sales' => Costing::percent($profit, $revenue),
            'markup_on_cost' => Costing::percent($profit, $landed),
            'approved_profit' => $lot->approved_revenue !== null ? $lot->approved_revenue - $lot->approved_cost : null,
            'cost_variance' => $lot->approved_cost !== null ? $landed - $lot->approved_cost : null,
            'unexpected' => $unexpected,
            'original_margin' => $originalMargin,
            'margin_absorbed' => $absorbed,
            'uncovered_loss' => $uncoveredLoss,
            'fund_pending' => $fund('pending'),
            'fund_approved' => $fund('approved'),
            'fund_utilised' => $fund('utilised'),
            'fund_live' => $fundLive,
            'fund_suggested' => max(0, $uncoveredLoss - $fundLive),
            'fund_excess' => max(0, $fundLive - $uncoveredLoss),
            'pending_loss_after_fund' => max(0, $uncoveredLoss - $fund('utilised')),
            'supplier_paid' => $paid,
            'supplier_outstanding' => $supplierOutstanding,
            'costs_unpaid' => $costsUnpaid,
            'cash_required' => $supplierOutstanding + $costsUnpaid,
            'billed' => $billed,
            'unbilled' => max(0, $revenue - $billed),
            'received' => $received,
            'receivable' => $billed - $received,
        ];
    }
}
