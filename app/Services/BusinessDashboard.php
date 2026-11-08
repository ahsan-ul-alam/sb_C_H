<?php

namespace App\Services;

use App\Models\AccountStatement;
use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\LoanReceivable;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ReceiptClassification;
use App\Models\ReceiptReview;
use App\Models\RevenueAdjustment;
use App\Models\RevenueEvent;
use App\Models\SourcingLotLine;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one canonical calculation behind the Central Business Dashboard. READ ONLY: it reads the existing records and services and never posts, classifies or
 * changes anything. The result is the owner's MANAGEMENT view; the statutory ledger, frozen allocations and the existing Profit & Loss stay as they are.
 *
 *   Net profit = verified earned revenue - management expenses
 *   Verified earned revenue = service receipts whose classification is APPLIED (less advances, plus recognitions, less revenue refunds)
 *                           + sourcing margin of lots that are financially SETTLED in the period and whose owner is stated (agreed client amount - company-borne landed cost)
 *   Management expenses = every verified expense that is not a loan, not a sourcing payment or lot cost (those live in the sourcing margin) and not a reversal,
 *                         INCLUDING purchases tagged equipment/asset (owner policy); tags are descriptive only. A cost is counted once, at its expense date;
 *                         no depreciation is ever added on top.
 *
 * Never revenue: partner capital, customer receipts that are not applied as service income, client money, loans, transfers, supplier advances.
 * Never an expense: supplier advances and payments for sourcing (they become margin cost only through their lot), loan principal, transfers, voided or
 * rejected entries. Receipts that are only proposed or suggested as service fees are reported separately as PROVISIONAL and never added to the verified result.
 * Each metric has its own confidence (verified | provisional | incomplete; cash: verified | unverified); an unresolved item downgrades only what it touches.
 */
class BusinessDashboard
{
    public const RANK = ['verified' => 0, 'provisional' => 1, 'incomplete' => 2];

    /**
     * @return array<string, mixed>
     */
    public static function build(?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $currency = (string) config('finance.currency');
        $start = $from ?? CarbonImmutable::create(2000, 1, 1);
        $receipts = self::receipts($start, $to, $currency);
        $sourcing = self::sourcing($start, $to);
        $expenses = self::managementExpenses($start, $to);
        $revenue = self::revenue($start, $to, $receipts, $sourcing);
        $investment = self::investment($to, $start);
        $cash = self::cash();
        $position = self::position($receipts, $currency);
        $fund = self::fundPosition($cash, $position, $currency, $to);

        $net = $revenue['verified'] - $expenses['total'];
        $funding = self::funding($start, $to, $receipts, $cash);
        $unresolved = self::unresolved($receipts, $sourcing, $expenses, $cash, $position, $investment, $currency, $start, $to);
        $status = [
            'revenue' => $receipts['unknown']['count'] > 0 ? 'incomplete' : (($receipts['provisional_service']['count'] + $receipts['sourcing_receipts']['unapplied_count'] > 0 || $sourcing['unresolved_count'] > 0) ? 'provisional' : 'verified'),
            'expenses' => $expenses['pending']['count'] > 0 ? 'provisional' : 'verified',
            'sourcing' => $sourcing['unresolved_count'] > 0 ? 'provisional' : 'verified',
            'investment' => $investment['pending']['count'] > 0 ? 'provisional' : 'verified',
            'cash' => $cash['status'],
            'receivables' => $position['unlinked']['count'] > 0 ? 'provisional' : 'verified',
        ];
        $overall = collect(['revenue', 'expenses', 'sourcing'])->map(fn ($k) => $status[$k])->sortByDesc(fn ($s) => self::RANK[$s])->first();

        return [
            'currency' => $currency, 'from' => $from?->toDateString(), 'to' => $to->toDateString(),
            'net_profit' => ['amount' => $net, 'status' => $overall,
                'if_suggested_service_accepted' => $net + $receipts['provisional_service']['amount'],
                'unrecognised_sourcing_estimate' => $sourcing['estimate_total']],
            'investment' => $investment, 'revenue' => $revenue, 'expenses' => $expenses, 'sourcing' => $sourcing, 'cash' => $cash, 'position' => $position, 'fund' => $fund, 'reconciliation' => CashReconciliation::snapshot(),
            'receipts' => $receipts, 'status' => $status, 'unresolved' => $unresolved, 'funding' => $funding, 'accounts' => self::accounts($start, $to), 'bridge' => self::bridge($revenue, $expenses, $funding, $receipts, $net),
            'chart' => self::chart($from, $to, $receipts['applied_service_rows'], $sourcing['recognised_rows']),
        ];
    }

    /**
     * Customer receipts of the period by what is known about their nature. Only an APPLIED classification is verified.
     *
     * @return array<string, mixed>
     */
    private static function receipts(CarbonImmutable $start, CarbonImmutable $to, string $currency): array
    {
        $applied = ReceiptClassification::pluck('nature', 'payment_id');
        $reviews = ReceiptReview::where('status', '!=', 'rejected')->pluck('proposed_nature', 'payment_id');
        $payments = Payment::query()->whereHas('project', fn ($q) => $q->where('currency', $currency))->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $to)->with('invoice.items')->get();
        $zero = fn () => ['amount' => 0, 'count' => 0, 'refs' => []];
        $out = ['total' => $zero(), 'verified_service' => $zero(), 'provisional_service' => $zero(), 'sourcing_receipts' => $zero() + ['unapplied_amount' => 0, 'unapplied_count' => 0], 'unknown' => $zero(), 'applied_service_rows' => [], 'payment_ids' => []];
        $add = function (string $key, Payment $p) use (&$out) {
            $out[$key]['amount'] += (int) $p->amount;
            $out[$key]['count']++;
            $out[$key]['refs'][] = 'payment '.$p->id;
        };
        $out['collections'] = array_fill_keys(['onboarding', 'marketing', 'product_sourcing', 'other'], ['amount' => 0, 'count' => 0, 'pending_amount' => 0, 'refs' => []]);
        foreach ($payments as $p) {
            $add('total', $p);
            $out['payment_ids'][] = $p->id;
            // Every collection is a funding inflow whatever its classification; the split below is descriptive and never makes it revenue.
            $nature = $applied[$p->id] ?? $reviews[$p->id] ?? ReceiptReviews::suggest($p)[0];
            $text = self::paymentText($p);
            $bucket = match (true) {
                $nature === 'product_sourcing' => 'product_sourcing',
                (bool) preg_match('/onboard|joining/i', $text) => 'onboarding',
                (bool) preg_match('/market|advert|\bads?\b/i', $text) => 'marketing',
                default => 'other',
            };
            $out['collections'][$bucket]['amount'] += (int) $p->amount;
            $out['collections'][$bucket]['count']++;
            $out['collections'][$bucket]['refs'][] = 'payment '.$p->id;
            $out['collections'][$bucket]['pending_amount'] += isset($applied[$p->id]) ? 0 : (int) $p->amount;
            if (isset($applied[$p->id])) {
                if ($applied[$p->id] === 'service') {
                    $add('verified_service', $p);
                    $out['applied_service_rows'][] = [$p->paid_on->toDateString(), (int) $p->amount];
                } else {
                    $add('sourcing_receipts', $p);
                }

                continue;
            }
            if ($nature === 'service') {
                $add('provisional_service', $p);
            } elseif ($nature === 'product_sourcing') {
                $add('sourcing_receipts', $p);
                $out['sourcing_receipts']['unapplied_amount'] += (int) $p->amount;
                $out['sourcing_receipts']['unapplied_count']++;
            } else {
                $add('unknown', $p);
            }
        }

        return $out;
    }

    /** What the invoice or segment the payment was recorded against says: the same evidence the receipt suggestion uses. */
    private static function paymentText(Payment $p): string
    {
        $text = collect($p->invoice?->items ?? [])->pluck('description')->push($p->invoice?->notes)->filter()->implode(' ');
        if ($text === '' && ($segmentId = DB::table('payment_allocations')->where('payment_id', $p->id)->value('segment_id'))) {
            $text = (string) DB::table('segments')->where('id', $segmentId)->value('name');
        }

        return $text;
    }

    /**
     * Sourcing margin per lot from the existing lot figures: agreed client amount minus the company-borne landed cost. Recognised only when the lot is
     * financially settled (its supplier advance settled and approved), in the settlement month, with a stated owner. Everything else is an estimate, not profit.
     *
     * @return array<string, mixed>
     */
    private static function sourcing(CarbonImmutable $start, CarbonImmutable $to): array
    {
        $recognised = 0;
        $estimate = 0;
        $lots = [];
        $unresolved = 0;
        $unknownOwner = [];
        $potential = 0;
        $potentialLots = [];
        foreach (ProjectSourcingReport::build()['lots'] as $row) {
            $lot = $row['lot'];
            $agreed = (int) collect($row['buckets'])->except('unallocated')->sum('agreed');
            $cost = (int) collect($row['buckets'])->except('unallocated')->sum('cost');
            $state = match (true) {
                $lot->ownership === null => 'ownership_unknown',
                $lot->status === 'settled' => 'recognised',
                $lot->status === 'completed' => 'completed_unsettled',
                default => 'open',
            };
            // Potential margin at the prices set on the lot (allocated or not), for any lot that is not yet earned. It is information only and is never added to profit.
            if ($lot->status !== 'settled' && (int) $row['figures']['revenue'] > 0) {
                $potential += (int) $row['figures']['profit'];
                $potentialLots[] = ['number' => $lot->number, 'margin' => (int) $row['figures']['profit'], 'state' => $state];
            }
            // A lot with no client agreement is company stock, not a sourcing result. An unknown owner is always unresolved, whatever is agreed.
            if ($agreed <= 0 && $state !== 'ownership_unknown') {
                continue;
            }
            $margin = $agreed - $cost;
            $when = ($lot->settled_at ?? $lot->updated_at)?->toDateString();
            $inPeriod = $state === 'recognised' && $when >= $start->toDateString() && $when <= $to->toDateString();
            if ($inPeriod) {
                $recognised += $margin;
            } elseif ($state !== 'recognised') {
                $estimate += $margin;
                $unresolved++;
            }
            if ($state === 'ownership_unknown') {
                $unknownOwner[] = $lot->number;
            }
            $lots[] = ['number' => $lot->number, 'state' => $state, 'agreed' => $agreed, 'cost' => $cost, 'margin' => $margin, 'counted' => $inPeriod, 'date' => $inPeriod ? $when : null];
        }

        return ['recognised' => $recognised, 'estimate_total' => $estimate, 'potential_total' => $potential, 'potential_lots' => $potentialLots, 'lots' => $lots, 'unresolved_count' => $unresolved, 'unknown_owner' => $unknownOwner,
            'recognised_rows' => collect($lots)->where('counted', true)->map(fn ($l) => [$l['date'], $l['margin']])->values()->all()];
    }

    /** @return array<string, mixed> */
    private static function managementExpenses(CarbonImmutable $start, CarbonImmutable $to): array
    {
        $accounting = FinancialStatements::usesAccountingBasis();
        $query = fn (bool $reversal, ?bool $asset = null) => Expense::query()->where('status', 'verified')->notReclassifiedAsLoan()->excludingSourcingAssets()
            ->where('category', 'not like', 'Sourcing:%')
            ->when($reversal, fn ($q) => $q->whereNotNull('reverses_id'), fn ($q) => $q->whereNull('reverses_id'))
            ->when($asset !== null, fn ($q) => $q->where('is_asset', $asset))
            ->when($accounting, fn ($q) => $q->where('classification', '!=', 'prepaid'))
            ->whereDate('spent_on', '>=', $start)->whereDate('spent_on', '<=', $to);
        $net = fn (?bool $asset) => (int) $query(false, $asset)->sum('amount') - (int) $query(true, $asset)->sum('amount');
        $assetTagged = $net(true);
        $other = $net(false);
        if ($accounting) {
            $released = PrepaidService::releasedBetween($start, $to);
            $other += $released['direct'] + $released['operating'];
        }
        $pending = Expense::where('status', 'pending')->whereDate('spent_on', '>=', $start)->whereDate('spent_on', '<=', $to);
        $projectAsset = $query(false, true)->whereNotNull('project_id');

        return ['total' => $assetTagged + $other, 'asset_tagged' => $assetTagged, 'other' => $other,
            'pending' => ['count' => (clone $pending)->count(), 'amount' => (int) (clone $pending)->sum('amount')],
            'project_asset' => ['count' => (clone $projectAsset)->count(), 'amount' => (int) (clone $projectAsset)->sum('amount'), 'refs' => (clone $projectAsset)->pluck('id')->map(fn ($i) => 'expense '.$i)->all()]];
    }

    /** @return array<string, mixed> */
    private static function revenue(CarbonImmutable $start, CarbonImmutable $to, array $receipts, array $sourcing): array
    {
        $cutover = AccrualRevenue::cutover();
        if ($cutover !== null && $start->toDateString() >= $cutover) {
            $service = (int) RevenueEvent::where('status', 'approved')->whereDate('effective_on', '>=', $start)->whereDate('effective_on', '<=', $to)->sum('revenue_delta');
            $basis = 'approved earned-revenue events';
        } else {
            $appliedIds = ReceiptClassification::where('nature', 'service')->pluck('payment_id');
            $advances = (int) RevenueAdjustment::where('kind', 'advance')->where('status', 'verified')->whereIn('payment_id', $appliedIds)
                ->whereHas('payment', fn ($q) => $q->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $to))->sum('amount');
            $adjust = fn (string $kind) => RevenueAdjustment::where('kind', $kind)->where('status', 'verified')->whereDate('effective_on', '>=', $start)->whereDate('effective_on', '<=', $to);
            $service = $receipts['verified_service']['amount'] - $advances + (int) $adjust('recognition')->sum('amount') - (int) $adjust('refund')->where('against', 'revenue')->sum('amount');
            $basis = 'service receipts with an applied classification';
        }

        return ['service' => $service, 'sourcing' => $sourcing['recognised'], 'verified' => $service + $sourcing['recognised'], 'provisional_service' => $receipts['provisional_service']['amount'], 'basis' => $basis];
    }

    /** @return array<string, mixed> */
    private static function investment(CarbonImmutable $to, CarbonImmutable $start): array
    {
        $entries = fn (string $kind) => CapitalEntry::where('kind', $kind)->where('status', 'verified')->whereDate('paid_on', '<=', $to);
        $opening = (int) DB::table('opening_lines as l')->join('opening_batches as b', 'b.id', '=', 'l.opening_batch_id')->where('b.status', 'posted')->where('l.kind', 'partner_capital')->sum('l.amount');
        $contributed = (int) $entries('contribution')->sum('amount') + $opening;
        $withdrawn = (int) $entries('withdrawal')->sum('amount');
        $pending = CapitalEntry::where('status', 'pending')->whereDate('paid_on', '<=', $to);

        return ['contributed' => $contributed, 'withdrawn' => $withdrawn, 'net' => $contributed - $withdrawn,
            'in_period' => (int) $entries('contribution')->whereDate('paid_on', '>=', $start)->sum('amount'),
            'pending' => ['count' => (clone $pending)->count(), 'amount' => (int) (clone $pending)->sum('amount')]];
    }

    /** @return array<string, mixed> */
    private static function cash(): array
    {
        $withStatement = AccountStatement::where('status', 'verified')->pluck('financial_account_id')->unique();
        $groups = ['cash' => 'Cash in Hand', 'mobile' => 'bKash / mobile banking', 'bank' => 'Bank'];
        $rows = [];
        $verifiedTotal = 0;
        $unassigned = 0;
        foreach (AccountingService::balances() as $b) {
            $a = $b['account'];
            if ($a === null) {
                $unassigned = $b['balance'];

                continue;
            }
            $verified = $a->is_active && $a->activation_status === 'active' && $withStatement->contains($a->id);
            $verifiedTotal += $verified ? $b['balance'] : 0;
            $rows[] = ['name' => $a->name, 'group' => $groups[$a->type] ?? 'Cash in Hand', 'balance' => $b['balance'], 'verified' => $verified];
        }
        $ledger = AccountingService::totalCash();
        $allVerified = $rows !== [] && collect($rows)->every(fn ($r) => $r['verified']) && $unassigned === 0;
        $clientFunds = Ledger::creditBalance('client.funds');

        return ['accounts' => $rows, 'verified_total' => $verifiedTotal, 'unassigned' => $unassigned, 'ledger_total' => $ledger, 'unverified_total' => $ledger - $verifiedTotal,
            'status' => $allVerified ? 'verified' : 'unverified', 'client_funds_in_ledger' => $clientFunds, 'company_cash_after_client_funds' => $verifiedTotal - $clientFunds];
    }

    /**
     * Where the money came from and where it went, from RECORDED cash movements only. It is a funding explanation, not profit and not verified cash:
     * partner investment and customer collections are inflows whatever their revenue classification; supplier payments, loans lent, equipment and
     * operating expenses are outflows; transfers between accounts are left out (they net to zero); collections are counted from the payment records
     * only, so a collection that is also journaled is never counted twice.
     *
     * @return array<string, mixed>
     */
    private static function funding(CarbonImmutable $start, CarbonImmutable $to, array $receipts, array $cash): array
    {
        $lines = JournalLine::query()->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', 'cash')
            ->whereDate('e.entry_date', '>=', $start)->whereDate('e.entry_date', '<=', $to)->where('e.source_type', 'not like', 'transfer%')
            ->get(['e.source_type as type', 'e.source_id as sid', 'journal_lines.debit as d', 'journal_lines.credit as c']);
        // A later payment of an already-recorded unpaid expense is the same cost, classified by its expense (it must not show as an unexplained "other" outflow).
        $paidExpense = ExpensePayment::whereIn('id', $lines->where('type', 'expense_payment')->pluck('sid')->unique())->pluck('expense_id', 'id');
        $expenseIds = $lines->where('type', 'expense')->pluck('sid')->merge($paidExpense->values())->unique();
        $expenses = Expense::whereIn('id', $expenseIds)->get(['id', 'category', 'is_asset'])->keyBy('id');
        $loans = DB::table('loan_receivables')->where('status', 'verified')->whereIn('expense_id', $expenseIds)->pluck('expense_id')->flip();
        $investment = 0;
        $otherIn = 0;
        $otherParts = [];
        $otherOutParts = [];
        $out = array_fill_keys(['operating', 'equipment', 'sourcing', 'loans', 'partner_withdrawals', 'other'], 0);
        foreach ($lines as $l) {
            $type = (string) $l->type;
            if ($type === 'capital') {
                $investment += (int) $l->d;
                $out['partner_withdrawals'] += (int) $l->c;
            } elseif ($type === 'payment' || str_starts_with($type, 'payment_v') || $type === 'revenue') {
                continue;   // customer money is counted from the payment records below
            } elseif ($type === 'expense' || ($type === 'expense_payment' && $paidExpense->has($l->sid))) {
                $expenseId = $type === 'expense' ? $l->sid : $paidExpense[$l->sid];
                $e = $expenses->get($expenseId);
                $class = match (true) {
                    $e === null => 'other',
                    isset($loans[$expenseId]) => 'loans',
                    str_starts_with((string) $e->category, 'Sourcing:') => 'sourcing',
                    (bool) $e->is_asset => 'equipment',
                    default => 'operating',
                };
                $out[$class] += (int) $l->c - (int) $l->d;   // a refund received reduces the outflow
            } else {
                $otherIn += (int) $l->d;
                $otherParts[$type] = ($otherParts[$type] ?? 0) + (int) $l->d;
                $out['other'] += (int) $l->c;
                if ((int) $l->c > 0) {
                    $otherOutParts[$type] = ($otherOutParts[$type] ?? 0) + (int) $l->c;
                }
            }
        }
        $collections = $receipts['total']['amount'];
        $posted = DB::table('journal_entries')->where(fn ($q) => $q->where('source_type', 'payment')->orWhere('source_type', 'like', 'payment\_v%'))->whereIn('source_id', $receipts['payment_ids'])->pluck('source_id');
        $notInLedger = (int) Payment::whereIn('id', $receipts['payment_ids'])->whereNotIn('id', $posted)->sum('amount');
        $totalIn = $investment + $collections + $otherIn;
        $totalOut = array_sum($out);

        return [
            'investment' => $investment, 'collections' => $collections, 'collection_split' => $receipts['collections'], 'other_inflows' => $otherIn, 'other_inflow_parts' => $otherParts,
            'total_in' => $totalIn, 'outflows' => $out, 'other_outflow_parts' => $otherOutParts, 'total_out' => $totalOut, 'remaining' => $totalIn - $totalOut,
            'ledger_net' => (int) $lines->sum('d') - (int) $lines->sum('c'),
            // Proof: expected closing cash = what the ledger holds for the period + collections recorded but not yet in it. Anything else is an unexplained difference.
            'proof_difference' => ($totalIn - $totalOut) - ((int) $lines->sum('d') - (int) $lines->sum('c') + $notInLedger),
            'collections_not_in_ledger' => $notInLedger, 'status' => ($cash['status'] === 'verified' && $notInLedger === 0) ? 'verified' : 'unverified',
        ];
    }

    /**
     * Per account: opening + inflows - outflows +/- transfers = expected closing, from the journal's cash lines, then the newest VERIFIED statement and the
     * variance to it. An account with no verified statement has no actual balance: it is shown as "not verified", never estimated. Cash that no entry
     * assigns to an account is its own row.
     *
     * @return list<array<string, mixed>>
     */
    private static function accounts(CarbonImmutable $start, CarbonImmutable $to): array
    {
        $lines = JournalLine::query()->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', 'cash')
            ->whereDate('e.entry_date', '<=', $to)->get(['journal_lines.financial_account_id as aid', 'e.entry_date as day', 'e.source_type as type', 'journal_lines.debit as d', 'journal_lines.credit as c'])
            ->groupBy(fn ($l) => $l->aid ?? 0);
        $statements = StatementReconciliationService::latestVerified()->keyBy('financial_account_id');
        $groups = ['cash' => 'Cash in Hand', 'petty' => 'Cash in Hand', 'mobile' => 'bKash / mobile banking', 'bank' => 'Bank'];
        $row = function (?FinancialAccount $a, string $name, string $group) use ($lines, $start, $statements) {
            $mine = $lines->get($a?->id ?? 0, collect());
            $before = $mine->filter(fn ($l) => substr((string) $l->day, 0, 10) < $start->toDateString());
            $within = $mine->filter(fn ($l) => substr((string) $l->day, 0, 10) >= $start->toDateString());
            $isTransfer = fn ($l) => str_starts_with((string) $l->type, 'transfer');
            $in = (int) $within->reject($isTransfer)->sum('d');
            $out = (int) $within->reject($isTransfer)->sum('c');
            $transfers = (int) $within->filter($isTransfer)->sum('d') - (int) $within->filter($isTransfer)->sum('c');
            $opening = (int) $before->sum('d') - (int) $before->sum('c');
            $statement = $a ? $statements->get($a->id) : null;
            $position = $statement ? StatementReconciliationService::position($statement) : null;

            return ['name' => $name, 'group' => $group, 'active' => $a ? ($a->is_active && $a->activation_status === 'active') : false, 'opening' => $opening, 'inflows' => $in, 'outflows' => $out,
                'transfers' => $transfers, 'expected' => $opening + $in - $out + $transfers,
                'actual' => $statement ? (int) $statement->statement_balance : null, 'actual_date' => $statement?->statement_date->toDateString(),
                'variance' => $position ? $position['statement'] - $position['ledger'] : null, 'unexplained' => $position['unexplained'] ?? null,
                'state' => $position === null ? 'not verified: a statement or count is needed' : ($position['unexplained'] === 0 ? 'verified and reconciled' : 'verified, difference to explain')];
        };
        $rows = FinancialAccount::orderByDesc('is_default')->orderBy('name')->get()->map(fn ($a) => $row($a, $a->name, $groups[$a->type] ?? 'Cash in Hand'))->all();
        $unassigned = $row(null, 'Not assigned to any account', 'Unassigned');
        if ($lines->has(0)) {
            $rows[] = $unassigned;
        }

        return $rows;
    }

    /**
     * Why the result and the money differ, as an exact walk from the net result to expected closing cash. The lines add up to the funding figure to the paisa
     * (the identity is asserted in tests), so nothing is left to guess.
     *
     * @return array{lines: list<array{label: string, amount: int}>, total: int}
     */
    private static function bridge(array $revenue, array $expenses, array $funding, array $receipts, int $net): array
    {
        $o = $funding['outflows'];
        $product = $receipts['collections']['product_sourcing']['amount'];
        $lines = [
            ['Net business result (revenue earned minus management expenses)', $net],
            ['+ Partner investment (capital, not income)', $funding['investment']],
            ['+ Client money received for products (not revenue)', $product],
            ['+ Other collections not counted as revenue (pending classification or earned later)', $funding['collections'] - $product - $revenue['service']],
            ['+ Other recorded inflows', $funding['other_inflows']],
            ['− Supplier and sourcing payments (stock or advances; cost only when the margin is earned)', -$o['sourcing']],
            ['− Loans lent (a receivable, not an expense)', -$o['loans']],
            ['− Partner withdrawals and other outflows', -($o['partner_withdrawals'] + $o['other'])],
            ['− Expenses paid in cash but outside the management-expense total', -($o['operating'] + $o['equipment'] - $expenses['total'])],
            ['− Sourcing margin already in the result (its cash is inside client money above)', -$revenue['sourcing']],
        ];
        $lines = array_map(fn ($l) => ['label' => $l[0], 'amount' => (int) $l[1]], $lines);

        return ['lines' => $lines, 'total' => (int) array_sum(array_column($lines, 'amount'))];
    }

    /**
     * Three different figures that must never be mixed up. Available cash is money in the accounts (the ledger's cash plus customer collections recorded
     * but not yet journaled). Cash + loans receivable adds money lent out and still to be repaid: a recoverable asset, NOT spendable cash. Total net assets
     * is every ledger asset less every liability (the same figure as the balance sheet) plus those unjournaled collections. A loan is none of operating
     * expense, loss, revenue or profit: lending moves cash into the receivable and a repayment moves it back, so neither changes profit.
     *
     * @return array<string, mixed>
     */
    private static function fundPosition(array $cash, array $position, string $currency, CarbonImmutable $to): array
    {
        $unjournaled = CashReconciliation::collectionsNotJournaled($currency, $to->toDateString());
        $available = $cash['ledger_total'] + $unjournaled;
        $balance = FinancialStatements::position();

        $loans = LoanReceivable::with('repayments')->where('status', 'verified')->orderBy('id')->get()->map(function (LoanReceivable $l) {
            $repaid = (int) $l->repayments->where('status', 'verified')->sum('amount');

            return ['borrower' => $l->borrower_name, 'lent_on' => $l->lent_on->toDateString(), 'principal' => $l->principal, 'repaid' => $repaid, 'outstanding' => $l->principal - $repaid,
                'status' => $repaid === 0 ? 'outstanding' : ($repaid >= $l->principal ? 'repaid' : 'partly repaid')];
        })->all();

        return ['available_cash' => $available, 'loans_receivable' => $position['loans_receivable'], 'cash_plus_receivables' => $available + $position['loans_receivable'],
            'total_net_assets' => $balance['assets'] - $balance['liabilities'] + $unjournaled, 'collections_not_journaled' => $unjournaled, 'loans' => $loans];
    }

    /** @return array<string, mixed> */
    private static function position(array $receipts, string $currency): array
    {
        $open = Invoice::query()->where('currency', $currency)->where('status', '!=', 'paid');
        $openByProject = (clone $open)->pluck('project_id')->unique();
        $unlinked = Payment::whereNull('invoice_id')->whereIn('project_id', $openByProject)->with('allocations')->get()
            ->filter(fn ($p) => ! DB::table('invoice_items')->whereIn('segment_id', $p->allocations->pluck('segment_id'))->exists());
        $short = SourcingLotLine::whereNotNull('received_quantity')->whereColumn('received_quantity', '<', 'quantity')
            ->whereHas('lot', fn ($q) => $q->whereHas('payments', fn ($p) => $p->whereNull('voided_at')))->get()
            ->sum(fn ($l) => Money::multiply(bcsub((string) $l->quantity, (string) $l->received_quantity, 2), (int) $l->unit_price));
        $unpaidCosts = DB::table('sourcing_lot_costs')->where('kind', 'actual')->whereNull('expense_id')->whereNotIn('id', DB::table('sourcing_lot_costs')->whereNotNull('replaces_id')->select('replaces_id'));

        return [
            'invoices_outstanding' => (int) (clone $open)->sum(DB::raw('total - amount_paid')), 'invoices_open' => (clone $open)->count(),
            'invoices_overdue' => (int) Invoice::where('currency', $currency)->overdue()->sum(DB::raw('total - amount_paid')),
            'supplier_advances' => Ledger::debitBalance('assets.supplier_advances') + Ledger::debitBalance('assets.supplier_receivable'), 'supplier_shortfall' => (int) $short,
            'payables' => FinancialReportingService::accountsPayable() + (int) (clone $unpaidCosts)->sum('amount'), 'unpaid_lot_costs' => (int) (clone $unpaidCosts)->sum('amount'),
            'loans_receivable' => Ledger::debitBalance('assets.loans_receivable'), 'loans_payable' => Ledger::creditBalance('loans.payable'),
            'client_funds_ledger' => Ledger::creditBalance('client.funds'), 'client_money_received' => $receipts['sourcing_receipts']['amount'],
            'unlinked' => ['count' => $unlinked->count(), 'amount' => (int) $unlinked->sum('amount'), 'refs' => $unlinked->map(fn ($p) => 'payment '.$p->id)->values()->all()],
        ];
    }

    /**
     * One compact list of what keeps a metric from being fully verified. Each line names what it affects and where to resolve it.
     *
     * @return list<array{label: string, detail: string, amount: ?int, affects: string, route: string}>
     */
    private static function unresolved(array $r, array $s, array $e, array $c, array $p, array $inv, string $currency, CarbonImmutable $start, CarbonImmutable $to): array
    {
        $items = [];
        $add = function (bool $when, string $label, string $detail, ?int $amount, string $affects, string $route) use (&$items) {
            if ($when) {
                $items[] = compact('label', 'detail', 'amount', 'affects', 'route');
            }
        };
        $unattributed = Payment::whereNull('financial_account_id')->whereHas('project', fn ($q) => $q->where('currency', $currency))->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $to);
        $add($r['provisional_service']['count'] > 0, 'Receipts suggested as service fees, not yet classified', $r['provisional_service']['count'].' receipt(s): '.implode(', ', $r['provisional_service']['refs']).'. Not counted in verified revenue.', $r['provisional_service']['amount'], 'Revenue', 'finance.historical');
        $add($r['sourcing_receipts']['unapplied_count'] > 0, 'Product-sourcing receipts not yet classified', $r['sourcing_receipts']['unapplied_count'].' receipt(s) treated as client money, never as revenue, until classified.', $r['sourcing_receipts']['unapplied_amount'], 'Revenue, client funds', 'finance.historical');
        $add($r['unknown']['count'] > 0, 'Receipts of unknown nature', implode(', ', $r['unknown']['refs']), $r['unknown']['amount'], 'Revenue', 'finance.historical');
        $add((clone $unattributed)->exists(), 'Receipts not attributed to a financial account', (clone $unattributed)->count().' receipt(s): cash, bKash or bank is not evidenced.', (int) (clone $unattributed)->sum('amount'), 'Cash', 'finance.historical');
        $add($c['status'] === 'unverified', 'Cash accounts not activated or verified', count($c['accounts']).' account(s) on record; unassigned ledger cash '.Money::format($c['unassigned']).'.', $c['unassigned'], 'Available cash', 'finance.accounts');
        $add($s['unknown_owner'] !== [], 'Lot owner not stated', implode(', ', $s['unknown_owner']).': margin and ownership-dependent valuation stay out.', null, 'Sourcing profit', 'sourcing.lots.index');
        $add($s['estimate_total'] !== 0, 'Sourcing margin not yet earned', $s['unresolved_count'].' lot(s) are not financially settled; their estimated margin is excluded.', $s['estimate_total'], 'Sourcing profit', 'sourcing.lots.index');
        $add($p['unlinked']['count'] > 0, 'Payment not linked to an invoice', implode(', ', $p['unlinked']['refs']).' sits beside an unpaid invoice.', $p['unlinked']['amount'], 'Receivables', 'payments.index');
        $add($p['supplier_shortfall'] > 0, 'Supplier shortfall (paid for, not received)', 'Needs the supplier\'s confirmation before it is a recoverable.', $p['supplier_shortfall'], 'Supplier advances', 'sourcing.advances');
        $add($p['unpaid_lot_costs'] > 0, 'Lot costs without a payable or payment', 'Included in payables; not yet in the lot margin as paid.', $p['unpaid_lot_costs'], 'Payables', 'sourcing.lots.index');
        $add($e['project_asset']['count'] > 0, 'Asset-tagged expense linked to a client project', implode(', ', $e['project_asset']['refs']).': counted in management expenses by policy; whether it is a client cost needs a decision.', $e['project_asset']['amount'], 'Expenses (classification only)', 'finance.expenses');
        $add($e['pending']['count'] > 0, 'Expenses awaiting verification', $e['pending']['count'].' expense(s) not yet counted.', $e['pending']['amount'], 'Expenses', 'finance.expenses');
        $add($inv['pending']['count'] > 0, 'Investment entries awaiting verification', $inv['pending']['count'].' entry(ies) not yet counted.', $inv['pending']['amount'], 'Investment', 'finance.capital');
        $other = Project::where('currency', '!=', $currency)->count();
        $add($other > 0, 'Projects in another currency', $other.' project(s) are excluded; currencies are never combined.', null, 'All metrics', 'projects.index');

        return $items;
    }

    /**
     * Revenue versus management expenses per week (up to 92 days) or month, both at their own recognition dates.
     *
     * @return array{unit: string, buckets: list<array{label: string, revenue: int, expenses: int}>, max: int}
     */
    private static function chart(?CarbonImmutable $requestedFrom, CarbonImmutable $to, array $serviceRows, array $sourcingRows): array
    {
        // "All time" starts at the first recorded payment or expense, not at the year 2000.
        $first = collect([Payment::min('paid_on'), Expense::where('status', 'verified')->min('spent_on')])->filter()->map(fn ($d) => CarbonImmutable::parse($d))->min();
        $from = $requestedFrom ?? ($first && $first->lte($to) ? $first : $to);
        $days = $from->diffInDays($to) + 1;
        $unit = $days <= 92 ? 'week' : 'month';
        $bucket = fn (string $date): string => $unit === 'week' ? CarbonImmutable::parse($date)->startOfWeek()->toDateString() : CarbonImmutable::parse($date)->startOfMonth()->toDateString();
        $buckets = [];
        for ($c = $unit === 'week' ? $from->startOfWeek() : $from->startOfMonth(); $c->lte($to); $c = $c->add(1, $unit)) {
            $buckets[$c->toDateString()] = ['label' => $unit === 'week' ? $c->format('j M') : $c->format('M y'), 'revenue' => 0, 'expenses' => 0];
        }
        foreach ([...$serviceRows, ...$sourcingRows] as [$date, $amount]) {
            if (isset($buckets[$k = $bucket($date)])) {
                $buckets[$k]['revenue'] += $amount;
            }
        }
        $expenseRows = Expense::query()->where('status', 'verified')->notReclassifiedAsLoan()->excludingSourcingAssets()->where('category', 'not like', 'Sourcing:%')
            ->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)->get(['spent_on', 'amount', 'reverses_id']);
        foreach ($expenseRows as $x) {
            if (isset($buckets[$k = $bucket($x->spent_on->toDateString())])) {
                $buckets[$k]['expenses'] += $x->reverses_id ? -(int) $x->amount : (int) $x->amount;
            }
        }
        $max = max([1, ...array_map(fn ($b) => max($b['revenue'], $b['expenses']), $buckets)]);

        return ['unit' => $unit, 'buckets' => array_values($buckets), 'max' => $max];
    }
}
