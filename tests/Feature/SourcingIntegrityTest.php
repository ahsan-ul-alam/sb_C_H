<?php

use App\Models\AdvanceSettlement;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AccountingService;
use App\Services\AdvanceSettlementService;
use App\Services\ExpenseVerifier;
use App\Services\FinancialReportingService;
use App\Services\FinancialStatements;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingObligations;
use App\Services\SourcingPayments;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Phase 1a: sourcing integrity. Amounts are integer paisa and follow the audit's Scenario A: a lot of 13 pieces at BDT 5,000 (BDT 65,000), a
| supplier advance of BDT 50,000, a shortfall payable of BDT 15,000, transport of BDT 3,000 and a part settlement of BDT 10,000. Every
| expectation is worked out independently of the code under test. "Today" is 2026-11-10.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'accrual',
        'finance.sourcing_accrual_from' => '2026-11-01', 'finance.sourcing_recognition_trigger' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->bank = FinancialAccount::factory()->asDefault()->create(['name' => 'City Bank']);
    $this->co->invest('A', '1000000', '2026-11-02');
    $this->supplier = Supplier::create(['name' => 'Uttara Buying House']);
});

afterEach(fn () => Carbon::setTestNow());

/** A costed, approved lot of 13 pieces at BDT 5,000 = BDT 65,000, optionally with an actual transport cost. */
function siLot(?int $transport = null, ?Supplier $supplier = null): array
{
    $finance = test()->finance;
    $lot = Sourcing::createLot(['title' => 'Lot '.Str::random(4)], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => ($supplier ?? test()->supplier)->id, 'description' => 'Zipper Hoodie', 'quantity' => '13', 'unit_price' => 500_000], $finance);
    $cost = $transport ? Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => $transport, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance) : null;
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 75_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);

    return [$lot->refresh(), $line->refresh(), $cost];
}

function siVerify(Expense $expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

/** Record a supplier payment; it stays pending until the Board verifies it. */
function siRecord($lot, int $amount, string $date = '2026-11-03', ?Supplier $supplier = null): SupplierPayment
{
    return SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => ($supplier ?? test()->supplier)->id, 'amount' => $amount, 'paid_on' => $date, 'method' => 'bank',
        'purchase_order' => 'PO-'.$lot->id, 'reference' => 'PAY-'.Str::random(5), 'evidence_reference' => 'INV-'.$lot->id, 'idempotency_key' => (string) Str::uuid()], test()->finance)->load('expense');
}

function siPay($lot, int $amount, string $date = '2026-11-03'): SupplierPayment
{
    $payment = siRecord($lot, $amount, $date);
    siVerify($payment->expense);

    return $payment->refresh()->load('expense');
}

function siArrive($line, string $accepted = '13', string $received = '13'): void
{
    SourcingArrival::record($line->refresh(), $received, $accepted, '2026-11-08', test()->finance, 'DN-1');
}

/** Finance proposes, the Board verifies. */
function siSettle($lot): array
{
    $settlements = AdvanceSettlementService::propose($lot, test()->supplier->id, test()->finance);
    foreach ($settlements as $s) {
        AdvanceSettlementService::decide($s, true, test()->board);
    }

    return $settlements->all();
}

function siDirectCost(): int
{
    return FinancialReportingService::expenseTotals(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))['direct'];
}

it('refuses to settle goods while the supplier advance still awaits verification, then settles once it is verified', function () {
    [$lot, $line] = siLot();
    $advance = siRecord($lot, 5_000_000); // recorded, not yet verified: the books do not know it
    siArrive($line);

    expect(fn () => AdvanceSettlementService::propose($lot, $this->supplier->id, $this->finance))->toThrow(RuntimeException::class, 'await Board verification')
        ->and(AdvanceSettlement::count())->toBe(0)->and(dr('liabilities'))->toBe(0)->and(dr('assets.inventory'))->toBe(0);

    siVerify($advance->expense);
    $settlements = collect(siSettle($lot));

    // Dr inventory 65,000 = Cr advances 50,000 + Cr payable 15,000; no cash moves and no expense is booked.
    expect($settlements->pluck('kind')->all())->toBe(['advance', 'payable'])->and($settlements->pluck('amount')->all())->toBe([5_000_000, 1_500_000])
        ->and(dr('assets.inventory'))->toBe(6_500_000)->and(dr('assets.supplier_advances'))->toBe(0)->and(dr('liabilities'))->toBe(-1_500_000)->and(dr('expense'))->toBe(0);
    // Repeating changes nothing: no new proposal and no second journal entry.
    expect(AdvanceSettlementService::propose($lot->refresh(), $this->supplier->id, $this->finance))->toBeEmpty()
        ->and(fn () => AdvanceSettlementService::decide($settlements[0], true, $this->board))->toThrow(RuntimeException::class, 'Already decided');
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('never books a payable payment as a new advance while its settlement is pending', function () {
    [$lot, $line] = siLot();
    siPay($lot, 5_000_000);
    siArrive($line);
    $proposed = AdvanceSettlementService::propose($lot, $this->supplier->id, $this->finance); // an advance and a payable, both awaiting the Board
    expect($proposed)->toHaveCount(2);

    $cash = AccountingService::totalCash();
    expect(fn () => siRecord($lot, 1_000_000))->toThrow(RuntimeException::class, 'await Board verification')
        ->and(SupplierPayment::count())->toBe(1)->and(Expense::where('classification', 'supplier_advance')->count())->toBe(1)->and(AccountingService::totalCash())->toBe($cash);

    foreach ($proposed as $settlement) {
        AdvanceSettlementService::decide($settlement, true, $this->board);
    }
    // Now the same payment is what it is: a part payment of the 15,000 payable, Dr liabilities / Cr cash, never a second advance.
    $payment = siPay($lot, 1_000_000);

    expect($payment->expense->classification)->toBe('supplier_payable')->and(dr('liabilities'))->toBe(-500_000)->and(dr('assets.supplier_advances'))->toBe(0)
        ->and(AdvanceSettlementService::payableOutstanding($lot->refresh(), $this->supplier->id))->toBe(500_000)->and(AccountingService::totalCash())->toBe($cash - 1_000_000)
        ->and(dr('expense'))->toBe(0);
});

it('does not let pending payments together pay more of a payable than is owed', function () {
    [$lot, $line] = siLot();
    siPay($lot, 5_000_000);
    siArrive($line, '11'); // 13 arrived, 11 accepted: BDT 55,000 accepted, so a BDT 5,000 payable beyond the 50,000 advance
    siSettle($lot);
    expect(dr('liabilities'))->toBe(-500_000);

    $first = siRecord($lot, 300_000); // pending, classified as paying the payable
    expect($first->expense->classification)->toBe('supplier_payable')
        // The lot's own cap (13 pieces ordered) would still allow it: only the payable itself, net of the pending payment, stops it.
        ->and(fn () => siRecord($lot, 300_000))->toThrow(RuntimeException::class, 'is owed 2,000.00')
        ->and(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id, includePending: true))->toBe(200_000)
        ->and(SupplierPayment::count())->toBe(2);

    $second = siRecord($lot, 200_000);
    siVerify($first->expense);
    siVerify($second->expense);

    expect(dr('liabilities'))->toBe(0)->and(AdvanceSettlementService::payableOutstanding($lot->refresh(), $this->supplier->id))->toBe(0)
        ->and(dr('expense'))->toBe(0)->and(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('checks the same conditions again when the Board approves a settlement', function () {
    [$lot, $line] = siLot();
    siPay($lot, 5_000_000);
    siArrive($line);
    $proposed = AdvanceSettlementService::propose($lot, $this->supplier->id, $this->finance);

    // The arrival is corrected downwards after the proposal: 13 accepted became 10, so 65,000 can no longer be settled.
    siArrive($line, '10', '13');
    expect(fn () => AdvanceSettlementService::decide($proposed[1], true, $this->board))->toThrow(RuntimeException::class, 'no longer cover')
        ->and(dr('assets.inventory'))->toBe(0)->and(dr('liabilities'))->toBe(0)->and(AdvanceSettlement::where('status', 'verified')->count())->toBe(0);
    siArrive($line);

    // A payment recorded after the proposal (here inserted directly, as another request could have) blocks approval until it is decided.
    $late = ExpenseVerifier::record(['category' => 'Sourcing: supplier payment', 'description' => 'late', 'amount' => 100_000, 'spent_on' => '2026-11-05', 'classification' => 'supplier_advance'], $this->finance, ['sourcing' => true]);
    SupplierPayment::create(['supplier_id' => $this->supplier->id, 'sourcing_lot_id' => $lot->id, 'amount' => 100_000, 'paid_on' => '2026-11-05', 'idempotency_key' => (string) Str::uuid(), 'expense_id' => $late->id, 'created_by' => $this->finance->id]);
    expect(fn () => AdvanceSettlementService::decide($proposed[0], true, $this->board))->toThrow(RuntimeException::class, 'await Board verification')
        ->and(AdvanceSettlement::where('status', 'verified')->count())->toBe(0);

    siVerify($late->refresh());
    AdvanceSettlementService::decide($proposed[0], true, $this->board);
    expect(dr('assets.inventory'))->toBe(5_000_000);
});

it('does not reverse a payable settlement while a payment of it awaits verification', function () {
    [$lot, $line] = siLot();
    siPay($lot, 5_000_000);
    siArrive($line);
    $settlements = siSettle($lot);
    $pendingPayment = siRecord($lot, 1_000_000);

    expect(fn () => AdvanceSettlementService::reverse($settlements[1], 'Wrong quantity', $this->co->users['board2']))->toThrow(RuntimeException::class, 'awaits Board verification')
        ->and(dr('liabilities'))->toBe(-1_500_000);
    siVerify($pendingPayment->expense);
    expect(fn () => AdvanceSettlementService::reverse($settlements[1], 'Wrong quantity', $this->co->users['board2']))->toThrow(RuntimeException::class, 'already been paid');
});

it('keeps a refund of a supplier advance out of profit under the accrual basis, and restores the account it came from', function () {
    [$lot] = siLot();
    $advance = siPay($lot, 5_000_000);
    $bank = AccountingService::balance($this->bank->id);
    $profit = FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))['net_profit'];

    SourcingPayments::voidSupplierPayment($advance, 'Order cancelled', $this->finance, 'refund_received', 2_000_000, 'BANK-RCPT-1');
    $refund = Expense::findOrFail($advance->refresh()->reversal_expense_id);
    siVerify($refund);

    // The refund keeps the classification of the advance it reverses: Dr cash 20,000 / Cr advances 20,000, and no cost reduction.
    expect($refund->classification)->toBe('supplier_advance')->and(AccountingService::balance($this->bank->id))->toBe($bank + 2_000_000)
        ->and(dr('assets.supplier_advances'))->toBe(3_000_000)->and(dr('expense'))->toBe(0)->and(siDirectCost())->toBe(0)
        ->and(FinancialReportingService::profitAndLoss(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))['net_profit'])->toBe($profit);

    // A correction already on file whose own row says "operating" (as every earlier refund was recorded) is read through the expense it reverses.
    Expense::create(['category' => 'Sourcing: supplier payment correction', 'description' => 'recorded before the fix', 'amount' => 1_000_000, 'spent_on' => '2026-11-09', 'status' => 'verified',
        'classification' => 'operating', 'reverses_id' => $advance->expense_id, 'correction_type' => 'refund_received', 'payment_status' => 'paid', 'created_by' => $this->finance->id]);
    expect(siDirectCost())->toBe(0);
});

it('keeps the cash-basis behaviour of a supplier refund: the payment was cost, so the refund reduces cost', function () {
    config(['finance.sourcing_basis' => 'cash']);
    [$lot] = siLot();
    $payment = siRecord($lot, 4_000_000);
    siVerify($payment->expense);
    $bank = AccountingService::balance($this->bank->id);
    expect($payment->expense->classification)->toBe('operating')->and(siDirectCost())->toBe(4_000_000);

    SourcingPayments::voidSupplierPayment($payment->refresh(), 'Short delivery', $this->finance, 'refund_received', 1_000_000, 'BANK-RCPT-2');
    $refund = Expense::findOrFail($payment->refresh()->reversal_expense_id);
    siVerify($refund);

    expect($refund->classification)->toBe('operating')->and(siDirectCost())->toBe(3_000_000)->and(AccountingService::balance($this->bank->id))->toBe($bank + 1_000_000)
        ->and(dr('expense'))->toBe(3_000_000)->and(ledgerAudit()['unbalanced'])->toBe(0);
});

it('counts a payable the ledger already carries once in free cash, while it still holds back profit', function () {
    [$lot, $line, $transport] = siLot(transport: 300_000);
    siPay($lot, 5_000_000);
    siArrive($line);
    siSettle($lot);
    SourcingPayments::payCost($transport, '2026-11-09', $this->finance, null, asPayable: true); // incurred, unpaid: Dr inventory / Cr liabilities
    siVerify($transport->refresh()->expense);

    $cash = AccountingService::totalCash();
    $position = FinancialStatements::position();
    // The ledger holds 15,000 payable + 3,000 transport payable. They hold back profit (outstanding) but are not subtracted twice from cash.
    expect(dr('liabilities'))->toBe(-1_800_000)->and(dr('assets.inventory'))->toBe(6_800_000)
        ->and(SourcingObligations::outstanding()['total'])->toBe(1_800_000)
        ->and(SourcingObligations::notInLedger()['total'])->toBe(0)->and(SourcingObligations::notInLedger()['in_ledger'])->toBe(1_800_000)
        ->and($position['recorded_liabilities'])->toBeGreaterThanOrEqual(1_800_000)->and($position['sourcing']['total'])->toBe(0)
        ->and($position['free_cash'])->toBe($cash - $position['recorded_liabilities']);

    // Pay 10,000 of the payable: both views fall together, and the figures stay exact.
    siPay($lot, 1_000_000);
    $after = FinancialStatements::position();
    expect(dr('liabilities'))->toBe(-800_000)->and(SourcingObligations::outstanding()['total'])->toBe(800_000)->and(SourcingObligations::notInLedger()['total'])->toBe(0)
        ->and($after['free_cash'])->toBe(AccountingService::totalCash() - $after['recorded_liabilities']);

    // A payment of the payable that still awaits verification is part of the same liability, not a second claim on cash.
    $pending = siRecord($lot, 500_000);
    expect($pending->expense->classification)->toBe('supplier_payable')->and(SourcingObligations::notInLedger()['total'])->toBe(0)
        ->and(SourcingObligations::outstanding()['pending'])->toBe(500_000);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('never offsets one lot against another and never reports a negative obligation', function () {
    [$first, $firstLine] = siLot();
    siPay($first, 5_000_000);
    siArrive($firstLine);
    siSettle($first); // ledger payable of 15,000 on the first lot only

    [$second] = siLot(); // ordered BDT 65,000, nothing paid, nothing accepted: an obligation the ledger knows nothing about
    $view = SourcingObligations::notInLedger();

    expect($view['suppliers'])->toBe(6_500_000)->and($view['in_ledger'])->toBe(1_500_000)->and($view['total'])->toBe(6_500_000)
        ->and(collect($view['lines'])->every(fn ($l) => $l['not_in_ledger'] >= 0 && $l['in_ledger'] >= 0 && $l['not_in_ledger'] + $l['in_ledger'] <= $l['obligation'] + 0))->toBeTrue()
        ->and(collect($view['lines'])->where('lot', $first->number)->sum('not_in_ledger'))->toBe(0)
        ->and(collect($view['lines'])->where('lot', $second->number)->sum('not_in_ledger'))->toBe(6_500_000);
    // The complete list that holds back profit is unchanged: both lots still owe the supplier.
    expect(SourcingObligations::outstanding()['suppliers'])->toBe(8_000_000);
});

it('gives exactly the old figures under the cash basis, where the ledger holds no payable', function () {
    config(['finance.sourcing_basis' => 'cash']);
    [$lot] = siLot(transport: 300_000);
    siPay($lot, 2_000_000);
    siRecord($lot, 500_000); // awaiting verification

    $all = SourcingObligations::outstanding();
    $view = SourcingObligations::notInLedger();

    expect($view['suppliers'])->toBe($all['suppliers'])->and($view['costs'])->toBe($all['costs'])->and($view['pending'])->toBe($all['pending'])->and($view['total'])->toBe($all['total'])
        ->and($all['suppliers'])->toBe(4_000_000)->and($all['pending'])->toBe(500_000)->and($view['in_ledger'])->toBe(0);
});
