<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RevenueAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Company-wide figures for the financial overview. Every number is derived from the same verified sources as the ledger
 * (verified expenses, payments, verified revenue adjustments, the journal), so dashboard cards, reports and project pages
 * cannot drift apart. Money is integer minor units throughout; nothing here is stored.
 *
 * Basis: accrual for profit (revenue when recognised, costs when verified), cash for balances.
 *   Gross profit     = recognised revenue − direct project costs
 *   Operating profit = gross profit − operating expenses
 *   Net profit       = operating profit + other income − other expenses − taxes
 * Equipment/asset purchases, owner investments and withdrawals, transfers and payments of payables never touch profit.
 */
class FinancialReportingService
{
    /**
     * Profit & loss for any date range.
     *
     * @return array<string, int>
     */
    public static function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $revenue = RevenueRecognition::between($from->startOfDay(), $to->endOfDay());
        $expenses = self::expenseTotals($from, $to);
        $direct = $expenses['direct'];
        $gross = $revenue['income'] - $direct;
        $operating = $gross - $expenses['operating'];
        $otherIncome = FinancialStatements::otherIncome($from, $to);
        $net = $operating + $otherIncome - $expenses['other'] - $expenses['tax'];

        // Memo only, never posted: the tax the configured rate would put on profit before the taxes actually recorded.
        $rate = (int) config('finance.tax_rate_bps');
        $beforeTax = $net + $expenses['tax'];

        return [
            'collected' => $revenue['collected'], 'recognised_revenue' => $revenue['income'],
            'direct_costs' => $direct, 'gross_profit' => $gross,
            'operating_expenses' => $expenses['operating'], 'operating_profit' => $operating,
            'other_income' => $otherIncome, 'other_expenses' => $expenses['other'], 'taxes' => $expenses['tax'],
            'net_profit' => $net, 'total_expenses' => $direct + $expenses['operating'] + $expenses['other'] + $expenses['tax'],
            'estimated_tax' => $rate > 0 ? intdiv(max(0, $beforeTax) * $rate, 10000) : 0,
        ];
    }

    /**
     * Profit and loss read straight from the journal (income accounts minus the expense account, by entry date). It is an
     * independent cross-check of profitAndLoss(): the two agree for every month whose events are all journaled.
     *
     * @return array{revenue: int, other_income: int, expenses: int, net: int}
     */
    public static function ledgerProfitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account', ['income', 'income.other', 'expense'])
            ->whereDate('journal_entries.entry_date', '>=', $from)->whereDate('journal_entries.entry_date', '<=', $to)
            ->selectRaw('journal_lines.account account, SUM(journal_lines.credit) c, SUM(journal_lines.debit) d')->groupBy('journal_lines.account')->get()->keyBy('account');
        $net = fn (string $a) => (int) ($rows[$a]->c ?? 0) - (int) ($rows[$a]->d ?? 0);

        return ['revenue' => $net('income'), 'other_income' => $net('income.other'), 'expenses' => -$net('expense'), 'net' => $net('income') + $net('income.other') + $net('expense')];
    }

    /**
     * Why the dashboard's accounting profit for a month can differ from the profit the monthly close freezes and the partners'
     * allocation distributes. Distributable profit is only ever the figure on an AccountingPeriod / Allocation; this is a read-only
     * explanation, not a second source of truth.
     *
     * @return array<string, mixed>
     */
    public static function distributableBridge(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $pl = self::profitAndLoss($start, $end);
        $period = AccountingPeriod::with('allocation')->firstWhere('month', $month);
        $basis = config('finance.distributable_basis');
        $legacy = FinancialStatements::monthFigures($month);

        $prepaidPaid = (int) Expense::where('status', 'verified')->where('classification', 'prepaid')->whereNull('reverses_id')
            ->whereDate('spent_on', '>=', $start)->whereDate('spent_on', '<=', $end)->sum('amount');
        $released = PrepaidService::releasedBetween($start, $end);

        return [
            'month' => $month, 'basis' => $basis,
            'accounting_profit' => $pl['net_profit'],
            'distributable_basis_profit' => $legacy['income'] - $legacy['expenses'], // live, on the configured basis
            'frozen_period_profit' => $period?->net_profit,
            'allocation_status' => $period?->allocation?->status,
            'items' => [
                'Other income (accounting only unless the Board adopts the accounting basis)' => $basis === 'accounting' ? 0 : $pl['other_income'],
                'Prepaid costs: accounting spreads them; the legacy basis expenses them when paid' => $basis === 'accounting' ? 0 : $prepaidPaid - ($released['direct'] + $released['operating']),
            ],
            'unverified_items' => $legacy['pending'],
        ];
    }

    /**
     * Verified, non-asset expenses of a range, net of verified reversals, split by role in the P&L.
     * Sourcing supplier payments are cost of sales, so they count as direct costs.
     *
     * @return array{direct: int, operating: int, other: int, tax: int}
     */
    public static function expenseTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Prepaid purchases are an asset, not an expense, until released (month by month) below.
        $rows = Expense::query()->where('status', 'verified')->where('is_asset', false)->where('classification', '!=', 'prepaid')
            ->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)
            ->selectRaw("CASE WHEN classification = 'direct' OR category LIKE 'Sourcing:%' THEN 'direct' ELSE classification END AS cost_role, SUM(CASE WHEN reverses_id IS NULL THEN CAST(amount AS SIGNED) ELSE -CAST(amount AS SIGNED) END) AS total")
            ->groupBy('cost_role')->pluck('total', 'cost_role');

        $released = PrepaidService::releasedBetween($from, $to);

        return [
            'direct' => (int) ($rows['direct'] ?? 0) + $released['direct'], 'operating' => (int) ($rows['operating'] ?? 0) + $released['operating'],
            'other' => (int) ($rows['other'] ?? 0), 'tax' => (int) ($rows['tax'] ?? 0),
        ];
    }

    /**
     * The twelve KPI cards. Flow figures follow the selected range; balances are as of today.
     *
     * @return array<string, int>
     */
    public static function kpis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pl = self::profitAndLoss($from, $to);
        $currency = config('finance.currency');
        $projectIds = Project::where('currency', $currency)->pluck('id');
        $projects = ProjectFinancialService::summaries($projectIds);
        $expenses = self::expenseTotals($from, $to);

        return [
            'capital_invested' => self::capitalInvested(),
            'contract_value' => (int) $projects->sum('contract_value'),
            'recognised_revenue' => $pl['recognised_revenue'],
            'payments_received' => $pl['collected'],
            'outstanding_receivables' => (int) $projects->sum(fn ($p) => max(0, $p['outstanding'])),
            'project_expenses' => $expenses['direct'],
            'operating_expenses' => $pl['operating_expenses'] + $pl['other_expenses'] + $pl['taxes'],
            'total_expenses' => $pl['total_expenses'],
            'gross_profit' => $pl['gross_profit'],
            'net_profit' => $pl['net_profit'],
            'available_cash' => AccountingService::totalCash(),
            'accounts_payable' => self::accountsPayable(),
            'loans_outstanding' => Ledger::creditBalance('loans.payable'),
        ];
    }

    /** Paid-in capital from verified contributions (withdrawals are shown separately, never netted into "invested"). */
    public static function capitalInvested(): int
    {
        return (int) CapitalEntry::where('kind', 'contribution')->where('status', 'verified')->sum('amount')
            + (int) DB::table('opening_lines as l')->join('opening_batches as b', 'b.id', '=', 'l.opening_batch_id')
                ->where('b.status', 'posted')->where('l.kind', 'partner_capital')->sum('l.amount');
    }

    /** Verified expenses (and nothing else) not yet paid. */
    public static function accountsPayable(): int
    {
        return (int) Expense::where('status', 'verified')->whereNull('reverses_id')->whereIn('payment_status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount'));
    }

    /**
     * Last N months, oldest first, for the charts. Four grouped queries in total, aggregated in PHP.
     *
     * @return array{months: list<string>, revenue: array<string,int>, expenses: array<string,int>, net: array<string,int>, inflow: array<string,int>, outflow: array<string,int>, balance: array<string,int>, invested: array<string,int>}
     */
    public static function monthlySeries(int $months = 12): array
    {
        $end = CarbonImmutable::today()->endOfMonth();
        $start = $end->subMonthsNoOverflow($months - 1)->startOfMonth();
        $keys = [];
        for ($m = $start; $m->lte($end); $m = $m->addMonthNoOverflow()) {
            $keys[] = $m->format('Y-m');
        }
        $zero = array_fill_keys($keys, 0);
        $monthOf = fn ($date) => CarbonImmutable::parse($date)->format('Y-m');
        $currency = config('finance.currency');

        $revenue = $zero;
        Payment::query()->join('projects', 'projects.id', '=', 'payments.project_id')->where('projects.currency', $currency)
            ->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $end)->get(['paid_on', 'amount'])
            ->each(function ($p) use (&$revenue, $monthOf) {
                $revenue[$monthOf($p->paid_on)] += (int) $p->amount;
            });
        RevenueAdjustment::query()->join('payments', 'payments.id', '=', 'revenue_adjustments.payment_id')->join('projects', 'projects.id', '=', 'payments.project_id')
            ->where('projects.currency', $currency)->where('revenue_adjustments.status', 'verified')
            ->get(['revenue_adjustments.kind', 'revenue_adjustments.against', 'revenue_adjustments.amount', 'revenue_adjustments.effective_on', 'payments.paid_on'])
            ->each(function ($a) use (&$revenue, $monthOf, $keys) {
                // The same month attribution as RevenueRecognition: an advance counts in the payment's month, the rest in their own.
                $month = $monthOf($a->kind === 'advance' ? $a->paid_on : $a->effective_on);
                if (! in_array($month, $keys, true)) {
                    return;
                }
                $revenue[$month] += match (true) {
                    $a->kind === 'advance' => -(int) $a->amount,
                    $a->kind === 'recognition' => (int) $a->amount,
                    $a->kind === 'refund' && $a->against === 'revenue' => -(int) $a->amount,
                    default => 0,
                };
            });

        $expenses = $zero;
        Expense::query()->where('status', 'verified')->where('is_asset', false)->whereDate('spent_on', '>=', $start)->whereDate('spent_on', '<=', $end)
            ->get(['spent_on', 'amount', 'reverses_id'])->each(function ($e) use (&$expenses, $monthOf) {
                $expenses[$monthOf($e->spent_on)] += $e->reverses_id ? -(int) $e->amount : (int) $e->amount;
            });

        $inflow = $outflow = $zero;
        JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', 'cash')
            ->whereDate('journal_entries.entry_date', '>=', $start)->whereDate('journal_entries.entry_date', '<=', $end)
            ->get(['journal_entries.entry_date', 'journal_lines.debit', 'journal_lines.credit', 'journal_entries.source_type'])
            ->each(function ($l) use (&$inflow, &$outflow, $monthOf) {
                if ($l->source_type === 'transfer' || $l->source_type === 'transfer_reversal') {
                    return; // internal moves are neither inflow nor outflow of the company
                }
                $inflow[$monthOf($l->entry_date)] += (int) $l->debit;
                $outflow[$monthOf($l->entry_date)] += (int) $l->credit;
            });

        $opening = (int) JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', 'cash')
            ->whereDate('journal_entries.entry_date', '<', $start)->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS b')->value('b');
        $balance = [];
        $running = $opening;
        foreach ($keys as $k) {
            $running += $inflow[$k] - $outflow[$k];
            $balance[$k] = $running;
        }

        $invested = $zero;
        CapitalEntry::where('status', 'verified')->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $end)->get(['paid_on', 'amount', 'kind'])
            ->each(function ($c) use (&$invested, $monthOf) {
                $invested[$monthOf($c->paid_on)] += $c->kind === 'withdrawal' ? -(int) $c->amount : (int) $c->amount;
            });

        return [
            'months' => $keys, 'revenue' => $revenue, 'expenses' => $expenses,
            'net' => array_map(fn ($k) => $revenue[$k] - $expenses[$k], array_combine($keys, $keys)),
            'inflow' => $inflow, 'outflow' => $outflow, 'balance' => $balance, 'invested' => $invested,
        ];
    }

    /**
     * Expense categories of a range, largest first (net of reversals; capital purchases excluded).
     *
     * @return Collection<int, array{category: string, total: int}>
     */
    public static function expenseCategories(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Expense::query()->where('status', 'verified')->where('is_asset', false)->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)
            ->selectRaw('category, SUM(CASE WHEN reverses_id IS NULL THEN CAST(amount AS SIGNED) ELSE -CAST(amount AS SIGNED) END) AS total')->groupBy('category')->orderByDesc('total')->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => (int) $r->total])->filter(fn ($r) => $r['total'] !== 0)->values();
    }

    /**
     * Project-wise profitability for projects in the ledger currency, best gross profit first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function projectProfitability(int $limit = 10): Collection
    {
        $projects = Project::where('currency', config('finance.currency'))->get(['id', 'name']);
        $summaries = ProjectFinancialService::summaries($projects->pluck('id'));

        return $projects->map(fn ($p) => ['project' => $p, ...$summaries[$p->id]])
            ->filter(fn ($r) => $r['recognised_revenue'] !== 0 || $r['direct_costs'] !== 0)
            ->sortByDesc('gross_profit')->take($limit)->values();
    }
}
