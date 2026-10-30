<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Resolution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Month-end processing. The manual screens and the scheduled run share the same close/verify code. The scheduled run
 * acts ONLY under an approved standing policy, never as a person (closed_by / prepared_by stay empty and the audit log
 * names the policy), only for finished months, one month after another, and is idempotent: running it again does
 * nothing new. Anything it cannot process becomes a named exception — nothing is skipped silently.
 */
class PeriodProcessor
{
    public static function close(string $month, ?User $user, ?Resolution $authority = null): AccountingPeriod
    {
        if ($month >= now()->format('Y-m')) {
            throw new RuntimeException('Only a finished month can be closed.');
        }
        if (AccountingPeriod::where('month', $month)->exists()) {
            throw new RuntimeException('This month is already closed.');
        }
        $f = FinancialStatements::monthFigures($month);
        if ($f['pending'] > 0) {
            throw new RuntimeException($f['pending'].' unverified expense(s) or revenue adjustment(s) fall in this month; verify or reject them first.');
        }

        return DB::transaction(function () use ($month, $f, $user, $authority) {
            $period = AccountingPeriod::create([
                'month' => $month, 'income' => $f['income'], 'expenses' => $f['expenses'], 'net_profit' => $f['income'] - $f['expenses'],
                'closed_by' => $user?->id, 'auto_policy_id' => $authority?->id,
            ]);
            // Book the month's cash, earned income, refunds and customer-advance liability (expenses were journaled on verification).
            $lines = RevenueRecognition::journalLines($f);
            if ($lines) {
                Ledger::post(CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString(), 'Income '.$month, 'period', $period->id, $lines, $user?->id);
            }
            Audit::log('period.closed', $month, ($authority ? 'SYSTEM under standing policy #'.$authority->id.': ' : '').'net '.$period->net_profit);

            return $period->refresh();
        });
    }

    public static function verify(AccountingPeriod $period, ?User $user, ?Resolution $authority = null): void
    {
        DB::transaction(function () use ($period, $user, $authority) {
            $period = AccountingPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== 'closed') {
                throw new RuntimeException('Already verified.');
            }
            if ($user && $period->closed_by === $user->id) {
                throw new RuntimeException('You cannot verify a period you closed.');
            }
            if (! $user && ! $authority) {
                throw new RuntimeException('A system verification needs the standing policy that authorises it.');
            }
            $period->update(['status' => 'verified', 'verified_by' => $user?->id, 'verified_at' => now()]);
            Audit::log('period.verified', $period->month, $authority ? 'SYSTEM under standing policy #'.$authority->id : null);
        });
    }

    /**
     * Months the scheduled run is responsible for: finished months from the policy's approval onwards that are not yet
     * fully processed (no first-ever backlog is closed without a person: the earliest month is the one before approval).
     *
     * @return list<string>
     */
    public static function months(Resolution $policy): array
    {
        $floor = CarbonImmutable::parse($policy->decided_at ?? $policy->created_at)->subMonth()->format('Y-m');
        $last = now()->subMonth()->format('Y-m');
        $months = AccountingPeriod::where('month', '>=', $floor)->doesntHave('allocation')->pluck('month')->all();
        $latest = AccountingPeriod::max('month');
        for ($m = $latest ? CarbonImmutable::createFromFormat('Y-m', $latest)->addMonth()->format('Y-m') : $last; $m <= $last; $m = CarbonImmutable::createFromFormat('Y-m', $m)->addMonth()->format('Y-m')) {
            if ($m >= $floor) {
                $months[] = $m;
            }
        }
        $months = array_values(array_unique($months));
        sort($months);

        return $months;
    }

    /**
     * Where a finished month stands, and exactly what blocks the next step. Read-only.
     *
     * @return array{state: string, blocker: ?string}
     */
    public static function readiness(string $month): array
    {
        $period = AccountingPeriod::with('allocation')->firstWhere('month', $month);
        if (! $period) {
            $f = FinancialStatements::monthFigures($month);

            return $f['pending'] > 0 ? ['state' => 'Not closed', 'blocker' => $f['pending'].' unverified expense(s) or revenue adjustment(s) in this month'] : ['state' => 'Ready to close', 'blocker' => null];
        }
        if ($period->allocation) {
            return ['state' => 'Allocation '.$period->allocation->status, 'blocker' => null];
        }
        if ($period->status === 'closed') {
            return ['state' => 'Closed — ready to verify', 'blocker' => null];
        }
        if ($period->net_profit === 0) {
            return ['state' => 'Verified — nothing to distribute', 'blocker' => null];
        }
        $policy = ProfitAllocator::policyFor($month);
        if (! $policy || ($blocker = $policy->liveBlocker())) {
            return ['state' => 'Verified', 'blocker' => $blocker ?? 'No policy version exists.'];
        }
        if ($period->net_profit > 0) {
            $owed = SourcingObligations::outstanding(CarbonImmutable::createFromFormat('Y-m', $month)->endOfMonth()->toDateString());
            if ($owed['total'] > 0) {
                return ['state' => 'Profit held back', 'blocker' => 'Open sourcing obligations (listed below)'];
            }
        }

        return ['state' => 'Verified — ready to allocate', 'blocker' => null];
    }

    /**
     * @return array{processed: list<string>, exceptions: list<array{month: string, reason: string, action: string}>}
     */
    public static function run(): array
    {
        $result = ['processed' => [], 'exceptions' => []];
        $policy = StandingPolicies::active(StandingPolicies::PERIOD);
        if (! $policy) {
            $result['exceptions'][] = ['month' => '—', 'reason' => 'No standing Board policy for automatic monthly close and allocation is active.', 'action' => 'The Board approves the standing policy, or periods are closed manually.'];
        } else {
            $closeBlocked = false;
            foreach (self::months($policy) as $month) {
                try {
                    $period = AccountingPeriod::with('allocation')->firstWhere('month', $month);
                    if (! $period) {
                        if ($closeBlocked) {
                            continue; // months close in order; the earlier exception explains why
                        }
                        $period = self::close($month, null, $policy);
                        $result['processed'][] = "$month closed";
                    }
                    if ($period->status === 'closed') {
                        self::verify($period, null, $policy);
                        $result['processed'][] = "$month verified";
                        $period->refresh();
                    }
                    if ($period->net_profit === 0) {
                        continue;
                    }
                    $allocation = ProfitAllocator::prepare($period, null, $policy);
                    ProfitAllocator::approve($allocation, null, $policy);
                    ProfitAllocator::post($allocation->refresh(), null);
                    $result['processed'][] = "$month ".$allocation->kind.' allocated and posted';
                } catch (RuntimeException $e) {
                    $closeBlocked = $closeBlocked || ! AccountingPeriod::where('month', $month)->exists();
                    $held = str_contains($e->getMessage(), 'sourcing obligations') ? ' See the holdback list for each supplier and lot.' : '';
                    $result['exceptions'][] = ['month' => $month, 'reason' => $e->getMessage().$held, 'action' => 'Resolve the cause; the next run (or "Run now") continues automatically.'];
                }
            }
        }

        $released = EmergencyFund::releaseStranded();
        foreach ($released as $r) {
            $result['processed'][] = 'Emergency coverage '.$r.' released to the reserve';
        }
        self::report($result);

        return $result;
    }

    /** One audit line per outcome per day (re-runs do not repeat), and a notification only when a person must act. */
    private static function report(array $result): void
    {
        foreach ($result['processed'] as $line) {
            Audit::log('automation.done', 'finance', $line);
        }
        foreach ($result['exceptions'] as $x) {
            $text = $x['month'].': '.$x['reason'];
            if (! AuditLog::where('action', 'automation.exception')->where('details', $text)->whereDate('created_at', today())->exists()) {
                Audit::log('automation.exception', 'finance', $text);
            }
            Notify::send(Notify::withPermission('period.close'), 'Finance automation needs attention', 'A month or fund item could not be processed automatically. Open Finance automation for the exact reason.', 'finance.automation', 'approval', 'auto:'.md5($text));
        }
    }
}
