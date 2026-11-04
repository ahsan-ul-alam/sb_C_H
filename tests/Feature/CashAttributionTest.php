<?php

use App\Models\AccountingPeriod;
use App\Models\CapitalEntry;
use App\Models\Document;
use App\Models\ExitSettlement;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\Resolution;
use App\Services\AccountingService;
use App\Services\ExpenseService;
use App\Services\ExpenseVerifier;
use App\Services\InvestmentService;
use App\Services\Ledger;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Phase 1c: cash attribution and period protection. Amounts are integer paisa. "Today" is 2026-11-10. The owner's BDT 1,000,000 (100,000,000
| paisa) is invested BEFORE any financial account exists, so it starts as the legacy "unassigned" cash, exactly as in the real database.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->co->invest('A', '1000000', '2026-11-02');
});

afterEach(fn () => Carbon::setTestNow());

/** Office Cash (default) 29,999,900, City Bank 70,000,000 and a nearly empty wallet, all funded from the legacy cash. */
function caAccounts(): array
{
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $tiny = FinancialAccount::factory()->create(['name' => 'Tiny wallet', 'type' => 'mobile']);
    foreach ([[$cash, 29_999_900], [$bank, 70_000_000], [$tiny, 100]] as [$account, $amount]) {
        AccountingService::transfer(['from' => null, 'to' => $account->id, 'amount' => $amount, 'date' => '2026-11-02', 'idempotency_key' => (string) Str::uuid()], test()->finance);
    }

    return [$cash, $bank, $tiny];
}

/** An approved payout of $amount for partner A, whose profit entitlement is credited straight in the journal. */
function caPayout(int $amount): Payout
{
    $partner = test()->co->partner('A');
    static $n = 0;
    Ledger::post('2026-11-05', 'Entitlement', 'test', ++$n, [['profit.pool', null, $amount, 0], ['partner.payable', $partner->id, 0, $amount]], test()->finance->id);

    return Payout::create(['partner_id' => $partner->id, 'amount' => $amount, 'requested_by' => test()->finance->id, 'status' => 'approved', 'approved_by' => test()->board->id]);
}

function caCashLines(string $sourceType, int $sourceId): array
{
    return JournalEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->firstOrFail()->lines->where('account', 'cash')
        ->map(fn ($l) => [(int) $l->debit, (int) $l->credit, $l->financial_account_id])->values()->all();
}

it('pays a payout from the account the payer names, and refuses one that is missing, inactive or too poor', function () {
    [$cash, $bank, $tiny] = caAccounts();
    $payout = caPayout(8_000_000);

    expect(fn () => PayoutService::pay($payout, $this->finance, null))->toThrow(RuntimeException::class, 'Choose the financial account')
        ->and(fn () => PayoutService::pay($payout->refresh(), $this->finance, $tiny->id))->toThrow(RuntimeException::class, 'Insufficient balance in "Tiny wallet"') // the company has the money; this account does not
        ->and($payout->refresh()->status)->toBe('approved')->and(JournalEntry::where('source_type', 'payout')->count())->toBe(0);

    $retired = FinancialAccount::factory()->create(['name' => 'Retired wallet']);
    $retired->update(['is_active' => false]);
    expect(fn () => PayoutService::pay($payout->refresh(), $this->finance, $retired->id))->toThrow(RuntimeException::class, 'inactive');
    $this->co->post('finance', 'finance.payouts.pay', ['financial_account_id' => $retired->id], $payout)->assertSessionHasErrors('financial_account_id');

    $bankBefore = AccountingService::balance($bank->id);
    $cashBefore = AccountingService::balance($cash->id);
    PayoutService::pay($payout->refresh(), $this->finance, $bank->id);

    expect($payout->refresh()->status)->toBe('paid')->and($payout->financial_account_id)->toBe($bank->id)
        ->and(caCashLines('payout', $payout->id))->toBe([[0, 8_000_000, $bank->id]])
        ->and(AccountingService::balance($bank->id))->toBe($bankBefore - 8_000_000)->and(AccountingService::balance($cash->id))->toBe($cashBefore)
        ->and(dr('partner.payable', $payout->partner_id))->toBe(0);
    // Paid once: a second attempt changes nothing.
    expect(fn () => PayoutService::pay($payout->refresh(), $this->finance, $bank->id))->toThrow(RuntimeException::class, 'approved payout')
        ->and(JournalEntry::where('source_type', 'payout')->count())->toBe(1)->and(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('still pays from the legacy cash while the company has no financial accounts', function () {
    $payout = caPayout(8_000_000);

    PayoutService::pay($payout, $this->finance, null);

    expect($payout->refresh()->status)->toBe('paid')->and($payout->financial_account_id)->toBeNull()->and(caCashLines('payout', $payout->id))->toBe([[0, 8_000_000, null]])
        ->and(AccountingService::totalCash())->toBe(100_000_000 - 8_000_000);
});

it('refuses a payout when the partner entitlement is not there, whichever account is chosen', function () {
    [, $bank] = caAccounts();
    $payout = caPayout(8_000_000);
    $payout->update(['amount' => 9_000_000]); // more than the entitlement credited

    expect(fn () => PayoutService::pay($payout->refresh(), $this->finance, $bank->id))->toThrow(RuntimeException::class, 'entitlement is lower')
        ->and(JournalEntry::where('source_type', 'payout')->count())->toBe(0);
});

it('pays an exit settlement from a named account with enough money, and never into a closed month', function () {
    [, $bank, $tiny] = caAccounts();
    $this->co->invest('B', '500000', '2026-11-03'); // the leaving partner's capital (lands in the default account)
    Storage::fake();
    $b = Partner::firstWhere('code', 'B');
    Resolution::create(['type' => 'exit_request', 'title' => 'Exit', 'partner_id' => $b->id, 'status' => 'approved', 'requested_by' => $this->finance->id]);
    $this->co->post('finance', 'finance.exits.store', ['partner_id' => $b->id, 'exit_date' => '2026-11-09'])->assertSessionHasNoErrors();
    $exit = ExitSettlement::firstOrFail();
    expect($exit->net_settlement)->toBeGreaterThan(100);
    $this->co->post('finance', 'finance.documents.store', ['subject_type' => 'exit', 'subject_id' => $exit->id, 'name' => 'Deed', 'file' => UploadedFile::fake()->create('deed.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.exits.approve', [], $exit)->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.documents.review', ['decision' => 'approve'], Document::firstOrFail())->assertSessionHasNoErrors();
    $paid = ['paid_on' => '2026-11-10', 'payment_reference' => 'TRX-1'];
    $entries = JournalEntry::count();

    $this->co->post('finance', 'finance.exits.pay', $paid, $exit)->assertSessionHas('error'); // no account named although accounts exist
    $this->co->post('finance', 'finance.exits.pay', [...$paid, 'financial_account_id' => $tiny->id], $exit)->assertSessionHas('error'); // the company has it, the wallet does not
    AccountingPeriod::create(['month' => '2026-10', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    $this->co->post('finance', 'finance.exits.pay', [...$paid, 'paid_on' => '2026-10-20', 'financial_account_id' => $bank->id], $exit)->assertSessionHas('error'); // back-dated into a closed month
    expect($exit->refresh()->payment_status)->toBe('unpaid')->and(JournalEntry::count())->toBe($entries);

    $bankBefore = AccountingService::balance($bank->id);
    $this->co->post('finance', 'finance.exits.pay', [...$paid, 'financial_account_id' => $bank->id], $exit)->assertSessionHas('status');

    expect($exit->refresh()->payment_status)->toBe('paid')->and($exit->financial_account_id)->toBe($bank->id)
        ->and(caCashLines('exit', $exit->id))->toBe([[0, (int) $exit->net_settlement, $bank->id]])
        ->and(AccountingService::balance($bank->id))->toBe($bankBefore - (int) $exit->net_settlement)
        ->and(ledgerAudit()['unbalanced'])->toBe(0);
});

it('does not post a transfer, capital entry or expense into a closed month, and undoes old entries in the open month', function () {
    [$cash, $bank] = caAccounts();
    // Recorded while October was open...
    $oldTransfer = AccountingService::transfer(['from' => $cash->id, 'to' => $bank->id, 'amount' => 1_000_000, 'date' => '2026-10-20', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    $this->co->post('finance', 'finance.capital.store', ['partner_id' => $this->co->partner('A')->id, 'amount' => '1000', 'paid_on' => '2026-10-26', 'reference' => 'LATE-1'])->assertSessionHasNoErrors();
    $capital = CapitalEntry::latest('id')->firstOrFail();
    $expense = ExpenseVerifier::record(['category' => 'Office', 'description' => 'Chairs', 'amount' => 150_000, 'spent_on' => '2026-10-27', 'financial_account_id' => $bank->id], $this->finance);
    // ...and then October is closed before the Board gets to them.
    AccountingPeriod::create(['month' => '2026-10', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    $entries = JournalEntry::count();
    $cashTotal = AccountingService::totalCash();

    expect(fn () => AccountingService::transfer(['from' => $cash->id, 'to' => $bank->id, 'amount' => 1_000, 'date' => '2026-10-25', 'idempotency_key' => (string) Str::uuid()], $this->finance))->toThrow(RuntimeException::class, 'already closed');
    $this->co->post('board', 'finance.capital.decide', ['decision' => 'verify'], $capital)->assertSessionHas('error');
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHas('error');
    expect($capital->refresh()->status)->toBe('pending')->and($expense->refresh()->status)->toBe('pending')->and(JournalEntry::count())->toBe($entries)->and(AccountingService::totalCash())->toBe($cashTotal);

    // Voiding the October transfer is allowed, but the reversal is dated today: October's entries stay exactly as they were.
    $original = JournalEntry::where('source_type', 'transfer')->where('source_id', $oldTransfer->id)->firstOrFail();
    AccountingService::voidTransfer($oldTransfer->refresh(), $this->finance);
    $reversal = JournalEntry::where('source_type', 'transfer_reversal')->where('source_id', $oldTransfer->id)->firstOrFail();
    expect($reversal->entry_date->toDateString())->toBe('2026-11-10')->and($reversal->reversal_of)->toBe($original->id)->and($original->fresh()->entry_date->toDateString())->toBe('2026-10-20')
        ->and(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);

    // A payment of an expense can be voided too, and its reversal also lands in the open month.
    $payable = ExpenseVerifier::record(['category' => 'Office', 'description' => 'Desk', 'amount' => 200_000, 'spent_on' => '2026-11-03', 'payment_status' => 'unpaid'], $this->finance);
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $payable)->assertSessionHasNoErrors();
    $payment = ExpenseService::pay($payable->refresh(), ['amount' => 200_000, 'paid_on' => '2026-11-04', 'financial_account_id' => $bank->id], $this->finance);
    AccountingPeriod::create(['month' => '2026-11', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    expect(fn () => ExpenseService::voidPayment($payment->refresh(), $this->finance))->toThrow(RuntimeException::class, 'already closed'); // today is in a closed month too
});

it('keeps the default account when an unrelated edit leaves its box unticked, and will not deactivate it', function () {
    [$cash, $bank] = caAccounts();
    $admin = ['name' => 'Office Cash (main)', 'is_active' => 1];

    $this->co->as('finance')->put(route('finance.accounts.update', $cash), [...$admin, 'allow_negative' => 0])->assertSessionHasNoErrors();
    expect($cash->refresh()->is_default)->toBeTrue()->and($cash->name)->toBe('Office Cash (main)')->and(AccountingService::defaultAccountId())->toBe($cash->id);

    // It cannot be deactivated while it is the default (here it holds no money, so only this rule stands in the way).
    $empty = FinancialAccount::factory()->asDefault()->create(['name' => 'Empty default']);
    $cash->update(['is_default' => false]);
    $this->co->as('finance')->put(route('finance.accounts.update', $empty), ['name' => 'Empty default'])->assertSessionHas('error');
    expect($empty->refresh()->is_active)->toBeTrue()->and($empty->is_default)->toBeTrue();

    // The default is handed over by making another account the default.
    $this->co->as('finance')->put(route('finance.accounts.update', $bank), ['name' => 'City Bank', 'is_active' => 1, 'is_default' => 1])->assertSessionHasNoErrors();
    expect($bank->refresh()->is_default)->toBeTrue()->and($empty->refresh()->is_default)->toBeFalse()->and(FinancialAccount::where('is_default', true)->count())->toBe(1);
});

it('does not let a capital reversal and a withdrawal both rely on the same capital', function () {
    [$cash] = caAccounts();
    $a = $this->co->partner('A');
    $contribution = CapitalEntry::where('partner_id', $a->id)->where('kind', 'contribution')->firstOrFail();

    // Withdraw 60% of the capital: the contribution can no longer be reversed in full.
    $this->co->post('finance', 'finance.capital.store', ['partner_id' => $a->id, 'kind' => 'withdrawal', 'amount' => '600000', 'paid_on' => '2026-11-06', 'reference' => 'WD-1', 'financial_account_id' => $cash->id])->assertSessionHasNoErrors();
    $withdrawal = CapitalEntry::latest('id')->firstOrFail();
    $this->co->post('board', 'finance.capital.decide', ['decision' => 'verify'], $withdrawal)->assertSessionHas('error'); // Office Cash holds 29,999,900 of the 60,000,000 asked
    $this->co->post('finance', 'finance.capital.store', ['partner_id' => $a->id, 'kind' => 'withdrawal', 'amount' => '250000', 'paid_on' => '2026-11-06', 'reference' => 'WD-2', 'financial_account_id' => $cash->id])->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.capital.decide', ['decision' => 'verify'], CapitalEntry::latest('id')->firstOrFail())->assertSessionHasNoErrors();
    expect(InvestmentService::capitalBalance($a->id))->toBe(100_000_000 - 25_000_000);

    expect(fn () => InvestmentService::reverse($contribution->refresh(), 'Wrong partner', $this->board))->toThrow(RuntimeException::class, 'no longer holds this much capital')
        ->and(InvestmentService::capitalBalance($a->id))->toBe(75_000_000)->and(JournalEntry::where('source_type', 'capital_reversal')->count())->toBe(0);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});
