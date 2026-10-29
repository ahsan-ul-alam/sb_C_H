<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\RevenueAdjustment;
use Carbon\CarbonImmutable;

/**
 * Turns ClientHub payments plus verified revenue adjustments into recognised income for a month.
 *
 * - A payment is earned revenue unless Finance marks it as a customer ADVANCE (a liability).
 * - An advance becomes revenue only through a verified RECOGNITION dated in the month.
 * - A REFUND reduces revenue (or the advance liability) in the month it is made, never the original month.
 * Deleting a payment (a void) is only possible in an open month, so voided payments never reach this calculation.
 */
class RevenueRecognition
{
    /**
     * @return array{collected: int, advances: int, recognised: int, refund_revenue: int, refund_advance: int, income: int, pending: int}
     */
    public static function figures(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->endOfMonth();

        $collected = (int) Payment::query()
            ->whereHas('project', fn ($q) => $q->where('currency', config('finance.currency')))
            ->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $end)->sum('amount');

        $adjustments = fn (string $kind) => RevenueAdjustment::where('kind', $kind)->where('status', 'verified')
            ->whereDate('effective_on', '>=', $start)->whereDate('effective_on', '<=', $end);

        // An advance marking counts in the month the payment was received.
        $advances = (int) RevenueAdjustment::where('kind', 'advance')->where('status', 'verified')
            ->whereHas('payment', fn ($q) => $q->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $end))->sum('amount');
        $recognised = (int) $adjustments('recognition')->sum('amount');
        $refundRevenue = (int) $adjustments('refund')->where('against', 'revenue')->sum('amount');
        $refundAdvance = (int) $adjustments('refund')->where('against', 'advance')->sum('amount');

        $pending = RevenueAdjustment::where('status', 'pending')->where(function ($q) use ($start, $end) {
            $q->whereDate('effective_on', '>=', $start)->whereDate('effective_on', '<=', $end)
                ->orWhereHas('payment', fn ($p) => $p->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $end));
        })->count();

        return [
            'collected' => $collected, 'advances' => $advances, 'recognised' => $recognised,
            'refund_revenue' => $refundRevenue, 'refund_advance' => $refundAdvance,
            'income' => $collected - $advances + $recognised - $refundRevenue, 'pending' => $pending,
        ];
    }

    /**
     * Signed journal lines (positive = debit) that book the month's cash movements and advance liability.
     *
     * @param  array<string, int>  $f  result of figures()
     * @return list<array{0: string, 1: int|null, 2: int, 3: int}>
     */
    public static function journalLines(array $f): array
    {
        $signed = [
            'cash' => $f['collected'] - $f['refund_revenue'] - $f['refund_advance'],
            'income' => -($f['collected'] - $f['advances']) - $f['recognised'] + $f['refund_revenue'],
            'customer.advances' => -$f['advances'] + $f['recognised'] + $f['refund_advance'],
        ];

        $lines = [];
        foreach ($signed as $account => $amount) {
            if ($amount !== 0) {
                $lines[] = [$account, null, max($amount, 0), max(-$amount, 0)];
            }
        }

        return $lines;
    }
}
