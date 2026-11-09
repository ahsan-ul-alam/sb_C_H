<?php

use App\Models\CapitalEntry;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Models\LoanRecoveryAllocation;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\LoanRecoveryAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| A repaid company loan can optionally be associated with an investor, for tracking only. Cash before the repayment is BDT 49,000 and the loan outstanding BDT 8,000;
| after full repayment cash is BDT 57,000. The allocation posts nothing and is never capital.
*/
beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->co->invest('A', '57000', '2026-11-05');
    $this->loanExpense = $this->co->expense('Loan', 'Loan for Ahsan', '8000', '2026-10-26');
    $this->ahsan = $this->co->partner('B');
    $this->investor = $this->co->partner('C');
});

afterEach(fn () => Carbon::setTestNow());

function lraLoan(): LoanReceivable
{
    test()->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => test()->loanExpense->id, 'borrower_partner_id' => test()->ahsan->id])->assertSessionHasNoErrors();
    $loan = LoanReceivable::latest('id')->firstOrFail();
    test()->co->post('board', 'finance.loans-receivable.decide', ['decision' => 'verify'], $loan)->assertSessionHasNoErrors();

    return $loan->refresh();
}

function lraRepay(LoanReceivable $loan, string $amount, bool $verify = true): LoanReceivableRepayment
{
    test()->co->post('finance', 'finance.loans-receivable.repay', ['amount' => $amount, 'repaid_on' => '2026-11-06', 'idempotency_key' => (string) Str::uuid()], $loan)->assertSessionHasNoErrors();
    $r = LoanReceivableRepayment::latest('id')->firstOrFail();
    if ($verify) {
        test()->co->post('board', 'finance.loans-receivable.repayments.decide', ['decision' => 'verify'], $r)->assertSessionHasNoErrors();
    }

    return $r->refresh();
}

function lraAllocate(LoanReceivableRepayment $r, string $amount, ?string $key = null, string $who = 'finance')
{
    return test()->co->post($who, 'finance.loans-receivable.allocations.store', ['partner_id' => test()->investor->id, 'amount' => $amount, 'reason' => 'Owner request: relates to this investor', 'reference' => 'OWNER-1', 'idempotency_key' => $key ?? (string) Str::uuid()], $r);
}

/** Everything an allocation must leave alone. @return array<string, int> */
function lraFigures(): array
{
    $rows = collect(FinancialStatements::capital()['rows']);

    return ['cash' => Ledger::debitBalance('cash'), 'receivable' => Ledger::debitBalance('assets.loans_receivable'), 'income' => Ledger::creditBalance('income'), 'journals' => JournalEntry::count(),
        'capital_entries' => CapitalEntry::count(), 'contributed' => (int) $rows->sum('contributed'), 'capital_total' => (int) FinancialStatements::capital()['total'], 'partner_capital' => Ledger::creditBalance('partner.capital')];
}

it('records a full repayment exactly once: cash 49,000 to 57,000, loan receivable 8,000 to 0, no revenue or capital', function () {
    $loan = lraLoan();
    expect(Ledger::debitBalance('cash'))->toBe(4_900_000)->and($loan->outstanding())->toBe(800_000)->and(Ledger::debitBalance('assets.loans_receivable'))->toBe(800_000);
    $r = lraRepay($loan, '8000');
    expect(Ledger::debitBalance('cash'))->toBe(5_700_000)->and($loan->refresh()->outstanding())->toBe(0)->and(Ledger::debitBalance('assets.loans_receivable'))->toBe(0)
        ->and(Ledger::creditBalance('income'))->toBe(0)->and(JournalEntry::where('source_type', 'loan_receivable_repayment')->count())->toBe(1)->and(CapitalEntry::where('status', 'verified')->sum('amount'))->toEqual(5_700_000)
        ->and($r->status)->toBe('verified');
});

it('allocates a verified repayment to an investor for tracking only: no journal, cash, receivable, income or capital changes', function () {
    $loan = lraLoan();
    $r = lraRepay($loan, '8000');
    $before = lraFigures();

    lraAllocate($r, '5000')->assertSessionHasNoErrors();
    $a = LoanRecoveryAllocation::firstOrFail();
    expect($a->status)->toBe('pending')->and($a->kind)->toBe('tracking')->and(lraFigures())->toBe($before);                   // proposing changes nothing
    $this->co->post('board', 'finance.loans-receivable.allocations.decide', ['decision' => 'verify'], $a)->assertSessionHasNoErrors();

    expect($a->refresh()->status)->toBe('verified')->and(lraFigures())->toBe($before)                                           // verifying changes nothing either
        ->and(Ledger::debitBalance('cash'))->toBe(5_700_000)->and(Ledger::debitBalance('assets.loans_receivable'))->toBe(0);    // cash 57,000, loan outstanding 0

    // Reported apart from capital.
    $row = collect(FinancialStatements::capital()['rows'])->firstWhere('partner.id', $this->investor->id);
    expect($row['loan_recovery_allocated'])->toBe(500_000)->and($row['contributed'])->toBe(0)->and(LoanRecoveryAllocationService::verifiedByPartner()->sum())->toBe(500_000);
    $this->co->as('finance')->get(route('finance.investment'))->assertOk()->assertSee('Loan recovery allocated (tracking only, not capital)')->assertSee('Capital contributed');
});

it('supports partial repayment and partial allocation, and refuses duplicate, oversized, unverified and double allocation', function () {
    $loan = lraLoan();
    $pending = lraRepay($loan, '1000', verify: false);
    lraAllocate($pending, '100')->assertSessionHas('error');                                                                    // an unverified repayment cannot be allocated
    $this->co->post('board', 'finance.loans-receivable.repayments.decide', ['decision' => 'verify'], $pending)->assertSessionHasNoErrors();
    $r = lraRepay($loan, '3000');                                                                                               // partial: 3,000 of the 8,000
    expect($loan->refresh()->outstanding())->toBe(400_000);

    lraAllocate($r, '3000.01')->assertSessionHas('error');                                                                      // larger than the repayment
    $key = (string) Str::uuid();
    lraAllocate($r, '2000', $key)->assertSessionHasNoErrors();
    lraAllocate($r, '2000', $key);                                                                                              // the same submission twice
    expect(LoanRecoveryAllocation::count())->toBe(1)->and(LoanRecoveryAllocationService::available($r))->toBe(100_000);       // pending ones reserve their share
    lraAllocate($r, '1000.01')->assertSessionHas('error');                                                                      // the same taka cannot be allocated twice
    lraAllocate($r, '1000')->assertSessionHasNoErrors();
    lraAllocate($r, '0.01')->assertSessionHas('error');
    expect(LoanRecoveryAllocation::whereIn('status', ['pending', 'verified'])->sum('amount'))->toEqual(300_000)->and(LoanRecoveryAllocationService::available($r))->toBe(0);
});

it('applies Finance proposes / Board verifies, independence, reversal instead of deletion, and blocks reversing an allocated repayment', function () {
    $loan = lraLoan();
    $r = lraRepay($loan, '8000');
    lraAllocate($r, '1000', null, 'board')->assertForbidden();                                                                  // the Board does not propose
    lraAllocate($r, '4000')->assertSessionHasNoErrors();
    $a = LoanRecoveryAllocation::firstOrFail();
    $this->co->post('finance', 'finance.loans-receivable.allocations.decide', ['decision' => 'verify'], $a)->assertForbidden(); // Finance cannot verify
    $both = SellbeesCompany::user('both@audit.test', 'finance', 'board_member');
    $own = LoanRecoveryAllocationService::propose($r, $this->investor, 100_000, 'self', null, null, $both);
    expect(fn () => LoanRecoveryAllocationService::decide($own, true, $both))->toThrow(RuntimeException::class, 'proposed');   // nobody verifies their own proposal
    $this->co->post('board', 'finance.loans-receivable.allocations.decide', ['decision' => 'reject', 'reason' => 'mistaken'], $own)->assertSessionHasNoErrors();   // a rejected one frees its amount
    expect(LoanRecoveryAllocationService::available($r))->toBe(400_000);
    $this->co->post('board', 'finance.loans-receivable.allocations.decide', ['decision' => 'verify'], $a)->assertSessionHasNoErrors();

    // The repayment cannot be reversed while it is allocated.
    $this->co->post('board2', 'finance.loans-receivable.repayments.reverse', ['reason' => 'x'], $r)->assertSessionHas('error');
    expect($r->refresh()->status)->toBe('verified');

    $this->co->post('board', 'finance.loans-receivable.allocations.reverse', ['reason' => 'Owner changed his mind'], $a)->assertSessionHasNoErrors();
    expect($a->refresh()->status)->toBe('reversed')->and($a->reversal_reason)->toBe('Owner changed his mind')->and(LoanRecoveryAllocationService::available($r))->toBe(800_000);
    $this->co->post('board', 'finance.loans-receivable.allocations.reverse', ['reason' => 'again'], $a)->assertSessionHas('error');   // reversed once only
    expect(fn () => $a->delete())->toThrow(LogicException::class)->and(LoanRecoveryAllocation::count())->toBe(2);               // records are never deleted
});

it('refuses to turn a recovered loan into paid-in capital and keeps a fresh investor contribution separate', function () {
    $loan = lraLoan();
    $r = lraRepay($loan, '8000');
    lraAllocate($r, '8000')->assertSessionHasNoErrors();
    $a = LoanRecoveryAllocation::firstOrFail();
    $this->co->post('board', 'finance.loans-receivable.allocations.decide', ['decision' => 'verify'], $a)->assertSessionHasNoErrors();
    $before = lraFigures();

    $this->co->post('finance', 'finance.loans-receivable.allocations.capital', [], $a)->assertSessionHas('error');
    expect(CapitalEntry::count())->toBe($before['capital_entries'])->and(lraFigures())->toBe($before);                           // nothing created, nothing posted

    // A genuine new investment goes through the normal investment workflow and moves capital and cash, not the tracking figure.
    $this->co->invest('C', '1000', '2026-11-07');
    $after = lraFigures();
    expect($after['contributed'])->toBe($before['contributed'] + 100_000)->and($after['cash'])->toBe($before['cash'] + 100_000)->and($after['capital_entries'])->toBe($before['capital_entries'] + 1)
        ->and(LoanRecoveryAllocationService::verifiedByPartner()->sum())->toBe(800_000)->and(collect(FinancialStatements::capital()['rows'])->firstWhere('partner.id', $this->investor->id)['contributed'])->toBe(100_000);
});

it('shows the four figures separately on the business dashboard', function () {
    $loan = lraLoan();
    $r = lraRepay($loan, '3000');
    lraAllocate($r, '3000')->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.loans-receivable.allocations.decide', ['decision' => 'verify'], LoanRecoveryAllocation::firstOrFail())->assertSessionHasNoErrors();

    $page = $this->co->as('board')->get(route('dashboard'))->assertOk();
    $page->assertSee('Verified investor capital contributions')->assertSee('Loan recoveries allocated to investors (tracking only, not capital, not cash)');
});
