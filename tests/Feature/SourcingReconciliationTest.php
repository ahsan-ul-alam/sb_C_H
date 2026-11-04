<?php

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AdvanceSettlementService;
use App\Services\ExpenseService;
use App\Services\FinancialStatements;
use App\Services\LotFinancials;
use App\Services\ProjectSourcingReport;
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
| Source-based reconciliation of sourcing obligations. Amounts are integer paisa. The lot is the audit's Scenario A: 13 pieces at BDT 5,000
| (BDT 65,000), an advance of BDT 50,000, a BDT 15,000 payable and BDT 3,000 of transport. Every expectation is worked out independently.
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
    $this->other = Supplier::create(['name' => 'Second Supplier']);
});

afterEach(fn () => Carbon::setTestNow());

/** A costed, approved lot: 13 pieces from the first supplier, optionally transport and 4 pieces from a second supplier. */
function rmLot(?int $transport = null, bool $secondSupplier = false): array
{
    $finance = test()->finance;
    $lot = Sourcing::createLot(['title' => 'Lot '.Str::random(4)], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => test()->supplier->id, 'description' => 'Zipper Hoodie', 'quantity' => '13', 'unit_price' => 500_000], $finance);
    if ($secondSupplier) {
        Sourcing::addLine($lot, ['supplier_id' => test()->other->id, 'description' => 'Cap', 'quantity' => '4', 'unit_price' => 500_000], $finance);
    }
    $cost = $transport ? Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => $transport, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance) : null;
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 75_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);

    return [$lot->refresh(), $line->refresh(), $cost];
}

function rmVerify(Expense $expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

function rmRecord($lot, int $amount, ?Supplier $supplier = null): SupplierPayment
{
    return SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => ($supplier ?? test()->supplier)->id, 'amount' => $amount, 'paid_on' => '2026-11-03', 'method' => 'bank',
        'purchase_order' => 'PO-'.$lot->id, 'reference' => 'PAY-'.Str::random(5), 'evidence_reference' => 'INV-'.$lot->id, 'idempotency_key' => (string) Str::uuid()], test()->finance)->load('expense');
}

function rmPay($lot, int $amount): SupplierPayment
{
    $payment = rmRecord($lot, $amount);
    rmVerify($payment->expense);

    return $payment->refresh();
}

/** Accept everything and have the Board verify the settlements: the advance is applied and the rest becomes a payable. */
function rmAcceptAndSettle($lot, $line): void
{
    SourcingArrival::record($line->refresh(), '13', '13', '2026-11-08', test()->finance, 'DN-1');
    foreach (AdvanceSettlementService::propose($lot->refresh(), test()->supplier->id, test()->finance) as $settlement) {
        AdvanceSettlementService::decide($settlement, true, test()->board);
    }
}

function rmRow(string $kind, ?int $ref = null): array
{
    return collect(SourcingObligations::notInLedger()['rows'])->first(fn ($r) => $r['kind'] === $kind && ($ref === null || $r['ref'] === $ref));
}

it('carries each obligation once, by its own source, through a lot taken from proposal to payment', function () {
    [$lot, $line, $transport] = rmLot(transport: 300_000);
    rmPay($lot, 5_000_000);
    rmAcceptAndSettle($lot, $line);

    // S0: BDT 15,000 payable is in the ledger; BDT 3,000 of transport is owed but nothing has been recorded for it.
    $supplier = rmRow('supplier');
    expect($supplier)->toMatchArray(['total_obligation' => 6_500_000, 'paid_amount' => 5_000_000, 'pending_payment' => 0, 'remaining_owed' => 1_500_000,
        'verified_payable' => 1_500_000, 'recognised_in_ledger' => 1_500_000, 'not_recognised' => 0, 'status' => 'recognised']);
    expect(rmRow('cost'))->toMatchArray(['total_obligation' => 300_000, 'remaining_owed' => 300_000, 'recognised_in_ledger' => 0, 'not_recognised' => 300_000, 'status' => 'not_recorded'])
        ->and(SourcingObligations::outstanding())->toBe(['suppliers' => 1_500_000, 'costs' => 300_000, 'pending' => 0, 'total' => 1_800_000]);

    // S1: the transport is recorded as a payable but not yet verified: a proposal, counted once (before: twice, as 6,000).
    SourcingPayments::payCost($transport->refresh(), '2026-11-09', $this->finance, null, asPayable: true);
    expect(rmRow('cost'))->toMatchArray(['remaining_owed' => 300_000, 'recognised_in_ledger' => 0, 'not_recognised' => 300_000, 'status' => 'awaiting_verification'])
        ->and(SourcingObligations::outstanding())->toBe(['suppliers' => 1_500_000, 'costs' => 300_000, 'pending' => 0, 'total' => 1_800_000])
        ->and(SourcingObligations::notInLedger()['total'])->toBe(300_000);

    // S2: verified: now a recognised liability and no longer "not in the ledger".
    rmVerify($transport->refresh()->expense);
    expect(rmRow('cost'))->toMatchArray(['remaining_owed' => 300_000, 'verified_payable' => 300_000, 'recognised_in_ledger' => 300_000, 'not_recognised' => 0, 'status' => 'recognised'])
        ->and(SourcingObligations::notInLedger()['total'])->toBe(0)->and(-dr('liabilities'))->toBe(1_800_000);

    // S3: BDT 1,000 of the BDT 3,000 is paid: the remainder is what is owed (before: the whole 3,000).
    ExpenseService::pay($transport->refresh()->expense, ['amount' => 100_000, 'paid_on' => '2026-11-09'], $this->finance);
    expect(rmRow('cost'))->toMatchArray(['total_obligation' => 300_000, 'paid_amount' => 100_000, 'remaining_owed' => 200_000, 'recognised_in_ledger' => 200_000, 'not_recognised' => 0])
        ->and(SourcingObligations::outstanding())->toBe(['suppliers' => 1_500_000, 'costs' => 200_000, 'pending' => 0, 'total' => 1_700_000])
        ->and(-dr('liabilities'))->toBe(1_700_000);
    $figures = LotFinancials::for($lot->refresh());
    expect($figures['costs_unpaid'])->toBe(200_000)->and($figures['costs_paid'])->toBe(100_000)->and($figures['cash_required'])->toBe(1_700_000);

    // S4: BDT 10,000 of the payable is paid but still awaits verification: owed falls, the payment is shown separately, nothing is counted twice.
    $payable = rmRecord($lot->refresh(), 1_000_000);
    expect(rmRow('supplier'))->toMatchArray(['paid_amount' => 5_000_000, 'pending_payment' => 1_000_000, 'remaining_owed' => 500_000, 'verified_payable' => 1_500_000, 'recognised_in_ledger' => 1_500_000, 'not_recognised' => 0])
        ->and(SourcingObligations::outstanding())->toBe(['suppliers' => 500_000, 'costs' => 200_000, 'pending' => 1_000_000, 'total' => 1_700_000])
        ->and(SourcingObligations::notInLedger()['total'])->toBe(0);

    // S5: verified; S6: everything paid.
    rmVerify($payable->expense);
    expect(SourcingObligations::outstanding()['total'])->toBe(700_000)->and(-dr('liabilities'))->toBe(700_000);
    rmVerify(rmRecord($lot->refresh(), 500_000)->expense);
    ExpenseService::pay($transport->refresh()->expense, ['amount' => 200_000, 'paid_on' => '2026-11-09'], $this->finance);
    expect(SourcingObligations::outstanding())->toBe(['suppliers' => 0, 'costs' => 0, 'pending' => 0, 'total' => 0])
        ->and(SourcingObligations::notInLedger()['total'])->toBe(0)->and(-dr('liabilities'))->toBe(0)
        ->and(LotFinancials::for($lot->refresh())['costs_paid'])->toBe(300_000)->and(ledgerAudit()['unbalanced'])->toBe(0);
});

it('reflects a part-paid cost in the project cash-investment figures, split exactly over the lot', function () {
    [$lot, $line, $transport] = rmLot(transport: 300_000);
    rmPay($lot, 5_000_000);
    rmAcceptAndSettle($lot, $line);
    SourcingPayments::payCost($transport->refresh(), '2026-11-09', $this->finance, null, asPayable: true);
    rmVerify($transport->refresh()->expense);
    ExpenseService::pay($transport->refresh()->expense, ['amount' => 100_000, 'paid_on' => '2026-11-09'], $this->finance);

    $buckets = ProjectSourcingReport::build()['lots'][0]['buckets'];

    expect(collect($buckets)->sum('costs_paid'))->toBe(100_000)->and(collect($buckets)->sum('costs_unpaid'))->toBe(200_000)
        ->and(collect($buckets)->sum('supplier_paid'))->toBe(5_000_000)->and(collect($buckets)->sum('supplier_outstanding'))->toBe(1_500_000);
});

it('never offsets one supplier or lot against another, and never reports a negative amount', function () {
    [$first, $firstLine] = rmLot(secondSupplier: true); // supplier A 13 pieces, supplier B 4 pieces (BDT 20,000)
    rmPay($first, 5_000_000);
    rmAcceptAndSettle($first, $firstLine);              // supplier A: a BDT 15,000 payable in the ledger
    [$second] = rmLot();                                // another lot: BDT 65,000 ordered, nothing paid

    $view = SourcingObligations::notInLedger();
    $byLot = fn (string $number, string $field) => collect($view['rows'])->where('lot', $number)->sum($field);

    expect(rmRow('supplier', $this->supplier->id)['recognised_in_ledger'])->toBe(1_500_000)
        ->and(collect($view['rows'])->where('lot', $first->number)->firstWhere('ref', $this->other->id))->toMatchArray(['remaining_owed' => 2_000_000, 'recognised_in_ledger' => 0, 'not_recognised' => 2_000_000, 'status' => 'not_recorded'])
        ->and($byLot($second->number, 'not_recognised'))->toBe(6_500_000)
        ->and($view['suppliers'])->toBe(2_000_000 + 6_500_000)->and(SourcingObligations::outstanding()['suppliers'])->toBe(1_500_000 + 2_000_000 + 6_500_000);
    foreach ($view['rows'] as $row) {
        foreach (['total_obligation', 'paid_amount', 'pending_payment', 'remaining_owed', 'verified_payable', 'recognised_in_ledger', 'not_recognised'] as $field) {
            expect($row[$field])->toBeGreaterThanOrEqual(0);
        }
        expect($row['recognised_in_ledger'] + $row['not_recognised'])->toBeLessThanOrEqual($row['remaining_owed'] + $row['pending_payment']);
    }
});

it('keeps free cash exact and the profit holdback gate unchanged in meaning', function () {
    [$lot, $line, $transport] = rmLot(transport: 300_000);
    rmPay($lot, 5_000_000);
    rmAcceptAndSettle($lot, $line);
    SourcingPayments::payCost($transport->refresh(), '2026-11-09', $this->finance, null, asPayable: true);
    rmVerify($transport->refresh()->expense);
    ExpenseService::pay($transport->refresh()->expense, ['amount' => 100_000, 'paid_on' => '2026-11-09'], $this->finance);

    $position = FinancialStatements::position();
    // Cash less what is owed, each obligation deducted once: the ledger liabilities (15,000 + 2,000) and nothing already inside them.
    expect($position['free_cash'])->toBe($position['cash'] - 1_700_000)->and($position['sourcing']['total'])->toBe(0);
    // Profit allocation only asks "is anything open?": that answer is the same as before the correction, here and once all is paid.
    expect(SourcingObligations::outstanding()['total'] > 0)->toBeTrue();
    rmVerify(rmRecord($lot->refresh(), 1_500_000)->expense);
    ExpenseService::pay($transport->refresh()->expense, ['amount' => 200_000, 'paid_on' => '2026-11-09'], $this->finance);
    expect(SourcingObligations::outstanding()['total'] > 0)->toBeFalse()->and(SourcingObligations::details())->toBe([]);
});

it('lists a part-paid cost in the holdback by what is still owed, and a proposed cost once', function () {
    [$lot, $line, $transport] = rmLot(transport: 300_000);
    rmPay($lot, 5_000_000);
    rmAcceptAndSettle($lot, $line);
    SourcingPayments::payCost($transport->refresh(), '2026-11-09', $this->finance, null, asPayable: true);

    $costRows = fn () => collect(SourcingObligations::details())->filter(fn ($r) => str_starts_with($r['reason'], 'Lot cost'));
    $proposedRows = $costRows();
    expect($proposedRows)->toHaveCount(1)->and($proposedRows->first()['amount'])->toBe(300_000)
        ->and(collect(SourcingObligations::details())->where('reason', 'Payment recorded but not yet verified'))->toHaveCount(0); // not listed a second time

    rmVerify($transport->refresh()->expense);
    ExpenseService::pay($transport->refresh()->expense, ['amount' => 100_000, 'paid_on' => '2026-11-09'], $this->finance);
    expect($costRows()->first()['amount'])->toBe(200_000);
});
