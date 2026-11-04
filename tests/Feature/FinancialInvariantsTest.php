<?php

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\RevenueAdjustment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AccountingService;
use App\Services\AdvanceSettlementService;
use App\Services\ExpenseService;
use App\Services\ExpenseVerifier;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\PayoutService;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingObligations;
use App\Services\SourcingPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Ledger invariants after a mixed day of real transactions: customer money in and out, a supplier advance with a refund and a settlement, a payable
| expense paid from two accounts and refunded to each, a partner payout and a transfer. Every expected figure below is worked out by hand
| (integer paisa), independently of the code under test.
*/

it('keeps every ledger invariant across the changed paths', function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'accrual',
        'finance.sourcing_accrual_from' => '2026-11-01', 'finance.sourcing_recognition_trigger' => null]);
    $co = SellbeesCompany::boot($this);
    $finance = $co->users['finance'];
    $board = $co->users['board'];
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $verify = fn (Expense $e) => $co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $e)->assertSessionHasNoErrors()->assertSessionMissing('error');

    $co->invest('A', '1000000', '2026-11-02');                                                         // Office Cash +100,000,000
    AccountingService::transfer(['from' => $cash->id, 'to' => $bank->id, 'amount' => 20_000_000, 'date' => '2026-11-03', 'idempotency_key' => (string) Str::uuid()], $finance);

    // Customer pays BDT 1,000 into the bank; BDT 100 is refunded from the bank (revenue refund).
    $project = $co->project($co->client('Client'), 'Project');
    $segment = $co->segment($project, 'Build', '100000');
    $co->post('finance', 'segments.payments.store', ['amount' => '1000', 'paid_on' => '2026-11-06', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(), 'financial_account_id' => $bank->id], $segment)->assertSessionHasNoErrors();
    $payment = Payment::firstOrFail();
    $co->post('finance', 'finance.revenue.store', ['kind' => 'refund', 'against' => 'revenue', 'amount' => '100', 'effective_on' => '2026-11-08', 'reason' => 'Returned', 'financial_account_id' => $bank->id], $payment)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $co->post('board', 'finance.revenue.decide', ['decision' => 'verify'], RevenueAdjustment::firstOrFail())->assertSessionHasNoErrors();

    // Supplier: BDT 50,000 advance from the bank, BDT 20,000 refunded, 13 pieces accepted, BDT 10,000 of the payable paid.
    $supplier = Supplier::create(['name' => 'Uttara Buying House']);
    $lot = Sourcing::createLot(['title' => 'Lot'], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => $supplier->id, 'description' => 'Zipper Hoodie', 'quantity' => '13', 'unit_price' => 500_000], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 75_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, $board);
    $supplierPay = function (int $amount) use ($lot, $supplier, $finance, $bank, $verify) {
        $p = SourcingPayments::recordSupplierPayment($lot->refresh(), ['supplier_id' => $supplier->id, 'amount' => $amount, 'paid_on' => '2026-11-04', 'method' => 'bank', 'purchase_order' => 'PO-1',
            'reference' => 'R'.Str::random(4), 'evidence_reference' => 'E-1', 'financial_account_id' => $bank->id, 'idempotency_key' => (string) Str::uuid()], $finance)->load('expense');
        $verify($p->expense);

        return $p->refresh();
    };
    $advance = $supplierPay(5_000_000);
    SourcingPayments::voidSupplierPayment($advance, 'Short order', $finance, 'refund_received', 2_000_000, 'BANK-RCPT-1', $bank->id);
    $verify(Expense::findOrFail($advance->refresh()->reversal_expense_id));
    SourcingArrival::record($line->refresh(), '13', '13', '2026-11-08', $finance, 'DN-1');
    foreach (AdvanceSettlementService::propose($lot->refresh(), $supplier->id, $finance) as $s) {
        AdvanceSettlementService::decide($s, true, $board);
    }
    $supplierPay(1_000_000);

    // A BDT 3,000 payable expense paid 1,000 from the bank and 2,000 from cash, then refunded to the account each part came from.
    $bill = ExpenseVerifier::record(['category' => 'Office', 'description' => 'Furniture', 'amount' => 300_000, 'spent_on' => '2026-11-05', 'payment_status' => 'unpaid'], $finance);
    $verify($bill);
    $p1 = ExpenseService::pay($bill->refresh(), ['amount' => 100_000, 'paid_on' => '2026-11-06', 'financial_account_id' => $bank->id], $finance);
    $p2 = ExpenseService::pay($bill->refresh(), ['amount' => 200_000, 'paid_on' => '2026-11-06', 'financial_account_id' => $cash->id], $finance);
    $reversal = ExpenseService::reverse($bill->refresh(), 'recording_error', 'Duplicate bill', $finance);
    $verify($reversal);

    // A partner payout of BDT 8,000 from the bank against an allocated entitlement.
    $partner = $co->partner('A');
    Ledger::post('2026-11-07', 'Entitlement', 'test', 1, [['profit.pool', null, 800_000, 0], ['partner.payable', $partner->id, 0, 800_000]], $finance->id);
    $payout = Payout::create(['partner_id' => $partner->id, 'amount' => 800_000, 'requested_by' => $finance->id, 'status' => 'approved', 'approved_by' => $board->id]);
    PayoutService::pay($payout, $finance, $bank->id);

    // 1. Every entry balances and every source is posted once.
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);

    // 2. Per-account cash adds up to the company total, and each account holds what the transactions say.
    // Bank: 20,000,000 +100,000 -10,000 -5,000,000 +2,000,000 -1,000,000 -100,000 +100,000 -800,000 = 15,290,000. Cash: 100,000,000 -20,000,000 -200,000 +200,000.
    expect(AccountingService::balance($bank->id))->toBe(15_290_000)->and(AccountingService::balance($cash->id))->toBe(80_000_000)
        ->and(AccountingService::balances()->sum('balance'))->toBe(AccountingService::totalCash())->and(AccountingService::totalCash())->toBe(95_290_000);

    // 3. Income is the customer's money less the refund; refunds of assets and the reversed bill never touch income or cost.
    expect(Ledger::creditBalance('income'))->toBe(90_000)->and(dr('expense'))->toBe(0)->and(dr('assets.supplier_advances'))->toBe(0)->and(dr('assets.inventory'))->toBe(6_500_000);

    // 4. One liability, counted once: the BDT 35,000 payable less BDT 10,000 paid. The sourcing view carries it as in the ledger, not again as owed.
    $view = SourcingObligations::notInLedger();
    expect(-dr('liabilities'))->toBe(2_500_000)->and($view['in_ledger'])->toBe(2_500_000)->and($view['total'])->toBe(0)->and(SourcingObligations::outstanding()['suppliers'])->toBe(2_500_000)
        ->and(FinancialStatements::position()['free_cash'])->toBe(95_290_000 - 2_500_000 - 0)->and(dr('partner.payable', $partner->id))->toBe(0);

    // 5. Partial and split payments reconcile to their parts.
    expect(ExpensePayment::where('expense_id', $bill->id)->whereNull('voided_at')->sum('amount'))->toBe(300_000)
        ->and(SupplierPayment::whereNull('voided_at')->get()->sum(fn ($p) => $p->refresh()->load('corrections', 'expense')->effectiveAmount()))->toBe(3_000_000 + 1_000_000);

    // 6. Idempotent: repeating the settlement, the payout and a posting changes nothing, and posted history cannot be edited.
    $entries = JournalEntry::count();
    expect(AdvanceSettlementService::propose($lot->refresh(), $supplier->id, $finance))->toBeEmpty()
        ->and(fn () => PayoutService::pay($payout->refresh(), $finance, $bank->id))->toThrow(RuntimeException::class)
        ->and(fn () => Ledger::post('2026-11-07', 'again', 'test', 1, [['cash', null, 1, 0], ['expense', null, 0, 1]], $finance->id))->toThrow(RuntimeException::class, 'already been posted')
        ->and(JournalEntry::count())->toBe($entries)
        ->and(fn () => JournalLine::first()->update(['debit' => 1]))->toThrow(Exception::class)
        ->and(fn () => JournalEntry::first()->update(['memo' => 'changed']))->toThrow(Exception::class);

    Carbon::setTestNow();
});
