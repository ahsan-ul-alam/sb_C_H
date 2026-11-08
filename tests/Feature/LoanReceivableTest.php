<?php

use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Services\AccountingService;
use App\Services\BusinessDashboard;
use App\Services\ExpenseService;
use App\Services\FinancialReportingService;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\LoanReceivableService;
use App\Services\PeriodProcessor;
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
| Historical correction: "Loan for Ahsan" (BDT 8,000, 2026-10-26) was recorded as an operating expense but is a recoverable loan.
| The correction is a non-cash reclassification (Dr loan receivable / Cr expense); the original disbursement is never touched or
| repeated. "Today" is 2026-11-10, October is still open and real-time posting starts 2026-11-01, as in production.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $this->co->invest('A', '100000', '2026-11-05');
    $this->loanExpense = $this->co->expense('Loan', 'Loan for Ahsan', '8000', '2026-10-26');
    $this->ahsan = $this->co->partner('B');
});

afterEach(fn () => Carbon::setTestNow());

function lrPropose(): LoanReceivable
{
    test()->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => test()->loanExpense->id, 'borrower_partner_id' => test()->ahsan->id, 'notes' => 'Approved by the Board'])->assertSessionHasNoErrors();

    return LoanReceivable::latest('id')->firstOrFail();
}

function lrVerified(): LoanReceivable
{
    $loan = lrPropose();
    test()->co->post('board', 'finance.loans-receivable.decide', ['decision' => 'verify'], $loan)->assertSessionHasNoErrors();

    return $loan->refresh();
}

function lrRepay(LoanReceivable $loan, string $amount, string $date = '2026-11-06', bool $verify = true, ?int $accountId = null): LoanReceivableRepayment
{
    test()->co->post('finance', 'finance.loans-receivable.repay', ['amount' => $amount, 'repaid_on' => $date, 'idempotency_key' => (string) Str::uuid(),
        ...($accountId ? ['financial_account_id' => $accountId] : [])], $loan)->assertSessionHasNoErrors();
    $repayment = LoanReceivableRepayment::latest('id')->firstOrFail();
    if ($verify) {
        test()->co->post('board', 'finance.loans-receivable.repayments.decide', ['decision' => 'verify'], $repayment)->assertSessionHasNoErrors();
    }

    return $repayment->refresh();
}

function lrPl(string $month = '2026-10'): array
{
    $from = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

    return FinancialReportingService::profitAndLoss($from, $from->endOfMonth()->startOfDay());
}

function lrRange(string $month = '2026-10'): array
{
    $from = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

    return [$from, $from->endOfMonth()->startOfDay()];
}

/** The journal as the original disbursement left it: used to prove the correction never touches it. */
function lrOriginalEntry(): array
{
    $entry = JournalEntry::where('source_type', 'expense')->where('source_id', test()->loanExpense->id)->firstOrFail();

    return ['entry' => $entry->only(['id', 'entry_date', 'memo', 'source_type', 'source_id', 'reversal_of']),
        'lines' => $entry->lines()->orderBy('id')->get()->map->only(['id', 'account', 'partner_id', 'debit', 'credit', 'financial_account_id'])->all()];
}

it('reclassifies the historical expense as a loan without touching the original entry or moving cash', function () {
    $originalBefore = lrOriginalEntry();
    $cashBefore = AccountingService::totalCash();
    $accountsBefore = [AccountingService::balance($this->cash->id), AccountingService::balance($this->bank->id), AccountingService::balance(null)];
    $cashCredits = (int) JournalLine::where('account', 'cash')->sum('credit');
    $expenseBefore = $this->loanExpense->fresh()->getAttributes();
    $entriesBefore = JournalEntry::count();

    $loan = lrVerified();

    // Exactly one new, non-cash, balanced entry: Dr loan receivable (Ahsan) / Cr expense.
    expect(JournalEntry::count())->toBe($entriesBefore + 1);
    $entry = JournalEntry::where('source_type', 'loan_receivable')->where('source_id', $loan->id)->firstOrFail();
    $lines = $entry->lines()->orderBy('id')->get();
    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('account')->all())->toBe(['assets.loans_receivable', 'expense'])
        ->and((int) $lines[0]->debit)->toBe((int) $this->loanExpense->amount)->and((int) $lines[0]->credit)->toBe(0)
        ->and((int) $lines[1]->credit)->toBe((int) $this->loanExpense->amount)->and((int) $lines[1]->debit)->toBe(0)
        ->and($lines->sum('debit'))->toBe($lines->sum('credit'))
        ->and($entry->entry_date->toDateString())->toBe('2026-10-26');

    // The original disbursement is exactly as it was and was not repeated; cash did not move.
    expect(lrOriginalEntry())->toEqual($originalBefore)
        ->and($this->loanExpense->fresh()->getAttributes())->toEqual($expenseBefore)
        ->and(JournalEntry::where('source_type', 'expense')->where('source_id', $this->loanExpense->id)->count())->toBe(1)
        ->and((int) JournalLine::where('account', 'cash')->sum('credit'))->toBe($cashCredits)
        ->and(AccountingService::totalCash())->toBe($cashBefore)
        ->and([AccountingService::balance($this->cash->id), AccountingService::balance($this->bank->id), AccountingService::balance(null)])->toBe($accountsBefore);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('links the receivable to Ahsan and to the source expense', function () {
    $loan = lrVerified();

    expect($loan->borrower_partner_id)->toBe($this->ahsan->id)->and($loan->borrower_name)->toBe($this->ahsan->name)
        ->and($loan->expense_id)->toBe($this->loanExpense->id)->and($loan->principal)->toBe((int) $this->loanExpense->amount)
        ->and($loan->lent_on->toDateString())->toBe('2026-10-26')->and($loan->outstanding())->toBe((int) $this->loanExpense->amount)
        ->and(dr('assets.loans_receivable', $this->ahsan->id))->toBe((int) $this->loanExpense->amount)
        ->and(dr('assets.loans_receivable'))->toBe($loan->outstanding());
    $page = $this->co->as('finance')->get(route('finance.loans-receivable'));
    $page->assertOk()->assertSee($this->ahsan->name);
});

it('lowers October expenses and raises profit by exactly the loan, and nothing else', function () {
    $octBefore = lrPl('2026-10');
    $novBefore = lrPl('2026-11');
    $figuresBefore = FinancialStatements::monthFigures('2026-10');
    [$from, $to] = lrRange('2026-10');
    $ledgerBefore = FinancialReportingService::ledgerProfitAndLoss($from, $to);
    $amount = (int) $this->loanExpense->amount;

    lrVerified();

    $octAfter = lrPl('2026-10');
    expect($octBefore['total_expenses'] - $octAfter['total_expenses'])->toBe($amount)
        ->and($octAfter['net_profit'] - $octBefore['net_profit'])->toBe($amount)
        ->and($octBefore['operating_expenses'] - $octAfter['operating_expenses'])->toBe($amount)
        ->and($figuresBefore['expenses'] - FinancialStatements::monthFigures('2026-10')['expenses'])->toBe($amount)
        ->and($octAfter['recognised_revenue'])->toBe($octBefore['recognised_revenue'])
        ->and(lrPl('2026-11'))->toEqual($novBefore);
    // The dashboard and the journal tell the same story.
    $ledgerAfter = FinancialReportingService::ledgerProfitAndLoss($from, $to);
    expect($ledgerBefore['expenses'] - $ledgerAfter['expenses'])->toBe($amount)->and($ledgerAfter['net'] - $ledgerBefore['net'])->toBe($amount);
    // Category breakdown and the monthly series drop it too.
    expect(FinancialReportingService::expenseCategories(...lrRange('2026-10'))->firstWhere('category', 'Loan'))->toBeNull()
        ->and(FinancialReportingService::monthlySeries(3)['expenses']['2026-10'])->toBe(0);
    expect(FinancialStatements::position()['balanced'])->toBeTrue()
        ->and(collect(FinancialStatements::position()['groups']['assets'])->firstWhere('label', 'Loans receivable (money lent, to be repaid)')['amount'])->toBe($amount);
});

it('changes no partner capital, income, closed period or allocation', function () {
    $co = $this->co;
    $project = $co->project($co->client('Sept Client'));
    $segment = $co->segment($project, 'Build', '100000');
    $co->paySegment($segment, '10000', '2026-09-15')->assertSessionHasNoErrors();
    PeriodProcessor::close('2026-09', $co->users['finance']);

    $capital = collect(['A', 'B', 'C'])->mapWithKeys(fn ($c) => [$c => Ledger::creditBalance('partner.capital', $co->partner($c)->id)])->all();
    $periods = AccountingPeriod::orderBy('id')->get()->toArray();
    $allocations = DB::table('allocations')->count();
    $income = dr('income');
    $otherIncome = dr('income.other');
    $original = lrOriginalEntry();

    $loan = lrVerified();

    expect(collect(['A', 'B', 'C'])->mapWithKeys(fn ($c) => [$c => Ledger::creditBalance('partner.capital', $co->partner($c)->id)])->all())->toEqual($capital)
        ->and(AccountingPeriod::orderBy('id')->get()->toArray())->toEqual($periods)->and(DB::table('allocations')->count())->toBe($allocations)
        ->and(dr('income'))->toBe($income)->and(dr('income.other'))->toBe($otherIncome)->and(lrOriginalEntry())->toEqual($original)
        ->and(dr('partner.capital', $this->ahsan->id))->toBe(-Ledger::creditBalance('partner.capital', $this->ahsan->id));
    // Neither the loan nor the reclassification is capital, drawings or distributable profit.
    expect(JournalEntry::where('source_type', 'loan_receivable')->first()->lines()->pluck('account')->all())->not->toContain('partner.capital', 'partner.payable', 'income', 'cash');
    expect($loan->status)->toBe('verified');
});

it('refuses to reclassify an expense of a closed month', function () {
    $co = $this->co;
    $old = $co->expense('Loan', 'Old loan', '500', '2026-09-12');
    $project = $co->project($co->client('Closed Client'));
    $co->paySegment($co->segment($project, 'Build', '100000'), '10000', '2026-09-15');
    PeriodProcessor::close('2026-09', $co->users['finance']);
    $periods = AccountingPeriod::orderBy('id')->get()->toArray();

    $co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $old->id, 'borrower_partner_id' => $this->ahsan->id])->assertSessionHas('error');

    expect(LoanReceivable::count())->toBe(0)->and(AccountingPeriod::orderBy('id')->get()->toArray())->toEqual($periods);
});

it('keeps the maker-checker and conflict-of-interest rules', function () {
    $loan = lrPropose();
    // Finance cannot verify; the proposer cannot verify their own proposal.
    $this->co->post('finance', 'finance.loans-receivable.decide', ['decision' => 'verify'], $loan)->assertForbidden();
    expect(fn () => LoanReceivableService::decide($loan, true, $this->co->users['finance']))->toThrow(RuntimeException::class, 'you proposed');
    // The borrower, even as a Board member, cannot approve their own loan or its repayment.
    $borrowerBoard = SellbeesCompany::user('ahsan-board@audit.test', 'board_member');
    $borrowerBoard->update(['partner_id' => $this->ahsan->id]);
    expect(fn () => LoanReceivableService::decide($loan, true, $borrowerBoard))->toThrow(RuntimeException::class, 'borrower');
    expect(LoanReceivable::first()->status)->toBe('pending')->and(JournalEntry::where('source_type', 'loan_receivable')->count())->toBe(0);

    $this->co->post('board', 'finance.loans-receivable.decide', ['decision' => 'verify'], $loan)->assertSessionHasNoErrors();
    $repayment = lrRepay($loan->refresh(), '1000', verify: false);
    expect(fn () => LoanReceivableService::decideRepayment($repayment, true, $borrowerBoard))->toThrow(RuntimeException::class, 'borrower');
    expect(fn () => LoanReceivableService::decideRepayment($repayment, true, $this->co->users['finance']))->toThrow(RuntimeException::class, 'recorded');
});

it('only reclassifies a verified, fully paid, non-capital expense', function () {
    $asset = $this->co->expense('Equipment', 'Sofa', '3000', '2026-11-02', asset: true);
    $this->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $asset->id, 'borrower_name' => 'X'])->assertSessionHas('error');
    $this->co->post('finance', 'finance.expenses.store', ['category' => 'Misc', 'description' => 'Pending one', 'amount' => '100', 'spent_on' => '2026-11-03'])->assertSessionHasNoErrors();
    $pending = Expense::latest('id')->firstOrFail();
    expect($pending->status)->toBe('pending');
    $this->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $pending->id, 'borrower_name' => 'X'])->assertSessionHas('error');
    $this->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $this->loanExpense->id])->assertSessionHas('error'); // no borrower named
    expect(LoanReceivable::count())->toBe(0);
});

it('is idempotent: repeating the proposal, the verification or the whole correction changes nothing more', function () {
    $first = lrPropose();
    $this->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $this->loanExpense->id, 'borrower_partner_id' => $this->ahsan->id])->assertSessionHasNoErrors();
    expect(LoanReceivable::count())->toBe(1)->and(LoanReceivable::first()->id)->toBe($first->id);

    $this->co->post('board', 'finance.loans-receivable.decide', ['decision' => 'verify'], $first)->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.loans-receivable.decide', ['decision' => 'verify'], $first)->assertSessionHas('error');
    $this->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $this->loanExpense->id, 'borrower_partner_id' => $this->ahsan->id])->assertSessionHasNoErrors();

    expect(LoanReceivable::count())->toBe(1)->and(JournalEntry::where('source_type', 'loan_receivable')->count())->toBe(1)
        ->and(dr('assets.loans_receivable'))->toBe((int) $this->loanExpense->amount)
        ->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('takes a partial and then a full repayment into the chosen account, never as revenue or profit', function () {
    $loan = lrVerified();
    $profit = lrPl('2026-11')['net_profit'];
    $cash = AccountingService::totalCash();
    $income = dr('income');

    $partial = lrRepay($loan, '3000', '2026-11-06', accountId: $this->bank->id);
    expect($loan->refresh()->outstanding())->toBe(5_000_00)->and(AccountingService::totalCash())->toBe($cash + 3_000_00)
        ->and(AccountingService::balance($this->bank->id))->toBe(3_000_00)
        ->and(dr('assets.loans_receivable', $this->ahsan->id))->toBe(5_000_00)
        ->and($partial->status)->toBe('verified');
    $repaymentEntry = JournalEntry::where('source_type', 'loan_receivable_repayment')->where('source_id', $partial->id)->firstOrFail();
    expect($repaymentEntry->lines()->where('account', 'cash')->value('financial_account_id'))->toBe($this->bank->id);

    // More than is owed is refused; the rest is accepted.
    $this->co->post('finance', 'finance.loans-receivable.repay', ['amount' => '5000.01', 'repaid_on' => '2026-11-07', 'idempotency_key' => (string) Str::uuid()], $loan)->assertSessionHas('error');
    lrRepay($loan, '5000', '2026-11-08');

    expect($loan->refresh()->outstanding())->toBe(0)->and(AccountingService::totalCash())->toBe($cash + 8_000_00)->and(dr('assets.loans_receivable'))->toBe(0)
        ->and(dr('income'))->toBe($income)->and(dr('income.other'))->toBe(0)->and(lrPl('2026-11')['net_profit'])->toBe($profit)
        ->and(lrPl('2026-11')['recognised_revenue'])->toBe(0);
    $this->co->post('finance', 'finance.loans-receivable.repay', ['amount' => '1', 'repaid_on' => '2026-11-09', 'idempotency_key' => (string) Str::uuid()], $loan)->assertSessionHas('error'); // fully repaid
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(FinancialStatements::position()['balanced'])->toBeTrue();
});

it('shows the loan separately in the dashboard fund position: lending and repayment move cash and receivable, never profit', function () {
    $build = fn () => BusinessDashboard::build(null, CarbonImmutable::parse('2026-11-10'));
    $before = $build();

    $loan = lrVerified();
    $lent = $build();
    $amount = (int) $this->loanExpense->amount;

    // Reclassified: the historical expense leaves management operating expenses once (no double count); cash is untouched; the receivable appears on its own.
    expect($lent['expenses']['total'])->toBe($before['expenses']['total'] - $amount)
        ->and($lent['fund']['available_cash'])->toBe($before['fund']['available_cash'])
        ->and($lent['fund']['loans_receivable'])->toBe($amount)
        ->and($lent['fund']['cash_plus_receivables'])->toBe($lent['fund']['available_cash'] + $amount)
        ->and($lent['fund']['total_net_assets'])->toBe($before['fund']['total_net_assets'] + $amount)
        ->and($lent['fund']['loans'][0])->toMatchArray(['borrower' => $this->ahsan->name, 'principal' => $amount, 'repaid' => 0, 'outstanding' => $amount, 'status' => 'outstanding']);

    // A partial repayment: cash up, receivable down by the same amount; cash + receivables, net assets, expenses and profit do not move.
    lrRepay($loan, '3000', '2026-11-06', accountId: $this->bank->id);
    $repaid = $build();
    expect($repaid['fund']['available_cash'])->toBe($lent['fund']['available_cash'] + 3_000_00)
        ->and($repaid['fund']['loans_receivable'])->toBe($amount - 3_000_00)
        ->and($repaid['fund']['cash_plus_receivables'])->toBe($lent['fund']['cash_plus_receivables'])
        ->and($repaid['fund']['total_net_assets'])->toBe($lent['fund']['total_net_assets'])
        ->and($repaid['expenses']['total'])->toBe($lent['expenses']['total'])->and($repaid['net_profit']['amount'])->toBe($lent['net_profit']['amount'])
        ->and($repaid['fund']['loans'][0])->toMatchArray(['repaid' => 3_000_00, 'outstanding' => 5_000_00, 'status' => 'partly repaid']);

    // A repayment that is only pending is not cash yet.
    lrRepay($loan, '1000', '2026-11-07', verify: false);
    $pending = $build();
    expect($pending['fund']['available_cash'])->toBe($repaid['fund']['available_cash'])->and($pending['fund']['loans_receivable'])->toBe($repaid['fund']['loans_receivable']);

    $page = $this->co->as('board')->get('/')->assertOk()->getContent();
    expect($page)->toContain('Fund / asset position')->toContain('Cash + loans receivable')->toContain('Total net assets');
});

it('prevents duplicate repayments, including pending ones that reserve the balance', function () {
    $loan = lrVerified();
    $key = (string) Str::uuid();
    $payload = ['amount' => '5000', 'repaid_on' => '2026-11-06', 'idempotency_key' => $key];

    $this->co->post('finance', 'finance.loans-receivable.repay', $payload, $loan)->assertSessionHasNoErrors();
    $this->co->post('finance', 'finance.loans-receivable.repay', $payload, $loan)->assertSessionHas('error'); // same submission twice
    expect(LoanReceivableRepayment::count())->toBe(1);

    // A second 5,000 would exceed what is still owed once the first is counted, even though the first is not verified yet.
    $this->co->post('finance', 'finance.loans-receivable.repay', [...$payload, 'idempotency_key' => (string) Str::uuid()], $loan)->assertSessionHas('error');
    expect(LoanReceivableRepayment::count())->toBe(1)->and($loan->refresh()->pendingRepayments())->toBe(5_000_00);

    // Verifying the same repayment twice posts it once.
    $repayment = LoanReceivableRepayment::firstOrFail();
    $this->co->post('board', 'finance.loans-receivable.repayments.decide', ['decision' => 'verify'], $repayment)->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.loans-receivable.repayments.decide', ['decision' => 'verify'], $repayment)->assertSessionHas('error');
    expect(JournalEntry::where('source_type', 'loan_receivable_repayment')->count())->toBe(1)->and($loan->refresh()->outstanding())->toBe(3_000_00);
});

it('corrects through reversing entries and keeps the audit trail', function () {
    $before = lrPl('2026-10');
    $loan = lrVerified();
    $repayment = lrRepay($loan, '2000');
    $cash = AccountingService::totalCash();
    $originalReclass = JournalEntry::where('source_type', 'loan_receivable')->firstOrFail();

    // The reclassification cannot be undone while a repayment stands.
    $this->co->post('board2', 'finance.loans-receivable.reverse', ['reason' => 'Wrong'], $loan)->assertSessionHas('error');
    expect($loan->refresh()->status)->toBe('verified');

    // Reverse the repayment: the money is owed again and cash goes back.
    $this->co->post('board', 'finance.loans-receivable.repayments.reverse', ['reason' => 'Bounced cheque'], $repayment)->assertSessionHasNoErrors();
    $reversal = JournalEntry::where('source_type', 'loan_receivable_repayment_rev')->where('source_id', $repayment->id)->firstOrFail();
    $original = JournalEntry::where('source_type', 'loan_receivable_repayment')->where('source_id', $repayment->id)->firstOrFail();
    expect($reversal->reversal_of)->toBe($original->id)->and($loan->refresh()->outstanding())->toBe(8_000_00)
        ->and(AccountingService::totalCash())->toBe($cash - 2_000_00)->and($repayment->refresh()->status)->toBe('reversed');
    $this->co->post('board', 'finance.loans-receivable.repayments.reverse', ['reason' => 'Again'], $repayment)->assertSessionHas('error');

    // Now the reclassification itself can be reversed by a different Board user; the expense counts again and nothing is deleted.
    $this->co->post('board2', 'finance.loans-receivable.reverse', ['reason' => 'Reclassified in error'], $loan)->assertSessionHasNoErrors();
    $rev = JournalEntry::where('source_type', 'loan_receivable_rev')->where('source_id', $loan->id)->firstOrFail();
    expect($rev->reversal_of)->toBe($originalReclass->id)->and($loan->refresh()->status)->toBe('reversed')->and(dr('assets.loans_receivable'))->toBe(0)
        ->and(lrPl('2026-10')['net_profit'])->toBe($before['net_profit'])->and(JournalEntry::where('source_type', 'loan_receivable')->count())->toBe(1);

    // A later correct proposal is possible again.
    $this->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $this->loanExpense->id, 'borrower_partner_id' => $this->ahsan->id])->assertSessionHasNoErrors();
    expect(LoanReceivable::count())->toBe(2);

    foreach (['loanrec.proposed', 'loanrec.verified', 'loanrec.repayment_recorded', 'loanrec.repayment_verified', 'loanrec.repayment_reversed', 'loanrec.reversed'] as $action) {
        expect(DB::table('audit_logs')->where('action', $action)->exists())->toBeTrue($action);
    }
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('stops the expense itself from being reversed while it is a loan', function () {
    lrVerified();

    expect(fn () => ExpenseService::reverse($this->loanExpense->fresh(), 'recording_error', 'oops', $this->co->users['finance']))
        ->toThrow(RuntimeException::class, 'reclassified as a loan receivable');
    expect(Expense::where('reverses_id', $this->loanExpense->id)->count())->toBe(0);
});

it('reconciles the receivable against the ledger and leaves the other checks alone', function () {
    $loan = lrVerified();
    lrRepay($loan, '3000');

    $rows = collect(ReconciliationService::report());
    $row = $rows->firstWhere('check', 'Loans receivable: ledger vs verified principal minus repayments');
    expect($row['ok'])->toBeTrue()->and($row['recorded'])->toBe(5_000_00)->and($row['recalculated'])->toBe(5_000_00)
        ->and($rows->firstWhere('check', 'Journal entries whose debits differ from credits')['ok'])->toBeTrue()
        ->and($rows->firstWhere('check', 'Source records posted more than once')['ok'])->toBeTrue();

    // A tampered ledger would be caught.
    Ledger::post('2026-11-09', 'Stray', 'test', 1, [['assets.loans_receivable', null, 100, 0], ['expense', null, 0, 100]], null);
    expect(collect(ReconciliationService::report())->firstWhere('check', 'Loans receivable: ledger vs verified principal minus repayments')['ok'])->toBeFalse();
});

it('can run its migration again without harm', function () {
    $loan = lrVerified();
    $migration = require database_path('migrations/2026_11_05_100000_add_loans_receivable.php');

    $migration->up();
    $migration->up();

    expect(LoanReceivable::count())->toBe(1)->and($loan->refresh()->status)->toBe('verified')->and(dr('assets.loans_receivable'))->toBe((int) $this->loanExpense->amount);
});
