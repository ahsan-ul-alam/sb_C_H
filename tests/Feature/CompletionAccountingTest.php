<?php

use App\Models\AccountingPeriod;
use App\Models\CashAllocationBatch;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\OtherIncome;
use App\Models\Payment;
use App\Models\PrepaidRelease;
use App\Models\ReconciliationReview;
use App\Models\RevenueAdjustment;
use App\Models\Segment;
use App\Services\AccountingService;
use App\Services\CashAllocationService;
use App\Services\FinancialReportingService;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\LedgerPostingService;
use App\Services\LegacyBackfill;
use App\Services\PeriodProcessor;
use App\Services\PrepaidService;
use App\Services\ProjectFinancialService;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Completion of the accounting system: historical data safety, cutover boundaries, opening cash allocation, loans, prepaid
| expenses, other income, refund accounts, shared-cost allocation, profit-basis consistency and period close. Driven through the
| real routes with real roles. "Today" is 2026-11-10 and the real-time cutover is 2026-11-01 unless a test says otherwise.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
});

afterEach(fn () => Carbon::setTestNow());

function cpMonth(string $month = '2026-11'): array
{
    $from = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

    return [$from, $from->endOfMonth()->startOfDay()];
}

function cpPl(string $month = '2026-11'): array
{
    return FinancialReportingService::profitAndLoss(...cpMonth($month));
}

function cpProject(string $contract = '100000'): array
{
    $co = test()->co;
    $project = $co->project($co->client('Client '.Str::random(4)), 'Project '.Str::random(4));

    return [$project, $co->segment($project, 'Build', $contract)];
}

function cpPay(Segment $segment, string $amount, string $date, ?int $accountId = null): Payment
{
    // Once accounts exist a customer payment names one (the default account unless the test chooses another).
    $accountId ??= FinancialAccount::where('is_default', true)->value('id');
    test()->co->post('finance', 'segments.payments.store', ['amount' => $amount, 'paid_on' => $date, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ...($accountId ? ['financial_account_id' => $accountId] : [])], $segment)->assertSessionHasNoErrors();

    return Payment::latest('id')->firstOrFail();
}

function cpExpense(array $data, string $by = 'finance'): Expense
{
    $co = test()->co;
    $co->post($by, 'finance.expenses.store', ['category' => 'General', 'description' => 'Expense '.Str::random(6), 'spent_on' => '2026-11-07', ...$data])->assertSessionHasNoErrors();
    $expense = Expense::latest('id')->firstOrFail();
    if ($expense->status === 'pending') {
        $co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors();
    }

    return $expense->refresh();
}

function cpAdjust(Payment $payment, array $data): RevenueAdjustment
{
    $co = test()->co;
    // A refund pays money out of a named account once accounts exist.
    if (($data['kind'] ?? null) === 'refund' && ! isset($data['financial_account_id']) && ($default = FinancialAccount::where('is_default', true)->value('id'))) {
        $data['financial_account_id'] = $default;
    }
    $co->post('finance', 'finance.revenue.store', $data, $payment)->assertSessionHasNoErrors();
    $adjustment = RevenueAdjustment::latest('id')->firstOrFail();
    $co->post('board', 'finance.revenue.decide', ['decision' => 'verify'], $adjustment)->assertSessionHasNoErrors();

    return $adjustment->refresh();
}

function cpSound(): void
{
    $audit = ledgerAudit();
    expect($audit['unbalanced'])->toBe(0)->and($audit['duplicates'])->toBe(0);
    expect(AccountingService::balances()->sum('balance'))->toBe(dr('cash'));
    expect(JournalLine::whereRaw('debit > 0 AND credit > 0')->count())->toBe(0);
}

// ---------------------------------------------------------------------------------------------------------------------------
// Historical data safety
// ---------------------------------------------------------------------------------------------------------------------------

it('back-fills only expenses still in the pre-migration shape, and a second run changes nothing', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    $legacy = cpExpense(['category' => 'Rent', 'description' => 'Old rent', 'amount' => '1000']);
    $asset = cpExpense(['category' => 'Equipment', 'description' => 'Old laptop', 'amount' => '5000', 'classification' => 'asset']);
    $unpaid = cpExpense(['category' => 'Supplies', 'description' => 'A genuinely unpaid bill', 'amount' => '700', 'payment_status' => 'unpaid']);
    // Put the first two back into the shape a pre-migration row has.
    DB::table('expenses')->whereIn('id', [$legacy->id, $asset->id])->update(['paid_amount' => 0, 'paid_on' => null, 'classification' => 'operating']);

    expect(LegacyBackfill::expenses())->toBeGreaterThan(0);
    expect($legacy->refresh()->paid_amount)->toBe(100_000)->and($legacy->paid_on->toDateString())->toBe($legacy->spent_on->toDateString())
        ->and($asset->refresh()->classification)->toBe('asset')
        ->and($unpaid->refresh()->paid_amount)->toBe(0)->and($unpaid->payment_status)->toBe('unpaid')->and($unpaid->classification)->toBe('operating');
    expect(LegacyBackfill::expenses())->toBe(0);
});

it('can have both new migrations run again without error or any change to data', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    [, $segment] = cpProject();
    cpPay($segment, '20000', '2026-11-06');
    $before = [JournalEntry::count(), JournalLine::count(), Payment::count(), Expense::count(), FinancialAccount::count(), (int) JournalLine::sum('debit')];

    foreach (glob(database_path('migrations/2026_11_0[34]_*.php')) as $file) {
        (require $file)->up();
        (require $file)->up();
    }

    expect([JournalEntry::count(), JournalLine::count(), Payment::count(), Expense::count(), FinancialAccount::count(), (int) JournalLine::sum('debit')])->toBe($before);
    cpSound();
});

it('treats the cutover boundary exactly: the day before is booked at close, the cutover day in real time', function () {
    [, $segment] = cpProject();
    $oct = cpPay($segment, '10000', '2026-10-31');
    $nov = cpPay($segment, '5000', '2026-11-01');

    expect(JournalEntry::where('source_type', 'payment')->where('source_id', $oct->id)->exists())->toBeFalse()
        ->and(JournalEntry::where('source_type', 'payment')->where('source_id', $nov->id)->exists())->toBeTrue()
        ->and(AccountingService::totalCash())->toBe(5_000_00);

    // Edit across the boundary in both directions through the real form.
    $this->co->as('finance')->put(route('payments.update', $oct), ['amount' => '10000', 'paid_on' => '2026-11-02', 'method' => 'cash'])->assertSessionHasNoErrors();
    expect(AccountingService::totalCash())->toBe(15_000_00);
    $this->co->as('finance')->put(route('payments.update', $nov), ['amount' => '5000', 'paid_on' => '2026-10-30', 'method' => 'cash'])->assertSessionHasNoErrors();
    expect(AccountingService::totalCash())->toBe(10_000_00)->and($nov->refresh()->posting_version)->toBe(2);
    expect(JournalEntry::where('source_type', 'payment_rev1')->where('source_id', $nov->id)->count())->toBe(1);
    cpSound();
});

it('never lets the cutover move to a month that would double count or to one already booked at close', function () {
    [, $segment] = cpProject();
    cpPay($segment, '4000', '2026-11-03');
    config(['finance.realtime_from' => '2026-12-01']);
    expect(LedgerPostingService::cutover()->format('Y-m'))->toBe('2026-11'); // sticky: November was already journaled in real time

    config(['finance.realtime_from' => null]);
    expect(LedgerPostingService::cutover()->format('Y-m'))->toBe('2026-11'); // even if the setting is removed
});

it('rejects a cutover earlier than the last month already booked at close and says so in the reconciliation', function () {
    config(['finance.realtime_from' => null]);
    Carbon::setTestNow('2026-10-20');
    [, $segment] = cpProject();
    cpPay($segment, '10000', '2026-10-15');
    Carbon::setTestNow('2026-11-10');
    PeriodProcessor::close('2026-10', $this->co->users['finance']);
    expect(JournalEntry::where('source_type', 'period')->count())->toBe(1);

    config(['finance.realtime_from' => '2026-09-01']);
    expect(LedgerPostingService::cutover()->format('Y-m'))->toBe('2026-11');
    $row = collect(ReconciliationService::report())->firstWhere('check', 'Configured real-time cutover month vs the month actually in force');
    expect($row['ok'])->toBeFalse();

    config(['finance.realtime_from' => '2026-11-01']);
    expect(collect(ReconciliationService::report())->firstWhere('check', 'Configured real-time cutover month vs the month actually in force')['ok'])->toBeTrue();
});

it('journals a post-cutover refund of a pre-cutover payment in real time and books a pre-cutover refund at close, once', function () {
    [$project, $segment] = cpProject();
    Carbon::setTestNow('2026-10-20');
    $oct = cpPay($segment, '10000', '2026-10-15');
    cpAdjust($oct, ['kind' => 'refund', 'against' => 'revenue', 'amount' => '1000', 'effective_on' => '2026-10-18', 'reason' => 'October refund']);
    Carbon::setTestNow('2026-11-10');
    $nov = cpPay($segment, '5000', '2026-11-06');
    cpAdjust($oct, ['kind' => 'refund', 'against' => 'revenue', 'amount' => '500', 'effective_on' => '2026-11-08', 'reason' => 'November refund of an October payment']);

    // Only the November events are in the ledger so far.
    expect(AccountingService::totalCash())->toBe(4_500_00)->and(Ledger::creditBalance('income'))->toBe(4_500_00);

    Carbon::setTestNow('2026-11-12');
    PeriodProcessor::close('2026-10', $this->co->users['finance']);
    expect(AccountingService::totalCash())->toBe(13_500_00)->and(Ledger::creditBalance('income'))->toBe(13_500_00);

    Carbon::setTestNow('2026-12-02');
    PeriodProcessor::close('2026-11', $this->co->users['finance']);
    expect(AccountingService::totalCash())->toBe(13_500_00) // the close of a real-time month adds nothing
        ->and(JournalEntry::where('source_type', 'period')->count())->toBe(1);
    expect(ProjectFinancialService::for($project)['recognised_revenue'])->toBe(13_500_00);
    cpSound();
});

it('refuses repeated submissions of loans, other income and opening allocations', function () {
    $this->co->post('finance', 'finance.loans.store', ['lender' => 'Test Bank', 'reference' => 'L-1'])->assertSessionHasNoErrors();
    $loan = Loan::firstOrFail();
    $key = (string) Str::uuid();
    $entry = ['kind' => 'receipt', 'amount' => '1000', 'entry_date' => '2026-11-05', 'idempotency_key' => $key];
    $this->co->post('finance', 'finance.loans.entries.store', $entry, $loan)->assertSessionHasNoErrors();
    $this->co->post('finance', 'finance.loans.entries.store', $entry, $loan)->assertSessionHas('error');
    expect($loan->entries()->count())->toBe(1);

    $income = ['description' => 'Bank interest', 'amount' => '200', 'received_on' => '2026-11-05', 'idempotency_key' => (string) Str::uuid()];
    $this->co->post('finance', 'finance.other-income.store', $income)->assertSessionHasNoErrors();
    $this->co->post('finance', 'finance.other-income.store', $income)->assertSessionHas('error');
    expect(OtherIncome::count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------------------------------------
// Opening cash allocation
// ---------------------------------------------------------------------------------------------------------------------------

it('allocates legacy cash to accounts only when the plan equals the verified balance, with a second approver, and creates no profit or capital', function () {
    Ledger::post('2026-10-15', 'legacy cash', 'test', 1, [['cash', null, 500_000, 0, null], ['equity.opening', null, 0, 500_000]], null);
    expect(AccountingService::balance(null))->toBe(500_000);
    $profitBefore = cpPl()['net_profit'];

    $this->co->post('finance', 'finance.cash-allocation.prepare', ['amounts' => [$this->cash->id => '3000', $this->bank->id => '1999.99']])->assertSessionHas('error');
    expect(CashAllocationBatch::count())->toBe(0);

    $this->co->post('finance', 'finance.cash-allocation.prepare', ['amounts' => [$this->cash->id => '3000', $this->bank->id => '2000'], 'note' => 'Statements 31 Oct'])->assertSessionHasNoErrors();
    $batch = CashAllocationBatch::firstOrFail();
    $this->co->post('finance', 'finance.cash-allocation.prepare', ['amounts' => [$this->cash->id => '5000']])->assertSessionHas('error'); // one at a time
    expect(fn () => CashAllocationService::approve($batch, $this->co->users['finance']))->toThrow(RuntimeException::class, 'cannot approve');
    $this->co->post('finance', 'finance.cash-allocation.approve', [], $batch)->assertForbidden();
    expect(AccountingService::balance(null))->toBe(500_000); // nothing moved yet

    $this->co->post('board', 'finance.cash-allocation.approve', [], $batch)->assertSessionHasNoErrors();
    expect(AccountingService::balance(null))->toBe(0)->and(AccountingService::balance($this->cash->id))->toBe(300_000)->and(AccountingService::balance($this->bank->id))->toBe(200_000)
        ->and(AccountingService::totalCash())->toBe(500_000)->and($batch->refresh()->status)->toBe('approved');
    expect(cpPl()['net_profit'])->toBe($profitBefore)->and(dr('income'))->toBe(0)->and(Ledger::creditBalance('partner.capital'))->toBe(0)->and(dr('expense'))->toBe(0);
    $this->co->post('board', 'finance.cash-allocation.approve', [], $batch)->assertSessionHas('error'); // not twice
    expect(AccountingService::totalCash())->toBe(500_000);
    cpSound();
});

it('refuses a stale allocation plan when the unassigned cash moved after it was prepared', function () {
    Ledger::post('2026-10-15', 'legacy cash', 'test', 1, [['cash', null, 500_000, 0, null], ['equity.opening', null, 0, 500_000]], null);
    $this->co->post('finance', 'finance.cash-allocation.prepare', ['amounts' => [$this->cash->id => '5000']])->assertSessionHasNoErrors();
    Ledger::post('2026-10-16', 'more legacy cash', 'test', 2, [['cash', null, 100_000, 0, null], ['equity.opening', null, 0, 100_000]], null);

    $row = collect(ReconciliationService::report())->firstWhere('check', 'Pending opening allocation vs current unassigned cash');
    expect($row['ok'])->toBeFalse();
    $this->co->post('board', 'finance.cash-allocation.approve', [], CashAllocationBatch::firstOrFail())->assertSessionHas('error');
    expect(AccountingService::balance(null))->toBe(600_000);
});

// ---------------------------------------------------------------------------------------------------------------------------
// Loans
// ---------------------------------------------------------------------------------------------------------------------------

it('keeps borrowed money out of profit and capital, and treats interest as a cost', function () {
    $this->co->post('finance', 'finance.loans.store', ['lender' => 'Test Bank', 'reference' => 'L-1'])->assertSessionHasNoErrors();
    $loan = Loan::firstOrFail();
    $this->co->post('finance', 'finance.loans.entries.store', ['kind' => 'receipt', 'amount' => '50000', 'entry_date' => '2026-11-05'], $loan)->assertSessionHasNoErrors();
    $receipt = $loan->entries()->firstOrFail();
    expect(AccountingService::totalCash())->toBe(0); // pending: nothing moved
    $this->co->post('finance', 'finance.loans.entries.decide', ['decision' => 'verify'], $receipt)->assertForbidden();
    $this->co->post('board', 'finance.loans.entries.decide', ['decision' => 'verify'], $receipt)->assertSessionHasNoErrors();

    expect(AccountingService::totalCash())->toBe(50_000_00)->and(Ledger::creditBalance('loans.payable'))->toBe(50_000_00)
        ->and(dr('income'))->toBe(0)->and(Ledger::creditBalance('partner.capital'))->toBe(0)->and(cpPl()['net_profit'])->toBe(0)
        ->and($loan->outstanding())->toBe(50_000_00)->and(FinancialStatements::position()['balanced'])->toBeTrue();

    // Principal repayment: reduces cash and the loan, never profit.
    $this->co->post('finance', 'finance.loans.entries.store', ['kind' => 'principal', 'amount' => '60000', 'entry_date' => '2026-11-08'], $loan)->assertSessionHas('error'); // more than owed
    $this->co->post('finance', 'finance.loans.entries.store', ['kind' => 'principal', 'amount' => '20000', 'entry_date' => '2026-11-08'], $loan)->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.loans.entries.decide', ['decision' => 'verify'], $loan->entries()->latest('id')->first())->assertSessionHasNoErrors();
    expect($loan->outstanding())->toBe(30_000_00)->and(AccountingService::totalCash())->toBe(30_000_00)->and(cpPl()['net_profit'])->toBe(0);

    // Interest: a cost that does reduce profit, through the normal expense verification.
    $this->co->post('finance', 'finance.loans.interest', ['amount' => '1500', 'spent_on' => '2026-11-09'], $loan)->assertSessionHasNoErrors();
    $interest = Expense::latest('id')->firstOrFail();
    expect($interest->category)->toBe('Loan interest')->and($interest->status)->toBe('pending');
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $interest)->assertSessionHasNoErrors();
    expect(cpPl()['other_expenses'])->toBe(1_500_00)->and(cpPl()['net_profit'])->toBe(-1_500_00)->and($loan->outstanding())->toBe(30_000_00)->and(AccountingService::totalCash())->toBe(28_500_00);

    // A receipt that repayments depend on cannot be reversed from under them.
    $this->co->post('board', 'finance.loans.entries.reverse', ['reason' => 'x'], $receipt)->assertSessionHas('error');
    expect(FinancialStatements::position()['balanced'])->toBeTrue();
    cpSound();
});

// ---------------------------------------------------------------------------------------------------------------------------
// Prepaid expenses
// ---------------------------------------------------------------------------------------------------------------------------

it('holds a prepaid expense as an asset and expenses it month by month, once each', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    $prepaid = cpExpense(['category' => 'Insurance', 'description' => 'Annual cover', 'amount' => '12000', 'spent_on' => '2026-11-01', 'classification' => 'prepaid', 'prepaid_months' => 12]);

    expect(Ledger::debitBalance('assets.prepaid'))->toBe(12_000_00)->and(AccountingService::totalCash())->toBe(88_000_00)->and(dr('expense'))->toBe(0)
        ->and(cpPl()['total_expenses'])->toBe(0);

    expect(PrepaidService::releaseDue('2026-11', null))->toBe(1)->and(PrepaidService::releaseDue('2026-11', null))->toBe(0);
    expect(dr('expense'))->toBe(1_000_00)->and(Ledger::debitBalance('assets.prepaid'))->toBe(11_000_00)->and(cpPl()['operating_expenses'])->toBe(1_000_00)
        ->and(AccountingService::totalCash())->toBe(88_000_00); // releasing never touches cash

    // A missed month is caught up in the next open one (cumulative), not lost and not doubled.
    expect(PrepaidService::releaseDue('2027-01', null))->toBe(1);
    expect(dr('expense'))->toBe(3_000_00)->and(Ledger::debitBalance('assets.prepaid'))->toBe(9_000_00)->and(PrepaidService::unreleased())->toBe(9_000_00);
    expect(collect(ReconciliationService::report())->firstWhere('check', 'Prepaid expenses: ledger asset vs paid-but-not-yet-expensed')['ok'])->toBeTrue();

    // Once part of it is expensed it cannot be reversed away.
    $this->co->post('finance', 'finance.expenses.reverse', ['type' => 'recording_error', 'reason' => 'oops'], $prepaid)->assertSessionHas('error');
    cpSound();
});

it('spreads the last month exactly so the whole prepaid amount is expensed and nothing more', function () {
    expect(PrepaidService::scheduled(10_000, 3, 1))->toBe(3_333)->and(PrepaidService::scheduled(10_000, 3, 2))->toBe(6_666)->and(PrepaidService::scheduled(10_000, 3, 3))->toBe(10_000)
        ->and(PrepaidService::scheduled(10_000, 3, 9))->toBe(10_000);
});

it('releases the month share automatically when the month is closed', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    cpExpense(['category' => 'Software', 'description' => 'Annual licence', 'amount' => '6000', 'spent_on' => '2026-11-01', 'classification' => 'prepaid', 'prepaid_months' => 6]);

    Carbon::setTestNow('2026-12-02');
    $period = PeriodProcessor::close('2026-11', $this->co->users['finance']);

    expect((int) PrepaidRelease::where('month', '2026-11')->sum('amount'))->toBe(1_000_00)
        ->and(dr('expense'))->toBe(1_000_00)->and(Ledger::debitBalance('assets.prepaid'))->toBe(5_000_00);
    // Legacy distributable basis: the prepaid cost counts in full when paid (exactly how every existing period behaves).
    expect($period->expenses)->toBe(6_000_00);
    cpSound();
});

// ---------------------------------------------------------------------------------------------------------------------------
// Other income, profit bases, tax memo
// ---------------------------------------------------------------------------------------------------------------------------

it('records other income as its own line that is accounting profit but not distributable profit unless the Board adopts the accounting basis', function () {
    $this->co->post('finance', 'finance.other-income.store', ['description' => 'Bank interest', 'amount' => '2000', 'received_on' => '2026-11-05'])->assertSessionHasNoErrors();
    $income = OtherIncome::firstOrFail();
    $this->co->post('finance', 'finance.other-income.decide', ['decision' => 'verify'], $income)->assertForbidden();
    $this->co->post('board', 'finance.other-income.decide', ['decision' => 'verify'], $income)->assertSessionHasNoErrors();

    expect(AccountingService::totalCash())->toBe(2_000_00)->and(Ledger::creditBalance('income.other'))->toBe(2_000_00)->and(dr('income'))->toBe(0)
        ->and(cpPl()['other_income'])->toBe(2_000_00)->and(cpPl()['net_profit'])->toBe(2_000_00)->and(cpPl()['recognised_revenue'])->toBe(0);

    // Distributable (legacy) basis ignores it; the accounting basis includes it.
    expect(FinancialStatements::monthFigures('2026-11')['income'])->toBe(0);
    config(['finance.distributable_basis' => 'accounting']);
    expect(FinancialStatements::monthFigures('2026-11')['income'])->toBe(2_000_00);
    config(['finance.distributable_basis' => 'legacy']);

    $bridge = FinancialReportingService::distributableBridge('2026-11');
    expect($bridge['accounting_profit'])->toBe(2_000_00)->and($bridge['distributable_basis_profit'])->toBe(0)->and(array_values($bridge['items'])[0])->toBe(2_000_00);
    cpSound();
});

it('never treats an owner investment or a loan as profit, and a closed month freezes the chosen basis', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    $this->co->post('finance', 'finance.other-income.store', ['description' => 'Grant', 'amount' => '1000', 'received_on' => '2026-11-04'])->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.other-income.decide', ['decision' => 'verify'], OtherIncome::firstOrFail())->assertSessionHasNoErrors();
    expect(cpPl()['net_profit'])->toBe(1_000_00);

    Carbon::setTestNow('2026-12-02');
    $period = PeriodProcessor::close('2026-11', $this->co->users['finance']);
    expect($period->income)->toBe(0)->and($period->net_profit)->toBe(0); // legacy basis: what is distributed is unchanged

    $row = collect(ReconciliationService::report())->firstWhere('check', 'Month 2026-11: frozen (distributable) profit vs accounting profit');
    expect($row)->not->toBeNull()->and($row['reason'])->toContain('Different accounting basis');
    expect(collect(ReconciliationService::report())->first(fn ($r) => str_contains($r['check'], 'ledger profit vs dashboard') && ! $r['ok']))->toBeNull();

    // Switching the basis affects only periods closed afterwards; the closed one stays frozen.
    config(['finance.distributable_basis' => 'accounting']);
    expect($period->refresh()->net_profit)->toBe(0)->and(AccountingPeriod::count())->toBe(1);
});

it('reports an estimated tax memo only when a rate is configured and never posts it', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    [, $segment] = cpProject();
    cpPay($segment, '20000', '2026-11-06');
    cpExpense(['category' => 'Rent', 'description' => 'Rent', 'amount' => '4000']);
    expect(cpPl()['estimated_tax'])->toBe(0);

    config(['finance.tax_rate_bps' => 2500]);
    expect(cpPl()['estimated_tax'])->toBe(4_000_00); // 25% of 16,000
    $entries = JournalEntry::count();
    cpPl();
    expect(JournalEntry::count())->toBe($entries);

    cpExpense(['category' => 'Tax', 'description' => 'Advance tax paid', 'amount' => '1000', 'classification' => 'tax']);
    expect(cpPl()['taxes'])->toBe(1_000_00)->and(cpPl()['net_profit'])->toBe(15_000_00)->and(cpPl()['estimated_tax'])->toBe(4_000_00);
});

// ---------------------------------------------------------------------------------------------------------------------------
// Refund account selection, shared-cost allocation
// ---------------------------------------------------------------------------------------------------------------------------

it('pays a refund from the account the user chooses and refuses one the account cannot cover', function () {
    [, $segment] = cpProject();
    $payment = cpPay($segment, '10000', '2026-11-06', $this->bank->id);
    expect(AccountingService::balance($this->bank->id))->toBe(10_000_00);

    cpAdjust($payment, ['kind' => 'refund', 'against' => 'revenue', 'amount' => '2000', 'effective_on' => '2026-11-08', 'financial_account_id' => $this->bank->id]);
    expect(AccountingService::balance($this->bank->id))->toBe(8_000_00)->and(AccountingService::balance($this->cash->id))->toBe(0);

    // The cash account holds nothing, so a refund drawn from it is refused when the Board verifies it.
    $this->co->post('finance', 'finance.revenue.store', ['kind' => 'refund', 'against' => 'revenue', 'amount' => '1000', 'effective_on' => '2026-11-09', 'financial_account_id' => $this->cash->id], $payment)->assertSessionHasNoErrors();
    $overdraw = RevenueAdjustment::latest('id')->firstOrFail();
    $this->co->post('board', 'finance.revenue.decide', ['decision' => 'verify'], $overdraw)->assertSessionHas('error');
    expect($overdraw->refresh()->status)->toBe('pending')->and(AccountingService::balance($this->cash->id))->toBe(0);

    // With no account chosen the default account is used.
    $this->co->post('finance', 'finance.transfers.store', ['from' => (string) $this->bank->id, 'to' => (string) $this->cash->id, 'amount' => '3000', 'date' => '2026-11-09'])->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.revenue.decide', ['decision' => 'verify'], $overdraw)->assertSessionHasNoErrors();
    expect(AccountingService::balance($this->cash->id))->toBe(2_000_00);
    cpSound();
});

it('splits a shared expense across projects only on request, for reporting only', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    [$p1, $s1] = cpProject('60000');
    [$p2] = cpProject('40000');
    cpPay($s1, '30000', '2026-11-06');
    $shared = cpExpense(['category' => 'Rent', 'description' => 'Shared rent', 'amount' => '9000']);
    $gross = ProjectFinancialService::for($p1)['gross_profit'];
    expect(ProjectFinancialService::for($p1)['allocated_shared_costs'])->toBe(0);

    $this->co->post('finance', 'finance.expenses.allocate', ['allocations' => [$p1->id => '9001']], $shared)->assertSessionHas('error'); // more than the expense
    $this->co->post('finance', 'finance.expenses.allocate', ['allocations' => [$p1->id => '6000', $p2->id => '3000']], $shared)->assertSessionHasNoErrors();
    expect(ProjectFinancialService::for($p1)['allocated_shared_costs'])->toBe(6_000_00)->and(ProjectFinancialService::for($p2)['allocated_shared_costs'])->toBe(3_000_00)
        ->and(ProjectFinancialService::for($p1)['gross_profit'])->toBe($gross); // never enters gross profit
    $entries = JournalEntry::count();

    $this->co->post('finance', 'finance.expenses.allocate', ['allocations' => [$p1->id => '1000', $p2->id => '']], $shared)->assertSessionHasNoErrors(); // replaces the split
    expect(ProjectFinancialService::for($p1)['allocated_shared_costs'])->toBe(1_000_00)->and(ProjectFinancialService::for($p2)['allocated_shared_costs'])->toBe(0)
        ->and(JournalEntry::count())->toBe($entries);

    $direct = cpExpense(['category' => 'Materials', 'description' => 'Direct', 'amount' => '500', 'classification' => 'direct', 'project_id' => $p1->id]);
    $this->co->post('finance', 'finance.expenses.allocate', ['allocations' => [$p2->id => '100']], $direct)->assertSessionHas('error');
    $this->co->post('auditor', 'finance.expenses.allocate', ['allocations' => [$p2->id => '100']], $shared)->assertForbidden();
    $this->co->as('finance')->get(route('finance.cost-allocation'))->assertOk()->assertSee('Shared rent');
});

// ---------------------------------------------------------------------------------------------------------------------------
// Consistency across the whole system
// ---------------------------------------------------------------------------------------------------------------------------

it('keeps the ledger, the dashboard and the project pages in agreement across every kind of transaction', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    [$project, $segment] = cpProject('80000');
    $payment = cpPay($segment, '40000', '2026-11-06');
    cpAdjust($payment, ['kind' => 'refund', 'against' => 'revenue', 'amount' => '2000', 'effective_on' => '2026-11-08']);
    cpExpense(['category' => 'Rent', 'description' => 'Rent', 'amount' => '5000']);
    cpExpense(['category' => 'Materials', 'description' => 'Direct', 'amount' => '3000', 'classification' => 'direct', 'project_id' => $project->id]);
    cpExpense(['category' => 'Supplies', 'description' => 'Unpaid bill', 'amount' => '1500', 'payment_status' => 'unpaid']);
    cpExpense(['category' => 'Equipment', 'description' => 'Laptop', 'amount' => '8000', 'classification' => 'asset']);
    cpExpense(['category' => 'Insurance', 'description' => 'Annual', 'amount' => '1200', 'classification' => 'prepaid', 'prepaid_months' => 12, 'spent_on' => '2026-11-01']);
    PrepaidService::releaseDue('2026-11', null);
    $this->co->post('finance', 'finance.other-income.store', ['description' => 'Interest', 'amount' => '300', 'received_on' => '2026-11-09'])->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.other-income.decide', ['decision' => 'verify'], OtherIncome::firstOrFail())->assertSessionHasNoErrors();
    $this->co->post('finance', 'finance.loans.store', ['lender' => 'Bank'])->assertSessionHasNoErrors();
    $loan = Loan::firstOrFail();
    $this->co->post('finance', 'finance.loans.entries.store', ['kind' => 'receipt', 'amount' => '10000', 'entry_date' => '2026-11-09'], $loan)->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.loans.entries.decide', ['decision' => 'verify'], $loan->entries()->firstOrFail())->assertSessionHasNoErrors();

    [$from, $to] = cpMonth();
    $report = FinancialReportingService::profitAndLoss($from, $to);
    $ledger = FinancialReportingService::ledgerProfitAndLoss($from, $to);
    // The journal and the dashboard are two independent calculations of the same month.
    expect($ledger['net'])->toBe($report['net_profit'])->and($ledger['revenue'])->toBe($report['recognised_revenue'])->and($ledger['other_income'])->toBe($report['other_income']);

    $kpis = FinancialReportingService::kpis($from, $to);
    expect($kpis['net_profit'])->toBe($report['net_profit'])->and($kpis['available_cash'])->toBe(dr('cash'))->and($kpis['loans_outstanding'])->toBe(10_000_00)
        ->and($kpis['accounts_payable'])->toBe(1_500_00)->and(FinancialStatements::position()['balanced'])->toBeTrue();
    // Project page against the company figure.
    expect(ProjectFinancialService::for($project)['recognised_revenue'])->toBe($report['recognised_revenue'])->and(ProjectFinancialService::for($project)['direct_costs'])->toBe($report['direct_costs']);
    // Nothing the reconciliation checks for the live month disagrees.
    $disagree = collect(ReconciliationService::report())->filter(fn ($r) => ! $r['ok'])->pluck('check')->all();
    expect($disagree)->toBe([]);
    cpSound();
});

it('renders every new screen, keeps their authorization, and records reviews of reconciliation findings', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    [, $segment] = cpProject();
    cpPay($segment, '1000', '2026-11-06');
    cpPay($segment, '1000', '2026-11-06'); // looks like a duplicate: a finding to review
    $unpaid = cpExpense(['category' => 'Supplies', 'description' => 'Bill', 'amount' => '500', 'payment_status' => 'unpaid']);
    $this->co->post('finance', 'finance.expenses.pay', ['amount' => '200', 'paid_on' => '2026-11-09'], $unpaid)->assertSessionHasNoErrors();

    foreach (['finance.accounts.unassigned', 'finance.expense-payments', 'finance.cost-allocation', 'finance.cash-allocation', 'finance.loans', 'finance.other-income', 'finance.reconciliation', 'finance.overview'] as $route) {
        $this->co->as('finance')->get(route($route))->assertOk();
        $this->co->as('auditor')->get(route($route))->assertOk();
        $this->co->as('employee')->get(route($route))->assertForbidden();
    }
    $history = $this->co->as('finance')->get(route('finance.accounts.show', $this->cash))->assertOk();
    expect($history->viewData('closing'))->toBe(dr('cash') - AccountingService::balance(null) - AccountingService::balance($this->bank->id))
        ->and($history->viewData('closing'))->toBe($history->viewData('current'))->and($history->viewData('rows'))->not->toBeEmpty();
    $this->co->as('finance')->get(route('finance.expense-payments', ['state' => 'live']))->assertOk()->assertSee('Bill');

    $finding = collect(ReconciliationService::report())->first(fn ($r) => ! $r['ok'] && str_contains($r['check'], 'Possible duplicate payment'));
    expect($finding)->not->toBeNull();
    $this->co->post('auditor', 'finance.reconciliation.review', ['key' => $finding['key'], 'status' => 'acknowledged', 'note' => 'x'])->assertForbidden();
    $this->co->post('finance', 'finance.reconciliation.review', ['key' => $finding['key'], 'status' => 'acknowledged', 'note' => 'Two genuine instalments, same day'])->assertSessionHasNoErrors();
    $row = collect(ReconciliationService::report())->firstWhere('key', $finding['key']);
    expect($row['review']->note)->toBe('Two genuine instalments, same day')->and($row['changed_since_review'])->toBeFalse()->and($row['ok'])->toBeFalse(); // a review never changes a figure
    $this->co->as('finance')->get(route('finance.reconciliation'))->assertSee('Two genuine instalments, same day');

    cpPay($segment, '1000', '2026-11-06'); // a third identical payment: the difference changes
    $row = collect(ReconciliationService::report())->first(fn ($r) => str_contains($r['check'], 'Possible duplicate payment'));
    expect($row['changed_since_review'])->toBeTrue();
    $this->co->post('finance', 'finance.reconciliation.review', ['key' => str_repeat('a', 32), 'status' => 'resolved', 'note' => 'x'])->assertSessionHas('error');
    expect(ReconciliationReview::count())->toBe(1);
});

it('shows accounting profit and distributable profit side by side without altering either', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    $this->co->post('finance', 'finance.other-income.store', ['description' => 'Interest', 'amount' => '500', 'received_on' => '2026-11-09'])->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.other-income.decide', ['decision' => 'verify'], OtherIncome::firstOrFail())->assertSessionHasNoErrors();
    $entries = JournalEntry::count();
    $periods = AccountingPeriod::count();

    $this->co->as('finance')->get(route('finance.overview'))->assertOk()->assertSee('Accounting profit vs distributable profit')->assertSee('Only the profit frozen at period close');
    expect(JournalEntry::count())->toBe($entries)->and(AccountingPeriod::count())->toBe($periods);
});
