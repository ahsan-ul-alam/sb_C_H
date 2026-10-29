<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Support\Split;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Net profit waterfall: 50% Company Fund / 50% Partners' Profit Fund; Company Fund = 50% Growth / 50% Emergency;
 * Growth = 80% Salary / 20% Office. Leaf weights are integers over 1,000,000 and the amount is split once with
 * the largest-remainder method, so every paisa is allocated exactly and deterministically.
 */
class ProfitAllocator
{
    public const FUND_ACCOUNTS = ['fund.salary', 'fund.office', 'fund.emergency'];

    /**
     * The policy governing a period: latest APPROVED version effective by month end, else the latest draft
     * (usable for preview only).
     */
    public static function policyFor(string $month): ?PolicyVersion
    {
        $end = CarbonImmutable::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
        $versions = PolicyVersion::orderByDesc('effective_from')->orderByDesc('id')->get();

        return $versions->first(fn ($p) => $p->isApproved() && $p->effective_from->toDateString() <= $end)
            ?? $versions->first(fn ($p) => ! $p->isApproved());
    }

    /**
     * Pure calculation, never writes. Returns lines of [account, partner_id, amount].
     *
     * @return list<array{account: string, partner_id: int|null, amount: int}>
     */
    public static function preview(int $netProfit, PolicyVersion $policy): array
    {
        $ids = Partner::pluck('id', 'code');

        if ($netProfit < 0) {
            $shares = Split::apply(-$netProfit, $policy->loss_shares);

            return collect($shares)->map(fn ($amount, $code) => ['account' => 'partner.capital', 'partner_id' => $ids[$code], 'amount' => $amount])->values()->all();
        }
        if ($netProfit === 0) {
            return [];
        }

        $weights = ['fund.salary' => 200000, 'fund.office' => 50000, 'fund.emergency' => 250000];
        foreach ($policy->profit_bps as $code => $bps) {
            $weights['partner.payable:'.$code] = 50 * (int) $bps;
        }

        $lines = [];
        foreach (Split::apply($netProfit, $weights) as $key => $amount) {
            [$account, $code] = array_pad(explode(':', $key), 2, null);
            $lines[] = ['account' => $account, 'partner_id' => $code ? $ids[$code] : null, 'amount' => $amount];
        }

        return $lines;
    }

    public static function prepare(AccountingPeriod $period, User $user): Allocation
    {
        if ($period->status !== 'verified') {
            throw new RuntimeException('The period must be verified by the Board before allocation.');
        }
        if ($period->net_profit === 0) {
            throw new RuntimeException('Net profit is zero; there is nothing to distribute.');
        }
        $policy = self::policyFor($period->month) ?? throw new RuntimeException('No policy version exists.');
        if ($blocker = $policy->liveBlocker()) {
            throw new RuntimeException($blocker.' Preview is available, but a live allocation cannot be prepared.');
        }

        return DB::transaction(function () use ($period, $user, $policy) {
            if (Allocation::where('accounting_period_id', $period->id)->exists()) {
                throw new RuntimeException('This period already has an allocation.');
            }
            $allocation = Allocation::create([
                'accounting_period_id' => $period->id, 'policy_version_id' => $policy->id,
                'kind' => $period->net_profit > 0 ? 'profit' : 'loss', 'net_profit' => $period->net_profit,
                'prepared_by' => $user->id,
            ]);
            $allocation->lines()->createMany(self::preview($period->net_profit, $policy));
            Audit::log('allocation.prepared', 'period '.$period->month, 'net '.$period->net_profit);

            return $allocation;
        });
    }

    public static function approve(Allocation $allocation, User $user): void
    {
        DB::transaction(function () use ($allocation, $user) {
            $allocation = Allocation::lockForUpdate()->findOrFail($allocation->id);
            if ($allocation->status !== 'proposed') {
                throw new RuntimeException('Allocation is not awaiting approval.');
            }
            if ($allocation->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve an allocation you prepared.');
            }
            $allocation->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
            Audit::log('allocation.approved', 'allocation '.$allocation->id);
        });
    }

    /**
     * One-time atomic posting. The unique allocation per period plus the status re-check under lock prevent doubles.
     */
    public static function post(Allocation $allocation, User $user): void
    {
        DB::transaction(function () use ($allocation, $user) {
            $allocation = Allocation::lockForUpdate()->findOrFail($allocation->id);
            if ($allocation->status === 'posted') {
                throw new RuntimeException('This allocation is already posted.');
            }
            if ($allocation->status !== 'approved') {
                throw new RuntimeException('The Board must approve the allocation before posting.');
            }
            if ($blocker = $allocation->policy->liveBlocker()) {
                throw new RuntimeException($blocker);
            }

            $lines = $allocation->lines;
            $total = (int) $lines->sum('amount');
            $month = $allocation->period->month;
            $date = CarbonImmutable::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();

            if ($allocation->kind === 'profit') {
                $journal = [['profit.pool', null, $total, 0]];
                foreach ($lines as $l) {
                    $journal[] = [$l->account, $l->partner_id, 0, (int) $l->amount];
                }
            } else {
                $journal = [['loss.pool', null, 0, $total]];
                foreach ($lines as $l) {
                    $journal[] = [$l->account, $l->partner_id, (int) $l->amount, 0];
                }
            }

            $entry = Ledger::post($date, ucfirst($allocation->kind).' allocation '.$month, 'allocation', $allocation->id, $journal, $user->id);
            $allocation->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now(), 'journal_entry_id' => $entry->id]);
            Audit::log('allocation.posted', 'allocation '.$allocation->id, 'period '.$month.' total '.$total);
        });
    }

    /**
     * Verified Company Fund allocations (new policy only) in the 12 months up to the exit date.
     */
    public static function companyFundBase(CarbonImmutable $exitDate): int
    {
        $from = $exitDate->subMonths(12)->format('Y-m');

        return (int) DB::table('allocation_lines as l')
            ->join('allocations as a', 'a.id', '=', 'l.allocation_id')
            ->join('accounting_periods as p', 'p.id', '=', 'a.accounting_period_id')
            ->join('policy_versions as v', 'v.id', '=', 'a.policy_version_id')
            ->where('a.status', 'posted')->where('a.kind', 'profit')->where('v.is_legacy', false)
            ->whereIn('l.account', self::FUND_ACCOUNTS)
            ->where('p.month', '>', $from)
            ->where('p.month', '<=', $exitDate->format('Y-m'))
            ->sum('l.amount');
    }
}
