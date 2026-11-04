<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\PrepaidRelease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A prepaid expense (insurance, annual subscription, advance rent) is an asset when it is paid and an expense as it is used:
 *   when paid:  Dr assets.prepaid / Cr cash account (or payable)
 *   each month: Dr expense / Cr assets.prepaid     (one PrepaidRelease row per expense per month — unique, so never twice)
 * The release is cumulative, so a missed month is caught up in the next open month and a closed month is never reopened.
 */
class PrepaidService
{
    /** Cumulative amount that should have been expensed after $k of $months months (the last month takes the rounding remainder). */
    public static function scheduled(int $amount, int $months, int $k): int
    {
        return $k >= $months ? $amount : intdiv($amount * max(0, $k), $months);
    }

    /**
     * Expense whatever has become due up to and including $month. Idempotent. Returns the number of releases made.
     */
    public static function releaseDue(string $month, ?int $userId): int
    {
        if (AccountingPeriod::where('month', $month)->exists()) {
            return 0;
        }
        $end = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->endOfMonth();
        $released = 0;

        // A prepaid expense with a reversal awaiting the Board is left alone: releasing part of it now would make the reversal (which returns the
        // whole amount out of the asset) take the prepaid asset below zero. If the reversal is rejected, the cumulative release catches up.
        $expenses = Expense::query()->where('classification', 'prepaid')->where('status', 'verified')->whereNull('reverses_id')
            ->where('payment_status', '!=', 'reversed')->whereNotNull('prepaid_months')->whereDate('spent_on', '<=', $end)
            ->whereNotIn('id', Expense::query()->where('status', 'pending')->whereNotNull('reverses_id')->select('reverses_id'))->get();

        foreach ($expenses as $expense) {
            $elapsed = CarbonImmutable::parse($expense->spent_on)->startOfMonth()->diffInMonths($end->startOfMonth()) + 1;
            $already = (int) PrepaidRelease::where('expense_id', $expense->id)->sum('amount');
            $due = self::scheduled((int) $expense->amount, (int) $expense->prepaid_months, (int) $elapsed) - $already;
            if ($due <= 0 || PrepaidRelease::where('expense_id', $expense->id)->where('month', $month)->exists()) {
                continue;
            }
            DB::transaction(function () use ($expense, $month, $due, $end, $userId) {
                $release = PrepaidRelease::create(['expense_id' => $expense->id, 'month' => $month, 'amount' => $due, 'role' => $expense->project_id ? 'direct' : 'operating']);
                Ledger::post($end->toDateString(), 'Prepaid release: '.$expense->description, 'prepaid_release', $release->id, [
                    ['expense', null, $due, 0],
                    ['assets.prepaid', null, 0, $due],
                ], $userId);
            });
            $released++;
        }

        return $released;
    }

    /**
     * Prepaid cost released as expense in a date range (a release is dated the last day of its month).
     *
     * @return array{direct: int, operating: int}
     */
    public static function releasedBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = PrepaidRelease::query()->get(['month', 'amount', 'role'])
            ->filter(function ($r) use ($from, $to) {
                $monthEnd = CarbonImmutable::createFromFormat('Y-m-d', $r->month.'-01')->endOfMonth()->startOfDay();

                return $monthEnd->gte($from->startOfDay()) && $monthEnd->lte($to->startOfDay());
            });

        return ['direct' => (int) $rows->where('role', 'direct')->sum('amount'), 'operating' => (int) $rows->where('role', 'operating')->sum('amount')];
    }

    /** Prepaid cost still sitting in the asset: what has been paid but not yet released. */
    public static function unreleased(): int
    {
        $expenses = Expense::where('classification', 'prepaid')->where('status', 'verified')->whereNull('reverses_id')->where('payment_status', '!=', 'reversed');

        return (int) (clone $expenses)->sum('amount') - (int) PrepaidRelease::whereIn('expense_id', (clone $expenses)->select('id'))->sum('amount');
    }
}
