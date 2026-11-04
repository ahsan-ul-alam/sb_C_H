<?php

use App\Models\AccountingPeriod;
use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\LandedCostReallocation;
use App\Models\Supplier;
use App\Services\AdvanceSettlementService;
use App\Services\ExpenseService;
use App\Services\Inventory;
use App\Services\InventoryEngine;
use App\Services\InventoryReconciliation;
use App\Services\InventoryValuation;
use App\Services\LandedCostAllocation;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Phase 2C-0: valuation hardening. Amounts are integer paisa. "Today" is 2026-11-10 and the engine starts in November.
| Transport of BDT 3,000 (300,000 paisa) is shared by a product worth BDT 50,000 and one worth BDT 10,000.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'accrual', 'finance.sourcing_accrual_from' => '2026-11-01',
        'finance.sourcing_recognition_trigger' => null, 'finance.inventory_engine_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    FinancialAccount::factory()->asDefault()->create(['name' => 'City Bank']);
    $this->co->invest('A', '1000000', '2026-11-02');
    $this->supplier = Supplier::create(['name' => 'Uttara Buying House']);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  list<array{0: string, 1: int}>  $lines
 */
function hdLot(array $lines = [['10', 500_000]], ?int $transport = 300_000, ?string $ownership = null): array
{
    $finance = test()->finance;
    $lot = Sourcing::createLot(['title' => 'Lot '.Str::random(4)], $finance);
    $made = [];
    foreach ($lines as $i => [$quantity, $price]) {
        $made[] = Sourcing::addLine($lot, ['supplier_id' => test()->supplier->id, 'description' => 'Product '.($i + 1), 'quantity' => $quantity, 'unit_price' => $price], $finance);
    }
    $cost = $transport ? Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => $transport, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance) : null;
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 10_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);
    if ($ownership) {
        $lot->update(['ownership' => $ownership]);
    }

    return [$lot->refresh(), collect($made)->map->refresh()->all(), $cost];
}

function hdVerify(Expense $expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

function hdEnrol($lot, string $ownership = 'company'): void
{
    InventoryEngine::decide(InventoryEngine::propose($lot, $ownership, 'Board resolution 2026/11 and the signed purchase order', '2026-11-01', test()->finance), true, test()->board);
}

function hdAdvance($lot, int $amount): void
{
    $payment = SourcingPayments::recordSupplierPayment($lot->refresh(), ['supplier_id' => test()->supplier->id, 'amount' => $amount, 'paid_on' => '2026-11-03', 'method' => 'bank', 'purchase_order' => 'PO-1',
        'reference' => 'R'.Str::random(4), 'evidence_reference' => 'E-1', 'idempotency_key' => (string) Str::uuid()], test()->finance)->load('expense');
    hdVerify($payment->expense);
}

function hdArrive($line, string $received, string $accepted): void
{
    SourcingArrival::record($line->refresh(), $received, $accepted, '2026-11-08', test()->finance, 'DN-'.$line->id);
}

function hdSettle($lot): array
{
    $settlements = AdvanceSettlementService::propose($lot->refresh(), test()->supplier->id, test()->finance);
    foreach ($settlements as $s) {
        AdvanceSettlementService::decide($s, true, test()->board);
    }

    return $settlements->all();
}

/** Pay a cost now (capitalised on payment) or as a prepayment; returns its verified expense. */
function hdPayCost($cost, bool $prepaid = false): Expense
{
    SourcingPayments::payCost($cost->refresh(), '2026-11-09', test()->finance, null, prepaid: $prepaid);
    $expense = $cost->refresh()->expense;
    hdVerify($expense);

    return $expense->refresh();
}

function hdFigures($line): array
{
    return array_diff_key(InventoryValuation::lineFigures($line->refresh()), ['purchase_valued' => 0]);
}

/** Refund a verified, paid expense and have the Board verify it. */
function hdRefund(Expense $expense): Expense
{
    $reversal = ExpenseService::reverse($expense->refresh(), 'refund_received', 'Supplier refunded', test()->finance);
    hdVerify($reversal);

    return $reversal->refresh();
}

it('reverses a transport prepayment before its service is confirmed against the prepayment, and then there is no cost to move', function () {
    [$lot, [$line], $transport] = hdLot();
    hdEnrol($lot);
    hdArrive($line, '10', '10');
    $expense = hdPayCost($transport, prepaid: true);
    expect(dr('assets.transport_prepaid'))->toBe(300_000)->and(dr('assets.inventory'))->toBe(0);

    hdRefund($expense);

    expect(dr('assets.transport_prepaid'))->toBe(0)->and(dr('assets.inventory'))->toBe(0)->and(hdFigures($line)['landed'])->toBe(0)
        ->and(fn () => SourcingPayments::completeService($transport->refresh(), '2026-11-10', $this->board))->toThrow(RuntimeException::class, 'refunded');
});

it('takes a refunded transport prepayment out of inventory once its service was confirmed, never below zero in the prepayment', function () {
    [$lot, [$line], $transport] = hdLot();
    hdEnrol($lot);
    hdArrive($line, '10', '10');
    $expense = hdPayCost($transport, prepaid: true);
    SourcingPayments::completeService($transport->refresh(), '2026-11-10', $this->board);   // Dr inventory / Cr prepayment
    expect(dr('assets.inventory'))->toBe(300_000)->and(dr('assets.transport_prepaid'))->toBe(0)->and(hdFigures($line)['landed'])->toBe(300_000);

    $reversal = hdRefund($expense);

    // The refund comes out of inventory (where the cost now sits): Dr cash / Cr inventory. The prepayment stays at zero instead of going negative.
    $credit = $reversal->id;
    expect(dr('assets.inventory'))->toBe(0)->and(dr('assets.transport_prepaid'))->toBe(0)->and(hdFigures($line)['landed'])->toBe(0)
        ->and(JournalEntry::where('source_type', 'expense')->where('source_id', $credit)->firstOrFail()->lines->firstWhere('account', 'assets.inventory')->credit)->toBe(300_000)
        ->and(InventoryValueMovement::where('type', 'correction')->count())->toBe(1)
        ->and(InventoryReconciliation::lot($lot->refresh())['journal_value'])->toBe(0)->and(InventoryReconciliation::lot($lot)['exceptions'])->toBe([])   // goods accepted but not settled are pending, not an error
        ->and(InventoryReconciliation::lot($lot)['unvalued_accepted'])->toBe(5_000_000);
});

it('refuses to reverse a capitalised cost whose value has been released or consumed, instead of crediting inventory that no longer holds it', function () {
    // A lot on the legacy whole-lot basis whose cost was already released into cost of goods sold.
    [$legacy, [$legacyLine], $legacyCost] = hdLot();
    hdArrive($legacyLine, '10', '10');
    $expense = hdPayCost($legacyCost);
    CostRecognition::forceCreate(['sourcing_lot_id' => $legacy->id, 'amount' => 300_000, 'recognised_on' => '2026-11-09', 'trigger' => 'lot_completed', 'status' => 'verified', 'created_by' => $this->finance->id, 'verified_by' => $this->board->id]);
    expect(fn () => ExpenseService::reverse($expense->refresh(), 'refund_received', 'Refund', $this->finance))->toThrow(RuntimeException::class, 'already been released');

    // An engine lot from which stock has already been issued.
    [$lot, [$line], $cost] = hdLot();
    hdEnrol($lot);
    hdArrive($line, '10', '10');
    $paid = hdPayCost($cost);
    InventoryValueMovement::create(['sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $line->id, 'ownership' => 'company', 'component' => 'quantity', 'type' => 'issue', 'quantity_delta' => '-1',
        'valuation_status' => 'valued', 'source_type' => 'test', 'source_id' => 1, 'move_key' => 'test-issue', 'effective_on' => '2026-11-10']);
    expect(fn () => ExpenseService::reverse($paid->refresh(), 'refund_received', 'Refund', $this->finance))->toThrow(RuntimeException::class, 'moved or consumed');
});

it('blocks a settlement reversal while capitalised costs still sit on the goods, and allows it once they are resolved', function () {
    [$lot, [$line], $transport] = hdLot();
    hdEnrol($lot);
    hdAdvance($lot, 5_000_000);
    hdArrive($line, '10', '10');
    $expense = hdPayCost($transport);
    $settlements = hdSettle($lot);
    expect(hdFigures($line))->toBe(['purchase' => 5_000_000, 'landed' => 300_000, 'quantity' => '10.00']);

    // Reversing the settlement would leave BDT 3,000 of freight on goods that are no longer valued: refused, nothing changed.
    $count = InventoryValueMovement::count();
    expect(fn () => AdvanceSettlementService::reverse($settlements[0], 'Wrong delivery note', $this->board))->toThrow(RuntimeException::class, 'Capitalised costs')
        ->and(AdvanceSettlement::find($settlements[0]->id)->status)->toBe('verified')->and(InventoryValueMovement::count())->toBe($count);

    // Resolve the dependent cost first (a verified refund takes it out of inventory), then the settlement can be reversed with nothing left behind.
    hdRefund($expense);
    AdvanceSettlementService::reverse($settlements[0], 'Wrong delivery note', $this->board);
    expect(hdFigures($line))->toBe(['purchase' => 0, 'landed' => 0, 'quantity' => '0.00'])->and(InventoryReconciliation::lot($lot->refresh())['journal_value'])->toBe(0)
        ->and(InventoryReconciliation::lot($lot)['exceptions'])->toBe([])
        ->and(fn () => AdvanceSettlementService::reverse($settlements[0], 'Again', $this->board))->toThrow(RuntimeException::class, 'Only a verified settlement'); // no second reversal
});

it('reports value that has no accepted goods to hold it instead of leaving it unexplained', function () {
    [$lot, [$line], $transport] = hdLot();
    hdEnrol($lot);
    hdAdvance($lot, 5_000_000);
    hdArrive($line, '10', '10');
    hdPayCost($transport);
    hdSettle($lot);

    hdArrive($line, '10', '0');   // every unit found unacceptable after settlement and costing

    $row = InventoryReconciliation::lot($lot->refresh());
    expect($row['ok'])->toBeFalse()->and(collect($row['exceptions'])->implode(' '))->toContain('no accepted goods to hold it')->and(collect($row['exceptions'])->implode(' '))->toContain('above what was accepted')
        ->and(hdFigures($line)['quantity'])->toBe('0.00');
});

it('does not spread a shared cost over a line merely because it was accepted later', function () {
    [$lot, [$a, $b], $transport] = hdLot([['10', 500_000], ['4', 250_000]]);
    hdEnrol($lot);
    hdArrive($a, '10', '10');
    hdPayCost($transport);          // capitalised while only the first product has been accepted
    hdArrive($b, '4', '4');         // the second product arrives afterwards

    expect(hdFigures($a)['landed'])->toBe(300_000)->and(hdFigures($b)['landed'])->toBe(0);
    InventoryValuation::syncLot($lot->id);
    expect(hdFigures($a)['landed'])->toBe(300_000)->and(hdFigures($b)['landed'])->toBe(0)   // still not moved
        ->and(InventoryValueMovement::where('type', 'reallocation')->count())->toBe(0);
});

it('moves a landed cost between lines only through an approved, documented reallocation with exact compensating movements', function () {
    [$lot, [$a, $b], $transport] = hdLot([['10', 500_000], ['4', 250_000]]);
    hdEnrol($lot);
    hdAdvance($lot, 6_000_000);
    hdArrive($a, '10', '10');
    hdPayCost($transport);
    hdArrive($b, '4', '4');
    hdSettle($lot);
    $before = hdFigures($a)['landed'] + hdFigures($b)['landed'];
    $journalBefore = InventoryReconciliation::lot($lot->refresh())['journal_value'];

    expect(fn () => LandedCostAllocation::propose($transport->refresh(), [$a->id, $b->id], '  ', 'value', $this->finance))->toThrow(RuntimeException::class, 'documented cost policy')
        ->and(fn () => LandedCostAllocation::propose($transport->refresh(), [], 'Board resolution 2026/12', 'value', $this->finance))->toThrow(RuntimeException::class, 'Name the lines');
    $request = LandedCostAllocation::propose($transport->refresh(), [$a->id, $b->id], 'Board resolution 2026/12: freight is shared by every product of the shipment', 'value', $this->finance);
    expect(fn () => LandedCostAllocation::propose($transport->refresh(), [$a->id], 'Board resolution 2026/12', 'value', $this->finance))->toThrow(RuntimeException::class, 'already awaiting')
        ->and(fn () => LandedCostAllocation::decide($request, true, $this->finance))->toThrow(RuntimeException::class, 'you proposed')
        ->and(hdFigures($b)['landed'])->toBe(0);   // nothing moves on a proposal

    LandedCostAllocation::decide($request, true, $this->board);

    // 300,000 by accepted value 5,000,000 : 1,000,000 = 250,000 : 50,000; the compensating movements net to zero and the ledger entry is untouched.
    $moves = InventoryValueMovement::where('type', 'reallocation')->get();
    expect(hdFigures($a)['landed'])->toBe(250_000)->and(hdFigures($b)['landed'])->toBe(50_000)->and(hdFigures($a)['landed'] + hdFigures($b)['landed'])->toBe($before)
        ->and($moves->sum('value_delta'))->toBe(0)->and($moves->pluck('value_delta')->sort()->values()->all())->toBe([-50_000, 50_000])->and($moves->every(fn ($m) => $m->journal_entry_id !== null))->toBeTrue()
        ->and(InventoryReconciliation::lot($lot->refresh())['journal_value'])->toBe($journalBefore)->and(InventoryReconciliation::lot($lot)['difference'])->toBe(0)
        ->and(InventoryReconciliation::company()['negative_lines'])->toBe(0)->and(InventoryReconciliation::lot($lot)['ok'])->toBeTrue()
        ->and(fn () => LandedCostAllocation::decide($request, true, $this->board))->toThrow(RuntimeException::class, 'Already decided'); // never applied twice
});

it('refuses a reallocation to an unaccepted line and in a closed month', function () {
    [$lot, [$a, $b], $transport] = hdLot([['10', 500_000], ['4', 250_000]]);
    hdEnrol($lot);
    hdArrive($a, '10', '10');
    hdPayCost($transport);
    expect(fn () => LandedCostAllocation::propose($transport->refresh(), [$a->id, $b->id], 'Board resolution 2026/12', 'value', $this->finance))->toThrow(RuntimeException::class, 'accepted goods');

    hdArrive($b, '4', '4');
    $request = LandedCostAllocation::propose($transport->refresh(), [$a->id, $b->id], 'Board resolution 2026/12', 'quantity', $this->finance);
    AccountingPeriod::create(['month' => '2026-11', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);   // closes before the decision
    expect(fn () => LandedCostAllocation::decide($request, true, $this->board))->toThrow(RuntimeException::class, 'already closed')
        ->and(LandedCostReallocationStatus($request))->toBe('pending')->and(InventoryValueMovement::where('type', 'reallocation')->count())->toBe(0);
});

function LandedCostReallocationStatus($request): string
{
    return LandedCostReallocation::find($request->id)->status;
}

it('refuses to move a cost after stock was issued', function () {
    [$lot, [$a, $b], $transport] = hdLot([['10', 500_000], ['4', 250_000]]);
    hdEnrol($lot);
    hdArrive($a, '10', '10');
    hdArrive($b, '4', '4');
    hdPayCost($transport);
    InventoryValueMovement::create(['sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $a->id, 'ownership' => 'company', 'component' => 'quantity', 'type' => 'issue', 'quantity_delta' => '-1',
        'valuation_status' => 'valued', 'source_type' => 'test', 'source_id' => 1, 'move_key' => 'test-issue-2', 'effective_on' => '2026-11-10']);

    expect(fn () => LandedCostAllocation::propose($transport->refresh(), [$a->id, $b->id], 'Board resolution 2026/12', 'value', $this->finance))->toThrow(RuntimeException::class, 'already been handed over');
});

it('bridges accepted, valued and physical quantities by ownership and reports differences without correcting them', function () {
    [$lot, [$line]] = hdLot(transport: null);
    hdEnrol($lot);
    hdAdvance($lot, 5_000_000);
    hdArrive($line, '10', '10');
    hdSettle($lot);

    $row = collect(InventoryReconciliation::quantities())->firstWhere('line_id', $line->id);
    expect($row['classification'])->toBe('company_valued')->and($row['accepted'])->toBe('10.00')->and($row['valued'])->toBe('10.00')->and($row['physical_held'])->toBe('10.00')->and($row['ok'])->toBeTrue();

    // A stock-take finds two units missing: a count difference, reported with its cause and left exactly as it is (an approved adjustment is a later step).
    Inventory::adjust($line->refresh(), '-2', 'Stock-take: two units not found', $this->finance);
    $row = collect(InventoryReconciliation::quantities())->firstWhere('line_id', $line->id);
    expect($row['ok'])->toBeFalse()->and(collect($row['differences'])->implode(' '))->toContain('physical count held (8.00) differs from the valued quantity (10.00)')
        ->and(hdFigures($line)['quantity'])->toBe('10.00');   // nothing was silently corrected

    // A pending line (accepted, not settled) is not a difference: it simply carries no valued quantity yet.
    [$pendingLot, [$pendingLine]] = hdLot(transport: null);
    hdEnrol($pendingLot);
    hdArrive($pendingLine, '10', '10');
    expect(collect(InventoryReconciliation::quantities())->firstWhere('line_id', $pendingLine->id))->toMatchArray(['valued' => '0.00', 'ok' => true]);
});

it('keeps client-owned and unclassified stock out of the valued quantities: custody only', function () {
    [$client, [$clientLine]] = hdLot(transport: null, ownership: 'client');
    hdArrive($clientLine, '10', '10');
    [$unclassified, [$unclassifiedLine]] = hdLot(transport: null);
    hdArrive($unclassifiedLine, '10', '10');

    $rows = collect(InventoryReconciliation::quantities());
    expect($rows->firstWhere('line_id', $clientLine->id))->toMatchArray(['classification' => 'client_custody', 'valued' => null, 'physical_held' => '10.00', 'ok' => true])
        ->and($rows->firstWhere('line_id', $unclassifiedLine->id))->toMatchArray(['classification' => 'unclassified', 'valued' => null])
        ->and(InventoryValueMovement::count())->toBe(0)->and(dr('assets.inventory'))->toBe(0);
});

it('never duplicates a correction: repeated syncs and a second reversal of the same cost change nothing', function () {
    [$lot, [$line], $transport] = hdLot();
    hdEnrol($lot);
    hdArrive($line, '10', '10');
    $expense = hdPayCost($transport);
    hdRefund($expense);
    $count = InventoryValueMovement::count();

    InventoryValuation::syncLot($lot->id);
    InventoryValuation::syncLot($lot->id);

    expect(InventoryValueMovement::count())->toBe($count)->and(InventoryValueMovement::where('type', 'correction')->count())->toBe(1)
        ->and(fn () => ExpenseService::reverse($expense->refresh(), 'refund_received', 'Again', $this->finance))->toThrow(RuntimeException::class, 'already')
        ->and(hdFigures($line)['landed'])->toBe(0);
});

it('refuses to reverse a capitalised cost when the month is closed, and leaves everything as it was', function () {
    [$lot, [$line], $transport] = hdLot();
    hdEnrol($lot);
    hdArrive($line, '10', '10');
    $expense = hdPayCost($transport);
    AccountingPeriod::create(['month' => '2026-11', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    $entries = JournalEntry::count();

    expect(fn () => ExpenseService::reverse($expense->refresh(), 'refund_received', 'Refund', $this->finance))->toThrow(RuntimeException::class, 'closed')
        ->and(JournalEntry::count())->toBe($entries)->and(hdFigures($line)['landed'])->toBe(300_000);
});
