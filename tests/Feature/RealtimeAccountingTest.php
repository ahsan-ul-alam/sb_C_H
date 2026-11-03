<?php

use App\Models\AccountTransfer;
use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RevenueAdjustment;
use App\Models\Segment;
use App\Services\AccountingService;
use App\Services\FinancialReportingService;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\PeriodProcessor;
use App\Services\ProjectFinancialService;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Real-time accounting: investments, customer payments, expenses (paid and unpaid), refunds, reversals, transfers and capital
| withdrawals, driven through the real HTTP routes with real roles. All amounts are BDT typed as a user would; assertions are
| in minor units (1 BDT = 100). The cutover is set to 2026-11-01 and "today" to 2026-11-10.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
});

afterEach(fn () => Carbon::setTestNow());

const BDT = 100;

function rtProject(string $contract = '100000'): array
{
    $co = test()->co;
    $project = $co->project($co->client('Client '.Str::random(4)), 'Project '.Str::random(4));
    $segment = $co->segment($project, 'Build', $contract);

    return [$project, $segment];
}

function rtPay(Segment $segment, string $amount, string $date = '2026-11-06', ?string $key = null): Payment
{
    test()->co->paySegment($segment, $amount, $date, $key)->assertSessionHasNoErrors();

    return Payment::latest('id')->firstOrFail();
}

/** Record an expense as Finance and have the Board verify it. */
function rtExpense(array $data): Expense
{
    $co = test()->co;
    $co->post('finance', 'finance.expenses.store', [
        'category' => 'General', 'description' => 'Expense '.Str::random(6), 'spent_on' => '2026-11-07', ...$data,
    ])->assertSessionHasNoErrors();
    $expense = Expense::latest('id')->firstOrFail();
    if ($expense->status === 'pending') {
        $co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors();
    }

    return $expense->refresh();
}

function rtAdjust(Payment $payment, array $data): RevenueAdjustment
{
    $co = test()->co;
    $co->post('finance', 'finance.revenue.store', $data, $payment)->assertSessionHasNoErrors();
    $adjustment = RevenueAdjustment::latest('id')->firstOrFail();
    $co->post('board', 'finance.revenue.decide', ['decision' => 'verify'], $adjustment)->assertSessionHasNoErrors();

    return $adjustment->refresh();
}

/** The invariants that must hold after any sequence of postings. */
function rtAssertLedgerSound(): void
{
    $audit = ledgerAudit();
    expect($audit['unbalanced'])->toBe(0)->and($audit['duplicates'])->toBe(0);
    // Total company cash is the sum of every account plus the unassigned legacy cash, and is exactly the ledger's cash.
    expect(AccountingService::balances()->sum('balance'))->toBe(dr('cash'))->and(AccountingService::totalCash())->toBe(dr('cash'));
    // Every journal line is a one-sided positive amount.
    expect(JournalLine::whereRaw('debit > 0 AND credit > 0')->count())->toBe(0);
}

it('books an owner investment to cash and capital but never to revenue or profit', function () {
    $this->co->invest('A', '100000', '2026-11-05');

    expect(AccountingService::balance($this->cash->id))->toBe(100_000 * BDT)
        ->and(Ledger::creditBalance('partner.capital'))->toBe(100_000 * BDT)
        ->and(dr('income'))->toBe(0)
        ->and(FinancialReportingService::capitalInvested())->toBe(100_000 * BDT);

    $pl = FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    expect($pl['recognised_revenue'])->toBe(0)->and($pl['net_profit'])->toBe(0);
    expect(collect(ReconciliationService::report())->firstWhere('check', 'Investment recorded as income')['ok'])->toBeTrue();
    rtAssertLedgerSound();
});

it('journals a project payment at once, recognises it as revenue once, and ignores a duplicate submit', function () {
    [$project, $segment] = rtProject();
    $key = (string) Str::uuid();
    $payment = rtPay($segment, '50000', '2026-11-06', $key);
    // The same form submitted again (refresh / retry) is told it was already recorded and posts nothing.
    $this->co->paySegment($segment, '50000', '2026-11-06', $key)->assertSessionHas('status', 'This payment was already recorded.');

    expect(Payment::count())->toBe(1)
        ->and(JournalEntry::where('source_type', 'payment')->count())->toBe(1)
        ->and(AccountingService::balance($this->cash->id))->toBe(50_000 * BDT)
        ->and(Ledger::creditBalance('income'))->toBe(50_000 * BDT);

    $summary = ProjectFinancialService::for($project);
    expect($summary['payments_received'])->toBe(50_000 * BDT)->and($summary['recognised_revenue'])->toBe(50_000 * BDT)
        ->and($summary['outstanding'])->toBe(50_000 * BDT)->and($summary['contract_value'])->toBe(100_000 * BDT);
    expect($payment->refresh()->posting_version)->toBe(1);
    rtAssertLedgerSound();
});

it('deducts a paid office expense from the account exactly once and counts it as operating', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    $expense = rtExpense(['category' => 'Rent', 'description' => 'Office rent', 'amount' => '10000']);

    expect($expense->status)->toBe('verified')->and($expense->payment_status)->toBe('paid')
        ->and(AccountingService::balance($this->cash->id))->toBe(90_000 * BDT)
        ->and(JournalEntry::where('source_type', 'expense')->where('source_id', $expense->id)->count())->toBe(1);

    $pl = FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    expect($pl['operating_expenses'])->toBe(10_000 * BDT)->and($pl['direct_costs'])->toBe(0)->and($pl['net_profit'])->toBe(-10_000 * BDT);
    rtAssertLedgerSound();
});

it('charges a project expense to its project profit and cash flow, not to other projects, and keeps profit separate from cash flow', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    [$project, $segment] = rtProject();
    [$other] = rtProject();
    rtPay($segment, '50000');
    rtExpense(['category' => 'Materials', 'description' => 'Project materials', 'amount' => '15000', 'classification' => 'direct', 'project_id' => $project->id]);
    rtExpense(['category' => 'Rent', 'description' => 'Shared rent', 'amount' => '10000']);

    $s = ProjectFinancialService::for($project);
    expect($s['direct_costs'])->toBe(15_000 * BDT)->and($s['gross_profit'])->toBe(35_000 * BDT)
        ->and($s['gross_margin_bps'])->toBe(7000)->and($s['cash_flow'])->toBe(35_000 * BDT)->and($s['unpaid_costs'])->toBe(0);
    expect(ProjectFinancialService::for($other)['direct_costs'])->toBe(0);

    $pl = FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    expect($pl['gross_profit'])->toBe(35_000 * BDT)->and($pl['net_profit'])->toBe(25_000 * BDT);
    // Profit (25,000) is not available cash: 100,000 invested + 50,000 collected − 15,000 − 10,000.
    expect(AccountingService::totalCash())->toBe(125_000 * BDT);
    rtAssertLedgerSound();
});

it('keeps an equipment purchase out of profit while still reducing cash', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    rtExpense(['category' => 'Equipment', 'description' => 'Laptop', 'amount' => '40000', 'classification' => 'asset']);

    $pl = FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    expect($pl['total_expenses'])->toBe(0)->and($pl['net_profit'])->toBe(0)
        ->and(AccountingService::totalCash())->toBe(60_000 * BDT)->and(dr('assets.equipment'))->toBe(40_000 * BDT);
    rtAssertLedgerSound();
});

it('treats a customer advance as a liability, not revenue, until it is recognised', function () {
    [$project, $segment] = rtProject();
    $payment = rtPay($segment, '20000');
    expect(ProjectFinancialService::for($project)['recognised_revenue'])->toBe(20_000 * BDT);

    rtAdjust($payment, ['kind' => 'advance']);
    $s = ProjectFinancialService::for($project);
    expect($s['recognised_revenue'])->toBe(0)->and($s['advances_held'])->toBe(20_000 * BDT)->and($s['payments_received'])->toBe(20_000 * BDT)
        ->and(AccountingService::totalCash())->toBe(20_000 * BDT)
        ->and(Ledger::creditBalance('customer.advances'))->toBe(20_000 * BDT)->and(Ledger::creditBalance('income'))->toBe(0);

    rtAdjust($payment, ['kind' => 'recognition', 'amount' => '8000', 'effective_on' => '2026-11-09']);
    $s = ProjectFinancialService::for($project);
    expect($s['recognised_revenue'])->toBe(8_000 * BDT)->and($s['advances_held'])->toBe(12_000 * BDT)
        ->and(Ledger::creditBalance('income'))->toBe(8_000 * BDT)->and(AccountingService::totalCash())->toBe(20_000 * BDT);
    rtAssertLedgerSound();
});

it('records an unpaid expense as a payable without touching cash, then pays it in parts exactly once', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    $expense = rtExpense(['category' => 'Supplies', 'description' => 'Vendor invoice', 'amount' => '25000', 'payment_status' => 'unpaid', 'vendor' => 'ABC Traders']);

    expect($expense->payment_status)->toBe('unpaid')->and(AccountingService::totalCash())->toBe(100_000 * BDT)
        ->and(Ledger::creditBalance('liabilities'))->toBe(25_000 * BDT)->and(FinancialReportingService::accountsPayable())->toBe(25_000 * BDT);
    // The cost is real the day it is verified, even though nothing was paid.
    expect(FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))['operating_expenses'])->toBe(25_000 * BDT);

    $key = (string) Str::uuid();
    $this->co->post('finance', 'finance.expenses.pay', ['amount' => '10000', 'paid_on' => '2026-11-09', 'idempotency_key' => $key], $expense)->assertSessionHasNoErrors();
    $this->co->post('finance', 'finance.expenses.pay', ['amount' => '10000', 'paid_on' => '2026-11-09', 'idempotency_key' => $key], $expense)->assertSessionHas('error');
    $expense->refresh();
    expect($expense->payment_status)->toBe('partial')->and($expense->outstanding())->toBe(15_000 * BDT)
        ->and(AccountingService::totalCash())->toBe(90_000 * BDT)->and(FinancialReportingService::accountsPayable())->toBe(15_000 * BDT);

    $this->co->post('finance', 'finance.expenses.pay', ['amount' => '16000', 'paid_on' => '2026-11-09'], $expense)->assertSessionHas('error'); // more than is owed
    $this->co->post('finance', 'finance.expenses.pay', ['amount' => '15000', 'paid_on' => '2026-11-09'], $expense)->assertSessionHasNoErrors();
    $expense->refresh();
    expect($expense->payment_status)->toBe('paid')->and($expense->outstanding())->toBe(0)
        ->and(AccountingService::totalCash())->toBe(75_000 * BDT)->and(Ledger::creditBalance('liabilities'))->toBe(0)
        ->and(FinancialReportingService::accountsPayable())->toBe(0);
    rtAssertLedgerSound();
});

it('refuses to pay an expense from an account that cannot cover it', function () {
    $this->co->invest('A', '5000', '2026-11-05');
    $expense = rtExpense(['category' => 'Supplies', 'description' => 'Big bill', 'amount' => '9000', 'payment_status' => 'unpaid']);

    $this->co->post('finance', 'finance.expenses.pay', ['amount' => '9000', 'paid_on' => '2026-11-09'], $expense)->assertSessionHas('error');
    expect($expense->refresh()->paid_amount)->toBe(0)->and(AccountingService::balance($this->cash->id))->toBe(5_000 * BDT);
});

it('refunds part of a payment out of revenue and the chosen account, reducing project profit and cash flow', function () {
    [$project, $segment] = rtProject();
    $payment = rtPay($segment, '50000');
    rtAdjust($payment, ['kind' => 'refund', 'against' => 'revenue', 'amount' => '10000', 'effective_on' => '2026-11-08', 'reason' => 'Scope reduced', 'financial_account_id' => $this->cash->id]);

    $s = ProjectFinancialService::for($project);
    expect($s['refunds'])->toBe(10_000 * BDT)->and($s['recognised_revenue'])->toBe(40_000 * BDT)->and($s['cash_flow'])->toBe(40_000 * BDT)
        ->and(AccountingService::balance($this->cash->id))->toBe(40_000 * BDT)->and(Ledger::creditBalance('income'))->toBe(40_000 * BDT);
    rtAssertLedgerSound();
});

it('reverses a paid expense with a Board-verified reversing entry, never by deleting it', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    $expense = rtExpense(['category' => 'Supplies', 'description' => 'Duplicate bill', 'amount' => '12000']);
    expect(AccountingService::totalCash())->toBe(88_000 * BDT);

    $this->co->post('finance', 'finance.expenses.reverse', ['type' => 'recording_error', 'reason' => 'Entered twice'], $expense)->assertSessionHasNoErrors();
    $reversal = Expense::latest('id')->first();
    expect($reversal->reverses_id)->toBe($expense->id)->and($reversal->status)->toBe('pending')->and(AccountingService::totalCash())->toBe(88_000 * BDT);
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $reversal)->assertSessionHasNoErrors();

    expect(AccountingService::totalCash())->toBe(100_000 * BDT)->and(dr('expense'))->toBe(0)
        ->and($expense->refresh()->payment_status)->toBe('reversed')->and(Expense::whereKey($expense->id)->exists())->toBeTrue();
    expect(FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))['total_expenses'])->toBe(0);
    // A second reversal of the same expense is refused.
    $this->co->post('finance', 'finance.expenses.reverse', ['type' => 'recording_error', 'reason' => 'again'], $expense)->assertSessionHas('error');
    rtAssertLedgerSound();
});

it('cancels an unpaid expense against its payable without moving cash', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    $expense = rtExpense(['category' => 'Supplies', 'description' => 'Wrong vendor bill', 'amount' => '7000', 'payment_status' => 'unpaid']);
    $this->co->post('finance', 'finance.expenses.reverse', ['reason' => 'Wrong vendor'], $expense)->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], Expense::latest('id')->first())->assertSessionHasNoErrors();

    expect(Ledger::creditBalance('liabilities'))->toBe(0)->and(AccountingService::totalCash())->toBe(100_000 * BDT)
        ->and(FinancialReportingService::accountsPayable())->toBe(0)->and(dr('expense'))->toBe(0);
    rtAssertLedgerSound();
});

it('transfers between cash and bank without changing total cash, and rejects an overdraft and a repeated request', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    $key = (string) Str::uuid();
    $data = ['from' => (string) $this->cash->id, 'to' => (string) $this->bank->id, 'amount' => '30000', 'date' => '2026-11-08', 'idempotency_key' => $key];
    $this->co->post('finance', 'finance.transfers.store', $data)->assertSessionHasNoErrors();
    $this->co->post('finance', 'finance.transfers.store', $data)->assertSessionHas('error'); // same key: already recorded

    expect(AccountingService::balance($this->cash->id))->toBe(70_000 * BDT)->and(AccountingService::balance($this->bank->id))->toBe(30_000 * BDT)
        ->and(AccountingService::totalCash())->toBe(100_000 * BDT)->and(dr('income'))->toBe(0)->and(dr('expense'))->toBe(0);

    $this->co->post('finance', 'finance.transfers.store', ['from' => (string) $this->bank->id, 'to' => (string) $this->cash->id, 'amount' => '30001', 'date' => '2026-11-08'])
        ->assertSessionHas('error');
    $this->co->post('finance', 'finance.transfers.store', ['from' => (string) $this->bank->id, 'to' => (string) $this->bank->id, 'amount' => '1', 'date' => '2026-11-08'])
        ->assertSessionHas('error');

    // Only the Board voids a transfer, by a reversing entry.
    $transfer = AccountTransfer::firstOrFail();
    $this->co->post('finance', 'finance.transfers.void', [], $transfer)->assertForbidden();
    $this->co->post('board', 'finance.transfers.void', [], $transfer)->assertSessionHasNoErrors();
    expect(AccountingService::balance($this->cash->id))->toBe(100_000 * BDT)->and(AccountingService::balance($this->bank->id))->toBe(0);
    rtAssertLedgerSound();
});

it('moves legacy unassigned cash into an account with a documented transfer', function () {
    // Cash posted before any account existed has no account; a transfer from "Unassigned" assigns it.
    $entry = Ledger::post('2026-10-15', 'legacy', 'test', 1, [['cash', null, 500_000, 0, null], ['equity.opening', null, 0, 500_000]], null);
    expect(AccountingService::balance(null))->toBe(500_000)->and($entry->id)->toBeInt();

    $this->co->post('finance', 'finance.transfers.store', ['from' => '', 'to' => (string) $this->bank->id, 'amount' => '3000', 'date' => '2026-11-08'])->assertSessionHasNoErrors();
    expect(AccountingService::balance(null))->toBe(200_000)->and(AccountingService::balance($this->bank->id))->toBe(300_000)->and(AccountingService::totalCash())->toBe(500_000);
    rtAssertLedgerSound();
});

it('lets an owner withdraw capital through Finance and Board without it becoming an expense, and caps it at the capital held', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    $partner = $this->co->partner('A');
    $this->co->post('finance', 'finance.capital.store', ['partner_id' => $partner->id, 'kind' => 'withdrawal', 'amount' => '30000', 'paid_on' => '2026-11-08', 'reference' => 'W1'])->assertSessionHasNoErrors();
    $entry = CapitalEntry::latest('id')->first();
    expect(AccountingService::totalCash())->toBe(100_000 * BDT); // pending: nothing moved yet
    $this->co->post('board', 'finance.capital.decide', ['decision' => 'verify'], $entry)->assertSessionHasNoErrors();

    expect(AccountingService::totalCash())->toBe(70_000 * BDT)->and(Ledger::creditBalance('partner.capital', $partner->id))->toBe(70_000 * BDT)
        ->and(dr('expense'))->toBe(0)->and(dr('income'))->toBe(0);
    $row = FinancialStatements::capital()['rows']->firstWhere(fn ($r) => $r['partner']->id === $partner->id);
    expect($row['contributed'])->toBe(100_000 * BDT)->and($row['withdrawn'])->toBe(30_000 * BDT)->and($row['loss_charged'])->toBe(0)->and($row['capital_balance'])->toBe(70_000 * BDT);
    expect(FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))['net_profit'])->toBe(0);

    // More than the partner holds is refused at verification.
    $this->co->post('finance', 'finance.capital.store', ['partner_id' => $partner->id, 'kind' => 'withdrawal', 'amount' => '70001', 'paid_on' => '2026-11-09', 'reference' => 'W2'])->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.capital.decide', ['decision' => 'verify'], CapitalEntry::latest('id')->first())->assertSessionHas('error');
    expect(AccountingService::totalCash())->toBe(70_000 * BDT);
    rtAssertLedgerSound();
});

it('reverses a verified capital entry with a reversing entry and keeps the capital figures clean', function () {
    $this->co->invest('A', '100000', '2026-11-05');
    $entry = CapitalEntry::latest('id')->first();
    $this->co->post('board', 'finance.capital.reverse', ['reason' => 'Entered against the wrong partner'], $entry)->assertSessionHasNoErrors();

    $row = FinancialStatements::capital()['rows']->firstWhere(fn ($r) => $r['partner']->id === $entry->partner_id);
    expect($entry->refresh()->status)->toBe('reversed')->and(AccountingService::totalCash())->toBe(0)
        ->and($row['contributed'])->toBe(0)->and($row['loss_charged'])->toBe(0)->and($row['capital_balance'])->toBe(0)
        ->and(FinancialReportingService::capitalInvested())->toBe(0);
    rtAssertLedgerSound();
});

it('reverses and re-posts an edited payment and reverses a voided one, keeping every entry balanced and nothing double counted', function () {
    [$project, $segment] = rtProject();
    $payment = rtPay($segment, '50000');

    $this->co->as('finance')->put(route('payments.update', $payment), ['amount' => '42000', 'paid_on' => '2026-11-06', 'method' => 'cash'])->assertSessionHasNoErrors();
    expect(AccountingService::totalCash())->toBe(42_000 * BDT)->and(Ledger::creditBalance('income'))->toBe(42_000 * BDT)
        ->and($payment->refresh()->posting_version)->toBe(2)
        ->and(JournalEntry::where('source_type', 'payment_rev1')->count())->toBe(1)->and(JournalEntry::where('source_type', 'payment_v2')->count())->toBe(1);

    // Saving the same figures again changes nothing.
    $this->co->as('finance')->put(route('payments.update', $payment), ['amount' => '42000', 'paid_on' => '2026-11-06', 'method' => 'cash'])->assertSessionHasNoErrors();
    expect(JournalEntry::count())->toBe(3); // the first posting, its reversal, and the corrected posting

    // Moving it into the bank re-books the cash side only.
    $this->co->as('finance')->put(route('payments.update', $payment), ['amount' => '42000', 'paid_on' => '2026-11-06', 'method' => 'cash', 'financial_account_id' => $this->bank->id])->assertSessionHasNoErrors();
    expect(AccountingService::balance($this->bank->id))->toBe(42_000 * BDT)->and(AccountingService::balance($this->cash->id))->toBe(0);

    $this->co->as('finance')->delete(route('payments.destroy', $payment))->assertSessionHasNoErrors();
    expect(Payment::count())->toBe(0)->and(AccountingService::totalCash())->toBe(0)->and(dr('income'))->toBe(0);
    expect(ProjectFinancialService::for($project)['payments_received'])->toBe(0);
    rtAssertLedgerSound();
});

it('never reverses the same entry twice', function () {
    $this->co->invest('A', '1000', '2026-11-05');
    $entry = JournalEntry::firstOrFail();
    Ledger::reverse($entry, '2026-11-06', 'undo', 'test_rev', 1, null);

    expect(fn () => Ledger::reverse($entry, '2026-11-06', 'undo again', 'test_rev', 2, null))->toThrow(RuntimeException::class, 'already been reversed');
    expect(fn () => Ledger::post('2026-11-06', 'unbalanced', 'test', 99, [['cash', null, 10, 0], ['income', null, 0, 9]], null))->toThrow(RuntimeException::class, 'Unbalanced');
    expect(fn () => Ledger::post('2026-11-06', 'again', $entry->source_type, $entry->source_id, [['cash', null, 10, 0], ['income', null, 0, 10]], null))->toThrow(RuntimeException::class, 'already been posted');
    rtAssertLedgerSound();
});

it('reconciles historical records: finds unposted payments, posts them once after a backup, and then agrees', function () {
    [$project, $segment] = rtProject();
    // Recorded while real-time posting was still off, in a month that is now inside the window.
    config(['finance.realtime_from' => null]);
    rtPay($segment, '30000', '2026-11-04');
    config(['finance.realtime_from' => '2026-11-01']);

    $find = fn () => collect(ReconciliationService::report())->firstWhere('check', 'Payments in the real-time window with no journal entry');
    expect($find()['ok'])->toBeFalse()->and($find()['recorded'])->toBe(1)->and(AccountingService::totalCash())->toBe(0);

    $backups = glob(storage_path('app/finance-backups/*.json')) ?: [];
    Artisan::call('finance:backfill-realtime'); // dry run
    expect(AccountingService::totalCash())->toBe(0);
    Artisan::call('finance:backfill-realtime', ['--apply' => true]);
    $created = array_diff(glob(storage_path('app/finance-backups/*.json')) ?: [], $backups);
    expect($created)->toHaveCount(1);
    $snapshot = json_decode(file_get_contents(array_values($created)[0]), true);
    expect($snapshot['payments'])->toHaveCount(1)->and($snapshot['journal_entries'])->toBe([]);
    array_map('unlink', $created);

    expect($find()['ok'])->toBeTrue()->and(AccountingService::totalCash())->toBe(30_000 * BDT)->and(Ledger::creditBalance('income'))->toBe(30_000 * BDT);
    Artisan::call('finance:backfill-realtime', ['--apply' => true]); // idempotent
    expect(JournalEntry::where('source_type', 'payment')->count())->toBe(1);
    foreach (glob(storage_path('app/finance-backups/*.json')) ?: [] as $file) {
        if (! in_array($file, $backups, true)) {
            unlink($file);
        }
    }
    rtAssertLedgerSound();
});

it('does not book a real-time month again at period close, and the close matches the live figures', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    [$project, $segment] = rtProject();
    rtPay($segment, '50000', '2026-11-06');
    rtExpense(['category' => 'Rent', 'description' => 'Office rent', 'amount' => '10000']);
    rtExpense(['category' => 'Equipment', 'description' => 'Laptop', 'amount' => '20000', 'classification' => 'asset']);
    $cash = dr('cash');
    $income = Ledger::creditBalance('income');

    Carbon::setTestNow('2026-12-02');
    $period = PeriodProcessor::close('2026-11', $this->co->users['finance']);

    expect(JournalEntry::where('source_type', 'period')->count())->toBe(0)
        ->and(dr('cash'))->toBe($cash)->and(Ledger::creditBalance('income'))->toBe($income)
        ->and($period->income)->toBe(50_000 * BDT)->and($period->expenses)->toBe(10_000 * BDT)->and($period->net_profit)->toBe(40_000 * BDT);

    $pl = FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    expect($pl['net_profit'])->toBe($period->net_profit)->and($pl['recognised_revenue'])->toBe($period->income);
    // Ledger P&L accounts agree with the report: income − expense.
    expect(Ledger::creditBalance('income') + dr('expense') * -1)->toBe($period->net_profit);
    rtAssertLedgerSound();
});

it('keeps the original close-time booking for months before the cutover', function () {
    config(['finance.realtime_from' => '2026-11-01']);
    Carbon::setTestNow('2026-10-20');
    [$project, $segment] = rtProject();
    rtPay($segment, '10000', '2026-10-15');
    expect(AccountingService::totalCash())->toBe(0); // October is before the cutover: booked at close, as before

    Carbon::setTestNow('2026-11-02');
    PeriodProcessor::close('2026-10', $this->co->users['finance']);
    expect(AccountingService::totalCash())->toBe(10_000 * BDT)->and(JournalEntry::where('source_type', 'period')->count())->toBe(1);
    rtAssertLedgerSound();
});

it('keeps dashboard, project and ledger figures in step across a mixed month', function () {
    $this->co->invest('A', '100000', '2026-11-02');
    [$p1, $s1] = rtProject('60000');
    [$p2, $s2] = rtProject('40000');
    rtPay($s1, '30000');
    rtPay($s2, '40000');
    rtExpense(['category' => 'Materials', 'description' => 'P1 materials', 'amount' => '5000', 'classification' => 'direct', 'project_id' => $p1->id]);
    rtExpense(['category' => 'Outsourcing', 'description' => 'P2 outsourcing', 'amount' => '12000', 'classification' => 'direct', 'project_id' => $p2->id, 'payment_status' => 'unpaid']);
    rtExpense(['category' => 'Internet', 'description' => 'ISP', 'amount' => '3000']);

    $from = CarbonImmutable::parse('2026-11-01');
    $to = CarbonImmutable::parse('2026-11-30');
    $k = FinancialReportingService::kpis($from, $to);
    $projects = ProjectFinancialService::summaries([$p1->id, $p2->id]);

    expect($k['capital_invested'])->toBe(100_000 * BDT)->and($k['contract_value'])->toBe(100_000 * BDT)
        ->and($k['payments_received'])->toBe(70_000 * BDT)->and($k['recognised_revenue'])->toBe(70_000 * BDT)
        ->and($k['outstanding_receivables'])->toBe(30_000 * BDT)->and($k['project_expenses'])->toBe(17_000 * BDT)
        ->and($k['operating_expenses'])->toBe(3_000 * BDT)->and($k['total_expenses'])->toBe(20_000 * BDT)
        ->and($k['gross_profit'])->toBe(53_000 * BDT)->and($k['net_profit'])->toBe(50_000 * BDT)
        ->and($k['accounts_payable'])->toBe(12_000 * BDT)
        ->and($k['available_cash'])->toBe(100_000 * BDT + 70_000 * BDT - 5_000 * BDT - 3_000 * BDT);
    // Card totals equal the sum of the per-project pages, and the live month figures used by period close.
    expect($projects->sum('recognised_revenue'))->toBe($k['recognised_revenue'])->and($projects->sum('direct_costs'))->toBe($k['project_expenses']);
    $month = FinancialStatements::monthFigures('2026-11');
    expect($month['income'] - $month['expenses'])->toBe($k['net_profit']);
    // Profit is not cash.
    expect($k['net_profit'])->not->toBe($k['available_cash']);

    $this->co->as('finance')->get(route('finance.overview'))->assertOk()->assertSee('Profit is not cash');
    $this->co->as('finance')->get(route('finance.accounts'))->assertOk();
    $this->co->as('finance')->get(route('finance.reconciliation'))->assertOk();
    $this->co->as('finance')->get(route('finance.reconciliation.csv'))->assertOk();
    $this->co->as('finance')->get(route('projects.show', $p1))->assertOk()->assertSee('Project financials');
    foreach (['today', 'this_week', 'this_month', 'this_year', 'custom'] as $range) {
        $this->co->as('finance')->get(route('finance.overview', ['range' => $range, 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk();
    }
    rtAssertLedgerSound();
});

it('lets only authorised roles manage accounts, transfers and payments of payables', function () {
    foreach (['employee', 'hr', 'clients'] as $who) {
        $this->co->post($who, 'finance.accounts.store', ['name' => 'X', 'type' => 'cash'])->assertForbidden();
    }
    $this->co->post('auditor', 'finance.transfers.store', ['amount' => '1', 'date' => '2026-11-08'])->assertForbidden();
    $this->co->post('finance', 'finance.accounts.store', ['name' => 'Mobile Wallet', 'type' => 'mobile'])->assertSessionHasNoErrors();
    expect(FinancialAccount::where('name', 'Mobile Wallet')->exists())->toBeTrue();
    // The auditor may read the overview and reconciliation but not change anything.
    $this->co->as('auditor')->get(route('finance.overview'))->assertOk();
    $this->co->as('auditor')->get(route('finance.reconciliation'))->assertOk();
    $expense = rtExpense(['category' => 'Supplies', 'description' => 'Bill', 'amount' => '100', 'payment_status' => 'unpaid']);
    $this->co->post('auditor', 'finance.expenses.reverse', ['reason' => 'x'], $expense)->assertForbidden();
    $this->co->post('auditor', 'finance.expenses.pay', ['amount' => '1', 'paid_on' => '2026-11-09'], $expense)->assertForbidden();
    $this->co->post('employee', 'finance.expenses.pay', ['amount' => '1', 'paid_on' => '2026-11-09'], $expense)->assertForbidden();
});

it('derives account balances only from the journal and rejects deactivating an account that still holds money', function () {
    $this->co->invest('A', '1000', '2026-11-05');
    $this->co->as('finance')->put(route('finance.accounts.update', $this->cash), ['name' => 'Office Cash', 'is_active' => 0, 'is_default' => 0])->assertSessionHas('error');
    expect($this->cash->refresh()->is_active)->toBeTrue();
    expect(Project::count())->toBe(0);
});
