<?php

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseRefundAllocation;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PrepaidRelease;
use App\Services\AccountingService;
use App\Services\ExpenseService;
use App\Services\ExpenseVerifier;
use App\Services\Ledger;
use App\Services\PrepaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Phase 1b: expense integrity. Amounts are integer paisa and every expectation is worked out independently of the code under test.
| "Today" is 2026-11-10. Three accounts hold money: Office Cash (the default), City Bank and bKash.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $this->bkash = FinancialAccount::factory()->create(['name' => 'bKash', 'type' => 'mobile']);
    $this->co->invest('A', '1000000', '2026-11-02');
    foreach ([[$this->bank, '300000'], [$this->bkash, '100000']] as [$to, $amount]) {
        $this->co->post('finance', 'finance.transfers.store', ['from' => (string) $this->cash->id, 'to' => (string) $to->id, 'amount' => $amount, 'date' => '2026-11-02', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }
});

afterEach(fn () => Carbon::setTestNow());

function eiVerify(Expense $expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

/** A verified, unpaid (payable) expense. */
function eiPayable(int $amount = 300_000, string $classification = 'operating'): Expense
{
    $expense = ExpenseVerifier::record(['category' => 'Office', 'description' => 'Item '.Str::random(5), 'amount' => $amount, 'spent_on' => '2026-11-05', 'classification' => $classification, 'payment_status' => 'unpaid'], test()->finance);
    eiVerify($expense);

    return $expense->refresh();
}

function eiPay(Expense $expense, int $amount, FinancialAccount $account): ExpensePayment
{
    return ExpenseService::pay($expense->refresh(), ['amount' => $amount, 'paid_on' => '2026-11-06', 'financial_account_id' => $account->id], test()->finance);
}

/** The journal debits and credits of one expense row, per financial account for cash. */
function eiLines(Expense $expense): array
{
    $entry = JournalEntry::where('source_type', 'expense')->where('source_id', $expense->id)->firstOrFail();

    return $entry->lines->map(fn ($l) => [$l->account, (int) $l->debit, (int) $l->credit, $l->financial_account_id])->all();
}

it('blocks voiding a payment while a reversal of the expense awaits verification, and allows it once the Board has decided', function () {
    $expense = eiPayable();
    $payment = eiPay($expense, 300_000, $this->bank);
    $reversal = ExpenseService::reverse($expense->refresh(), 'refund_received', 'Supplier refunded', $this->finance);
    $bank = AccountingService::balance($this->bank->id);
    $entries = JournalEntry::count();

    expect(fn () => ExpenseService::voidPayment($payment->refresh(), $this->finance))->toThrow(RuntimeException::class, 'awaiting verification')
        ->and($payment->refresh()->voided_at)->toBeNull()->and(AccountingService::balance($this->bank->id))->toBe($bank)->and(JournalEntry::count())->toBe($entries);

    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'reject', 'reason' => 'Not needed'], $reversal)->assertSessionHasNoErrors();
    ExpenseService::voidPayment($payment->refresh(), $this->finance); // the reversal is decided: the void is allowed again
    expect($payment->refresh()->voided_at)->not->toBeNull()->and(AccountingService::balance($this->bank->id))->toBe($bank + 300_000)->and(ledgerAudit()['unbalanced'])->toBe(0);
});

it('re-checks a reversal when the Board approves it: a voided or newly recorded payment stops it and nothing is posted', function () {
    // A payment voided after the request (here done directly, as a path that predates the block would have).
    $paid = eiPayable();
    $payment = eiPay($paid, 300_000, $this->bank);
    $reversal = ExpenseService::reverse($paid->refresh(), 'refund_received', 'Supplier refunded', $this->finance);
    $payment->update(['voided_at' => now(), 'voided_by' => $this->finance->id]);
    $cash = AccountingService::totalCash();
    $entries = JournalEntry::count();

    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $reversal)->assertSessionHas('error');
    expect($reversal->refresh()->status)->toBe('pending')->and(JournalEntry::count())->toBe($entries)->and(AccountingService::totalCash())->toBe($cash);

    // A payment recorded after a cancellation of an unpaid expense was requested.
    $unpaid = eiPayable(200_000);
    $cancel = ExpenseService::reverse($unpaid->refresh(), null, 'Ordered by mistake', $this->finance);
    expect($cancel->correction_type)->toBe('liability_cancel');
    ExpensePayment::create(['expense_id' => $unpaid->id, 'amount' => 50_000, 'paid_on' => '2026-11-07', 'financial_account_id' => $this->bank->id, 'idempotency_key' => (string) Str::uuid(), 'created_by' => $this->finance->id]);
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $cancel)->assertSessionHas('error');
    expect($cancel->refresh()->status)->toBe('pending')->and(JournalEntry::where('source_type', 'expense')->where('source_id', $cancel->id)->exists())->toBeFalse();
});

it('does not release a prepaid asset while its reversal is pending, so the asset never goes negative', function () {
    $prepaid = ExpenseVerifier::record(['category' => 'Insurance', 'description' => 'Annual cover', 'amount' => 1_200_000, 'spent_on' => '2026-11-02', 'classification' => 'prepaid', 'prepaid_months' => 12,
        'financial_account_id' => $this->bank->id], $this->finance);
    eiVerify($prepaid);
    expect(dr('assets.prepaid'))->toBe(1_200_000);

    $reversal = ExpenseService::reverse($prepaid->refresh(), 'recording_error', 'Wrong policy', $this->finance);
    // The month-end release would expense 100,000 now; with the reversal pending it is left alone.
    expect(PrepaidService::releaseDue('2026-11', $this->finance->id))->toBe(0)->and(PrepaidRelease::count())->toBe(0);
    eiVerify($reversal);

    expect(dr('assets.prepaid'))->toBe(0)->and(dr('expense'))->toBe(0)->and(PrepaidService::releaseDue('2026-11', $this->finance->id))->toBe(0)
        ->and(AccountingService::balance($this->bank->id))->toBe(30_000_000); // the recording error restored the bank

    // A release that did happen after the request (here inserted directly) stops the reversal instead of driving the asset below zero.
    $second = ExpenseVerifier::record(['category' => 'Insurance', 'description' => 'Second cover', 'amount' => 600_000, 'spent_on' => '2026-11-03', 'classification' => 'prepaid', 'prepaid_months' => 6,
        'financial_account_id' => $this->bank->id], $this->finance);
    eiVerify($second);
    $secondReversal = ExpenseService::reverse($second->refresh(), 'recording_error', 'Wrong policy', $this->finance);
    PrepaidRelease::create(['expense_id' => $second->id, 'month' => '2026-11', 'amount' => 100_000, 'role' => 'operating']);
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $secondReversal)->assertSessionHas('error');
    expect($secondReversal->refresh()->status)->toBe('pending')->and(dr('assets.prepaid'))->toBe(600_000);
});

it('refunds each original payment to its own account when an expense was paid through several accounts', function () {
    $expense = eiPayable(300_000);
    $fromBank = eiPay($expense, 100_000, $this->bank);
    $fromBkash = eiPay($expense, 200_000, $this->bkash);
    $before = JournalLine::orderBy('id')->get()->toArray();
    $bank = AccountingService::balance($this->bank->id);
    $bkash = AccountingService::balance($this->bkash->id);
    $cash = AccountingService::balance($this->cash->id);

    // Refund received: the bank part returns to the bank, the bKash part is paid into Office Cash instead. The latest payment's account is not assumed.
    $reversal = ExpenseService::reverse($expense->refresh(), 'refund_received', 'Supplier refunded', $this->finance, [$fromBank->id => null, $fromBkash->id => $this->cash->id]);
    expect(ExpenseRefundAllocation::where('reversal_expense_id', $reversal->id)->orderBy('id')->get()->map(fn ($p) => [$p->expense_payment_id, $p->financial_account_id, $p->amount])->all())
        ->toBe([[$fromBank->id, $this->bank->id, 100_000], [$fromBkash->id, $this->cash->id, 200_000]])
        ->and(AccountingService::balance($this->bank->id))->toBe($bank); // requesting moves nothing
    eiVerify($reversal);

    expect(AccountingService::balance($this->bank->id))->toBe($bank + 100_000)->and(AccountingService::balance($this->bkash->id))->toBe($bkash)
        ->and(AccountingService::balance($this->cash->id))->toBe($cash + 200_000)
        ->and(eiLines($reversal))->toBe([['cash', 100_000, 0, $this->bank->id], ['cash', 200_000, 0, $this->cash->id], ['expense', 0, 300_000, null]])
        ->and(dr('liabilities'))->toBe(0);
    // Every line posted before is exactly as it was.
    expect(JournalLine::orderBy('id')->limit(count($before))->get()->toArray())->toBe($before);

    // A recording error restores the accounts the money was recorded in, each part to its own; it cannot be redirected.
    $second = eiPayable(300_000);
    $a = eiPay($second, 100_000, $this->bank);
    $b = eiPay($second, 200_000, $this->bkash);
    expect(fn () => ExpenseService::reverse($second->refresh(), 'recording_error', 'Duplicate', $this->finance, [$b->id => $this->cash->id]))->toThrow(RuntimeException::class, 'cannot be redirected');
    $error = ExpenseService::reverse($second->refresh(), 'recording_error', 'Duplicate', $this->finance);
    $bank = AccountingService::balance($this->bank->id);
    $bkash = AccountingService::balance($this->bkash->id);
    eiVerify($error);
    expect(AccountingService::balance($this->bank->id))->toBe($bank + 100_000)->and(AccountingService::balance($this->bkash->id))->toBe($bkash + 200_000)
        ->and($error->refresh()->status)->toBe('verified')->and(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('refuses a refund account that is inactive or that belongs to no payment of the expense', function () {
    $spare = FinancialAccount::factory()->create(['name' => 'Retired wallet']);
    $spare->update(['is_active' => false]);

    $expense = eiPayable(300_000);
    $payment = eiPay($expense, 300_000, $this->bank);
    expect(fn () => ExpenseService::reverse($expense->refresh(), 'refund_received', 'Refund', $this->finance, [$payment->id => $spare->id]))->toThrow(RuntimeException::class, 'inactive')
        ->and(fn () => ExpenseService::reverse($expense->refresh(), 'refund_received', 'Refund', $this->finance, [999 => $this->cash->id]))->toThrow(RuntimeException::class, 'not part of this expense')
        ->and(Expense::whereNotNull('reverses_id')->count())->toBe(0);

    // An expense paid in one go: the refund goes where the Finance user says, and only to an active account.
    $direct = ExpenseVerifier::record(['category' => 'Office', 'description' => 'Chairs', 'amount' => 150_000, 'spent_on' => '2026-11-05', 'financial_account_id' => $this->bank->id], $this->finance);
    eiVerify($direct);
    expect(fn () => ExpenseService::reverse($direct->refresh(), 'refund_received', 'Refund', $this->finance, [0 => $spare->id]))->toThrow(RuntimeException::class, 'inactive');
    $reversal = ExpenseService::reverse($direct->refresh(), 'refund_received', 'Refund', $this->finance, [0 => $this->cash->id]);
    $cash = AccountingService::balance($this->cash->id);
    eiVerify($reversal);
    expect(AccountingService::balance($this->cash->id))->toBe($cash + 150_000);
});

it('rejects an inactive financial account for a new expense, a payment and a verification', function () {
    $spare = FinancialAccount::factory()->create(['name' => 'Retired wallet']);
    $spare->update(['is_active' => false]);

    expect(fn () => ExpenseVerifier::record(['category' => 'Office', 'description' => 'Pens', 'amount' => 10_000, 'spent_on' => '2026-11-05', 'financial_account_id' => $spare->id], $this->finance))->toThrow(RuntimeException::class, 'inactive');
    $payable = eiPayable(100_000);
    expect(fn () => eiPay($payable, 50_000, $spare))->toThrow(RuntimeException::class, 'inactive')->and(ExpensePayment::count())->toBe(0);

    // The form refuses it too (validation, not a server error).
    $this->co->post('finance', 'finance.expenses.store', ['category' => 'Office', 'description' => 'Pens', 'amount' => '100', 'spent_on' => '2026-11-05', 'financial_account_id' => $spare->id])->assertSessionHasErrors('financial_account_id');

    // An account that became inactive after the expense was recorded cannot receive its payment when the Board verifies it.
    $retiring = FinancialAccount::factory()->create(['name' => 'Retiring wallet']);
    $pending = ExpenseVerifier::record(['category' => 'Office', 'description' => 'Desk', 'amount' => 20_000, 'spent_on' => '2026-11-05', 'financial_account_id' => $retiring->id], $this->finance);
    $retiring->update(['is_active' => false]);
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $pending)->assertSessionHas('error');
    expect($pending->refresh()->status)->toBe('pending')->and(JournalEntry::where('source_type', 'expense')->where('source_id', $pending->id)->exists())->toBeFalse();
});

it('keeps posting a reversal that predates refund allocations to the single account on its own row', function () {
    $direct = ExpenseVerifier::record(['category' => 'Office', 'description' => 'Printer', 'amount' => 250_000, 'spent_on' => '2026-11-05', 'financial_account_id' => $this->bank->id], $this->finance);
    eiVerify($direct);
    $bank = AccountingService::balance($this->bank->id);

    $legacy = ExpenseVerifier::record(['category' => 'Office', 'description' => 'Reverses expense #'.$direct->id, 'amount' => 250_000, 'spent_on' => '2026-11-09', 'reverses_id' => $direct->id,
        'correction_type' => 'refund_received', 'financial_account_id' => $this->bank->id, 'payment_status' => 'paid', 'classification' => 'operating'], $this->finance);
    eiVerify($legacy);

    expect(ExpenseRefundAllocation::count())->toBe(0)->and(AccountingService::balance($this->bank->id))->toBe($bank + 250_000)
        ->and(eiLines($legacy))->toBe([['cash', 250_000, 0, $this->bank->id], ['expense', 0, 250_000, null]]);
});

it('posts a verified expense and reversal exactly once and keeps every entry balanced', function () {
    $expense = eiPayable(300_000);
    eiPay($expense, 300_000, $this->bank);
    $reversal = ExpenseService::reverse($expense->refresh(), 'refund_received', 'Refund', $this->finance);
    eiVerify($reversal);

    // The Board's decision cannot be taken twice, and the journal refuses a second posting of the same source.
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $reversal)->assertSessionHas('error');
    expect(fn () => ExpenseVerifier::post($reversal->refresh(), $this->finance->id))->toThrow(RuntimeException::class)
        ->and(JournalEntry::where('source_type', 'expense')->where('source_id', $reversal->id)->count())->toBe(1)
        ->and(fn () => Ledger::post('2026-11-10', 'again', 'expense', $reversal->id, [['cash', null, 1, 0], ['expense', null, 0, 1]], $this->finance->id))->toThrow(RuntimeException::class, 'already been posted');
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0)
        ->and(dr('liabilities'))->toBe(0)->and(dr('expense'))->toBe(0);
});

it('shows where each payment of an expense will be refunded on the reversal form, and takes the choice from the form', function () {
    $expense = eiPayable(300_000);
    $fromBank = eiPay($expense, 100_000, $this->bank);
    $fromBkash = eiPay($expense, 200_000, $this->bkash);

    $this->co->as('finance')->get(route('finance.expenses'))->assertOk()->assertSee('refund_accounts['.$fromBank->id.']', false)->assertSee('refund_accounts['.$fromBkash->id.']', false);

    $this->co->post('finance', 'finance.expenses.reverse', ['type' => 'refund_received', 'reason' => 'Supplier refunded', 'refund_accounts' => [$fromBank->id => '', $fromBkash->id => (string) $this->cash->id]], $expense)
        ->assertSessionHasNoErrors()->assertSessionMissing('error');
    $reversal = Expense::where('reverses_id', $expense->id)->firstOrFail();
    expect(ExpenseRefundAllocation::where('reversal_expense_id', $reversal->id)->pluck('financial_account_id', 'expense_payment_id')->all())
        ->toBe([$fromBank->id => $this->bank->id, $fromBkash->id => $this->cash->id]);

    $spare = FinancialAccount::factory()->create(['name' => 'Retired wallet']);
    $spare->update(['is_active' => false]);
    $other = eiPayable(100_000);
    $paid = eiPay($other, 100_000, $this->bank);
    $this->co->post('finance', 'finance.expenses.reverse', ['type' => 'refund_received', 'reason' => 'Refund', 'refund_accounts' => [$paid->id => (string) $spare->id]], $other)
        ->assertSessionHasErrors('refund_accounts.'.$paid->id);
});
