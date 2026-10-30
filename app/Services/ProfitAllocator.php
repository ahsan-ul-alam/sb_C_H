<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Models\Resolution;
use App\Models\User;
use App\Support\Money;
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
    public static function preview(int $netProfit, PolicyVersion $policy, int $coverage = 0): array
    {
        $ids = Partner::pluck('id', 'code');

        if ($netProfit < 0) {
            // Approved Emergency Fund coverage absorbs part of the loss; the partners' equal-share rule applies to the rest.
            $coverage = max(0, min($coverage, -$netProfit));
            $shares = Split::apply(-$netProfit - $coverage, $policy->loss_shares);
            $lines = collect($shares)->map(fn ($amount, $code) => ['account' => 'partner.capital', 'partner_id' => $ids[$code], 'amount' => $amount])->filter(fn ($l) => $l['amount'] > 0)->values()->all();
            if ($coverage > 0) {
                $lines[] = ['account' => 'fund.coverage', 'partner_id' => null, 'amount' => $coverage];
            }

            return $lines;
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

    /**
     * Emergency Fund coverage that may reduce this loss: only with an approved Board resolution, only what is held
     * and unclaimed, and never more than the loss. Zero for a profit.
     */
    public static function coverageFor(int $netProfit): int
    {
        if ($netProfit >= 0 || ! SourcingObligations::coverageResolutionApproved()) {
            return 0;
        }

        return min(-$netProfit, SourcingObligations::heldCoverage());
    }

    public static function prepare(AccountingPeriod $period, ?User $user, ?Resolution $authority = null): Allocation
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

        // Income is cash-based, so a profit may still owe money the ledger has not seen yet. Do not distribute it first.
        if ($period->net_profit > 0) {
            $owed = SourcingObligations::outstanding(CarbonImmutable::createFromFormat('Y-m', $period->month)->endOfMonth()->toDateString());
            if ($owed['total'] > 0) {
                throw new RuntimeException('Profit cannot be allocated while sourcing obligations are open (supplier payables '.Money::format($owed['suppliers']).', unpaid lot costs '.Money::format($owed['costs']).', payments awaiting verification '.Money::format($owed['pending']).'). Pay, verify and settle them first.');
            }
        }

        if ($user === null && $authority === null) {
            throw new RuntimeException('A system allocation needs the standing policy that authorises it.');
        }

        return DB::transaction(function () use ($period, $user, $policy, $authority) {
            if (Allocation::where('accounting_period_id', $period->id)->exists()) {
                throw new RuntimeException('This period already has an allocation.');
            }
            $coverage = self::coverageFor($period->net_profit);
            $allocation = Allocation::create([
                'accounting_period_id' => $period->id, 'policy_version_id' => $policy->id,
                'kind' => $period->net_profit > 0 ? 'profit' : 'loss', 'net_profit' => $period->net_profit,
                'prepared_by' => $user?->id, 'auto_policy_id' => $authority?->id,
            ]);
            $allocation->lines()->createMany(self::preview($period->net_profit, $policy, $coverage));
            Audit::log('allocation.prepared', 'period '.$period->month, (($authority ? 'SYSTEM under standing policy #'.$authority->id.': ' : '').'net '.$period->net_profit).($coverage ? ' emergency coverage '.$coverage : ''));

            return $allocation;
        });
    }

    public static function approve(Allocation $allocation, ?User $user, ?Resolution $authority = null): void
    {
        DB::transaction(function () use ($allocation, $user, $authority) {
            if ($user === null && $authority === null) {
                throw new RuntimeException('A system approval needs the standing policy that authorises it.');
            }
            $allocation = Allocation::lockForUpdate()->findOrFail($allocation->id);
            if ($allocation->status !== 'proposed') {
                throw new RuntimeException('Allocation is not awaiting approval.');
            }
            if ($user && $allocation->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve an allocation you prepared.');
            }
            $allocation->update(['status' => 'approved', 'approved_by' => $user?->id, 'approved_at' => now()]);
            Audit::log('allocation.approved', 'allocation '.$allocation->id, $authority ? 'SYSTEM under standing policy #'.$authority->id : null);
        });
    }

    /**
     * One-time atomic posting. The unique allocation per period plus the status re-check under lock prevent doubles.
     */
    public static function post(Allocation $allocation, ?User $user): void
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
            $coverage = (int) $lines->where('account', 'fund.coverage')->sum('amount');
            if ($coverage > Ledger::creditBalance('fund.coverage')) {
                throw new RuntimeException('The Emergency Fund coverage in this allocation is no longer held (it may have been reversed). Cancel and prepare it again.');
            }
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

            $entry = Ledger::post($date, ucfirst($allocation->kind).' allocation '.$month, 'allocation', $allocation->id, $journal, $user?->id);
            $allocation->update(['status' => 'posted', 'posted_by' => $user?->id, 'posted_at' => now(), 'journal_entry_id' => $entry->id]);
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
