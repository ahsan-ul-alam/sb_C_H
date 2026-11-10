<?php

namespace App\Services;

use App\Models\Allocation;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\PolicyVersion;

/**
 * READ-ONLY investor and distribution figures drawn from the existing records: posted allocations (allocation_lines for partner.payable), the partner.payable ledger
 * (paid out = its debits) and FinancialStatements::capital(). Nothing is posted, estimated or derived from capital. Four things stay apart:
 *   capital            verified contributions less withdrawals and charged losses
 *   profit             allocated (posted allocation lines) / paid (verified payouts) / outstanding payable
 *   loan recovery      tracking only; never added to capital or profit
 *   cash               never implied by an allocation: only a paid payout moves cash
 * Paid-out money is a partner-level ledger total, so the period-wise "paid" is shown oldest-first (first allocated, first settled): a display attribution only.
 */
class InvestorReporting
{
    /**
     * The approved profit share of a partner under the policy governing a month, or "pending approval" when the policy is missing or its ratio is not confirmed.
     *
     * @return array{state: string, bps: int|null, policy: string|null}
     */
    public static function approvedShare(Partner $partner, ?PolicyVersion $policy): array
    {
        if ($policy === null || ! $policy->isApproved() || ! $policy->isProfitConfirmed() || ! isset($policy->profit_bps[$partner->code])) {
            return ['state' => 'Pending Approval', 'bps' => null, 'policy' => $policy?->name];
        }

        return ['state' => 'Approved', 'bps' => (int) $policy->profit_bps[$partner->code], 'policy' => $policy->name];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forPartner(Partner $partner): array
    {
        $capital = FinancialStatements::capital();
        $row = $capital['rows']->first(fn ($r) => $r['partner']->id === $partner->id);
        $allocations = Allocation::with(['period', 'policy', 'lines' => fn ($q) => $q->where('account', 'partner.payable')->where('partner_id', $partner->id)])
            ->where('status', 'posted')->get()->sortBy(fn ($a) => $a->period->month)->values();

        $unsettled = (int) ($row['paid_out'] ?? 0);
        $history = $allocations->map(function (Allocation $a) use (&$unsettled, $partner) {
            $amount = (int) $a->lines->sum('amount');
            $paid = min($amount, $unsettled);
            $unsettled -= $paid;
            $share = self::approvedShare($partner, $a->policy);

            return ['month' => $a->period->month, 'allocation_id' => $a->id, 'kind' => $a->kind, 'net_profit' => (int) $a->net_profit, 'policy' => $a->policy?->name,
                'share_bps' => $share['bps'], 'share_state' => $share['state'], 'allocated' => $amount, 'paid' => $paid, 'outstanding' => $amount - $paid, 'status' => $a->status];
        });
        $policy = PolicyVersion::orderByDesc('effective_from')->orderByDesc('id')->first();

        return [
            'capital' => ['contributed' => (int) ($row['contributed'] ?? 0), 'withdrawn' => (int) ($row['withdrawn'] ?? 0), 'loss_charged' => (int) ($row['loss_charged'] ?? 0),
                'pending' => (int) ($row['pending'] ?? 0), 'balance' => (int) ($row['capital_balance'] ?? 0)],
            'profit' => ['share' => self::approvedShare($partner, $policy), 'allocated' => (int) ($row['accrued'] ?? 0), 'paid' => (int) ($row['paid_out'] ?? 0),
                'outstanding' => (int) ($row['outstanding'] ?? 0), 'payout_open' => (int) ($row['payout_open'] ?? 0), 'history' => $history->reverse()->values()],
            'loan_recovery' => ['allocated' => (int) ($row['loan_recovery_allocated'] ?? 0)],
        ];
    }

    /**
     * Company-level distribution position: per posted allocation what was set aside for the investors, what was paid and what is still payable.
     *
     * @return array{periods: list<array<string, mixed>>, total: array<string, int>, unapproved: list<string>}
     */
    public static function distribution(?string $from = null, ?string $to = null): array
    {
        $allocations = Allocation::with(['period', 'lines' => fn ($q) => $q->where('account', 'partner.payable')])->whereIn('status', ['proposed', 'approved', 'posted'])->get()
            ->filter(fn ($a) => ($from === null || $a->period->month >= $from) && ($to === null || $a->period->month <= $to))->sortBy(fn ($a) => $a->period->month)->values();
        $periods = $allocations->map(fn (Allocation $a) => ['month' => $a->period->month, 'income' => (int) $a->period->income, 'expenses' => (int) $a->period->expenses, 'net_profit' => (int) $a->net_profit,
            'status' => $a->status, 'investor_pool' => (int) $a->lines->sum('amount'), 'counts_as_distributed' => $a->status === 'posted'])->all();
        $posted = collect($periods)->where('counts_as_distributed', true)->sum('investor_pool');
        $paid = (int) JournalLine::where('account', 'partner.payable')->sum('debit');

        return ['periods' => $periods, 'total' => ['allocated' => (int) $posted, 'paid' => $paid, 'outstanding' => (int) $posted - $paid],
            'unapproved' => collect($periods)->where('counts_as_distributed', false)->pluck('month')->all()];
    }
}
