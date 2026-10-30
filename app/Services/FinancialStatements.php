<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\PolicyVersion;
use Carbon\CarbonImmutable;

/**
 * Read-only statements built from the EXISTING ledger, closed periods and expenses. Nothing here posts anything and
 * no figure is stored, so the pages can never disagree with the journal.
 */
class FinancialStatements
{
    /** Verified expenses of a date range, net of verified corrections (a correction reverses an earlier expense). */
    public static function expenses(CarbonImmutable $start, CarbonImmutable $end, bool $sourcingOnly = false): int
    {
        $query = fn (bool $reversal) => Expense::where('status', 'verified')
            ->when($reversal, fn ($q) => $q->whereNotNull('reverses_id'), fn ($q) => $q->whereNull('reverses_id'))
            ->where('is_asset', false)
            ->when($sourcingOnly, fn ($q) => $q->where('category', 'like', 'Sourcing:%'))
            ->whereDate('spent_on', '>=', $start)->whereDate('spent_on', '<=', $end);

        return (int) $query(false)->sum('amount') - (int) $query(true)->sum('amount');
    }

    /**
     * Income, expenses and unverified items for one month (live figures; a closed period stores the same numbers).
     *
     * @return array<string, int>
     */
    public static function monthFigures(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->endOfMonth();
        $revenue = RevenueRecognition::figures($month);

        return [
            ...$revenue,
            'expenses' => self::expenses($start, $end),
            'pending' => Expense::where('status', 'pending')->whereDate('spent_on', '>=', $start)->whereDate('spent_on', '<=', $end)->count() + $revenue['pending'],
        ];
    }

    /**
     * Monthly and cumulative company profit & loss, with the approved waterfall (20% Salary, 5% Office & Growth,
     * 25% Emergency, 50% Partners split by the policy's profit ratio) from the real allocation when one exists,
     * otherwise a clearly marked preview.
     *
     * @return list<array<string, mixed>>
     */
    public static function profitAndLoss(): array
    {
        $periods = AccountingPeriod::with('allocation.lines')->orderBy('month')->get()->keyBy('month');
        $current = now()->format('Y-m');
        $months = $periods->keys()->all();
        $from = $periods->isEmpty() ? now()->subMonth()->format('Y-m') : CarbonImmutable::createFromFormat('Y-m', $periods->keys()->last())->addMonth()->format('Y-m');
        for ($m = $from; $m <= $current; $m = CarbonImmutable::createFromFormat('Y-m', $m)->addMonth()->format('Y-m')) {
            $months[] = $m;
        }
        $months = array_values(array_unique($months));
        sort($months);

        $codes = Partner::pluck('code', 'id');
        $rows = [];
        $cumulative = 0;
        foreach ($months as $month) {
            $period = $periods[$month] ?? null;
            $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');
            $live = $period ? null : self::monthFigures($month);
            $income = $period ? $period->income : $live['income'];
            $expenses = $period ? $period->expenses : $live['expenses'];
            $net = $income - $expenses;
            $cumulative += $net;

            $allocation = $period?->allocation;
            $policy = $allocation?->policy ?? ProfitAllocator::policyFor($month);
            $lines = $allocation ? $allocation->lines->map(fn ($l) => ['account' => $l->account, 'partner_id' => $l->partner_id, 'amount' => (int) $l->amount])->all()
                : ($policy && $net !== 0 ? ProfitAllocator::preview($net, $policy, ProfitAllocator::coverageFor($net)) : []);
            $sum = fn (string $account) => (int) collect($lines)->where('account', $account)->sum('amount');
            $partners = [];
            foreach (collect($lines)->where('account', 'partner.payable') as $l) {
                $partners[$codes[$l['partner_id']]] = $l['amount'];
            }

            $rows[] = [
                'month' => $month, 'state' => $period ? $period->status : ($month === $current ? 'open (current month)' : 'not closed'),
                'income' => $income, 'expenses' => $expenses, 'sourcing' => self::expenses($start, $start->endOfMonth(), true),
                'net' => $net, 'cumulative' => $cumulative, 'pending' => $live['pending'] ?? 0,
                'basis' => $allocation ? 'allocation '.$allocation->status : 'preview',
                'salary' => $sum('fund.salary'), 'office' => $sum('fund.office'), 'emergency' => $sum('fund.emergency'),
                'partner_pool' => array_sum($partners), 'partners' => $partners,
                'loss_charge' => $sum('partner.capital'), 'coverage' => $sum('fund.coverage'),
            ];
        }

        return $rows;
    }

    /**
     * Balance sheet straight from the journal, plus memo items the cash-basis ledger does not carry.
     *
     * @return array<string, mixed>
     */
    public static function position(): array
    {
        $balances = JournalLine::query()->selectRaw('account, SUM(debit) d, SUM(credit) c')->groupBy('account')->get()
            ->mapWithKeys(fn ($r) => [$r->account => (int) $r->d - (int) $r->c]); // debit-normal

        $groups = ['assets' => [], 'liabilities' => [], 'capital' => [], 'funds' => [], 'result' => []];
        $labels = [
            'cash' => 'Cash & bank', 'receivables' => 'Customer receivables (ledger)', 'customer.advances' => 'Customer advances (unearned)',
            'liabilities' => 'Supplier / other liabilities', 'partner.payable' => 'Partner profit entitlement payable', 'partner.capital' => 'Partner capital (net of loss charges)',
            'fund.salary' => 'Salary Fund', 'fund.office' => 'Office & Growth Fund', 'fund.emergency' => 'Emergency Reserve', 'fund.coverage' => 'Emergency coverage held for partner losses',
            'equity.opening' => 'Opening equity', 'assets.equipment' => 'Office equipment & fixed assets (at cost, not depreciated)', 'supplier.credit' => 'Supplier credit notes receivable',
        ];
        foreach ($balances as $account => $debit) {
            $bucket = match (true) {
                in_array($account, ['cash', 'receivables', 'assets.equipment', 'supplier.credit'], true) => 'assets',
                in_array($account, ['customer.advances', 'liabilities', 'partner.payable'], true) => 'liabilities',
                $account === 'partner.capital' || $account === 'equity.opening' => 'capital',
                str_starts_with($account, 'fund.') => 'funds',
                default => 'result',
            };
            $groups[$bucket][] = ['label' => $labels[$account] ?? $account, 'amount' => $bucket === 'assets' ? $debit : -$debit];
        }
        $total = fn (string $g) => (int) collect($groups[$g])->sum('amount');

        $sourcing = SourcingObligations::outstanding();
        $currency = config('finance.currency');
        $receivable = (int) Invoice::where('currency', $currency)->sum('total') - (int) Invoice::where('currency', $currency)->sum('amount_paid');
        $cash = (int) ($balances['cash'] ?? 0);
        $recorded = -(int) ($balances['partner.payable'] ?? 0) - (int) ($balances['customer.advances'] ?? 0) - (int) ($balances['liabilities'] ?? 0);
        $committed = $recorded + $sourcing['total'];

        return [
            'groups' => $groups, 'assets' => $total('assets'), 'liabilities' => $total('liabilities'),
            'equity' => $total('capital') + $total('funds') + $total('result'),
            'capital' => $total('capital'), 'funds' => $total('funds'), 'result' => $total('result'),
            'balanced' => $total('assets') === $total('liabilities') + $total('capital') + $total('funds') + $total('result'),
            'cash' => $cash, 'recorded_liabilities' => $recorded, 'client_receivable' => $receivable, 'sourcing' => $sourcing, 'committed' => $committed, 'free_cash' => $cash - $committed,
        ];
    }

    /**
     * Owner investment and capital, per partner, against the policy's commitment, plus entitlement and payout status.
     *
     * @return array<string, mixed>
     */
    public static function capital(): array
    {
        $policy = PolicyVersion::orderByDesc('effective_from')->orderByDesc('id')->first();
        $partners = Partner::orderBy('sort')->get();
        $lines = JournalLine::query()->selectRaw('account, partner_id, SUM(debit) d, SUM(credit) c')
            ->whereIn('account', ['partner.capital', 'partner.payable'])->groupBy('account', 'partner_id')->get()
            ->groupBy('partner_id')->map(fn ($g) => $g->keyBy('account'));

        $rows = $partners->map(function (Partner $p) use ($lines, $policy) {
            $l = $lines[$p->id] ?? collect();
            $contributed = (int) ($l['partner.capital']->c ?? 0);
            $lossCharged = (int) ($l['partner.capital']->d ?? 0);
            $accrued = (int) ($l['partner.payable']->c ?? 0);
            $paidOut = (int) ($l['partner.payable']->d ?? 0);

            return [
                'partner' => $p, 'target_bps' => $policy?->investment_bps[$p->code] ?? null, 'contributed' => $contributed,
                'pending' => (int) CapitalEntry::where('partner_id', $p->id)->where('status', 'pending')->sum('amount'),
                'loss_charged' => $lossCharged, 'capital_balance' => $contributed - $lossCharged,
                'accrued' => $accrued, 'paid_out' => $paidOut, 'outstanding' => $accrued - $paidOut,
                'payout_open' => (int) Payout::where('partner_id', $p->id)->whereIn('status', ['requested', 'approved'])->sum('amount'),
            ];
        });
        $total = (int) $rows->sum('contributed');
        $rows = $rows->map(function ($r) use ($total) {
            $r['actual_bps'] = $total > 0 ? intdiv($r['contributed'] * 10000, $total) : null;
            // What this partner's paid-in capital should be if the policy proportions held across the same total.
            $r['target_amount'] = $r['target_bps'] !== null ? intdiv($total * $r['target_bps'], 10000) : null;
            $r['gap'] = $r['target_amount'] !== null ? $r['target_amount'] - $r['contributed'] : null;

            return $r;
        });

        return ['policy' => $policy, 'rows' => $rows, 'total' => $total, 'funds' => [
            'Salary Fund' => Ledger::creditBalance('fund.salary'), 'Office & Growth Fund' => Ledger::creditBalance('fund.office'), 'Emergency Reserve' => Ledger::creditBalance('fund.emergency'),
        ], 'held_coverage' => SourcingObligations::heldCoverage()];
    }
}
