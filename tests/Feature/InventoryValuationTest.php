<?php

use App\Models\AccountingPeriod;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Services\AdvanceSettlementService;
use App\Services\CostRecognitionService;
use App\Services\ExpenseService;
use App\Services\InventoryEngine;
use App\Services\InventoryReconciliation;
use App\Services\InventoryValuation;
use App\Services\Ledger;
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
| Phase 2B: the inventory value subledger. Amounts are integer paisa. The worked example is the design's: purchase BDT 50,000 (100 units at BDT 500),
| capitalised transport BDT 5,000, so BDT 55,000 of inventory and a unit cost of BDT 550. "Today" is 2026-11-10 and the engine starts in November.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'accrual', 'finance.sourcing_accrual_from' => '2026-11-01',
        'finance.sourcing_recognition_trigger' => null, 'finance.inventory_engine_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->bank = FinancialAccount::factory()->asDefault()->create(['name' => 'City Bank']);
    $this->co->invest('A', '1000000', '2026-11-02');
    $this->supplier = Supplier::create(['name' => 'Uttara Buying House']);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A costed, approved lot: $lines = list of [quantity, unit price in paisa]; $transport = shared actual cost. Returns [lot, lines, transport cost].
 *
 * @param  list<array{0: string, 1: int}>  $lines
 */
function ivLot(array $lines = [['100', 50_000]], ?int $transport = 500_000, ?string $ownership = null, string $basis = 'value'): array
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
    $lot->update(['allocation_basis' => $basis] + ($ownership ? ['ownership' => $ownership] : []));

    return [$lot->refresh(), collect($made)->map->refresh()->all(), $cost];
}

function ivVerify(Expense $expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

function ivEnrol($lot, string $ownership = 'company', string $on = '2026-11-01')
{
    $enrolment = InventoryEngine::propose($lot, $ownership, 'Board resolution 2026/11 and the signed purchase order', $on, test()->finance);
    InventoryEngine::decide($enrolment, true, test()->board);

    return $enrolment->refresh();
}

function ivAdvance($lot, int $amount): void
{
    $payment = SourcingPayments::recordSupplierPayment($lot->refresh(), ['supplier_id' => test()->supplier->id, 'amount' => $amount, 'paid_on' => '2026-11-03', 'method' => 'bank', 'purchase_order' => 'PO-1',
        'reference' => 'R'.Str::random(4), 'evidence_reference' => 'E-1', 'idempotency_key' => (string) Str::uuid()], test()->finance)->load('expense');
    ivVerify($payment->expense);
}

function ivArrive($line, string $received, string $accepted): void
{
    SourcingArrival::record($line->refresh(), $received, $accepted, '2026-11-08', test()->finance, 'DN-'.$line->id);
}

/** Finance proposes, the Board verifies. */
function ivSettle($lot): array
{
    $settlements = AdvanceSettlementService::propose($lot->refresh(), test()->supplier->id, test()->finance);
    foreach ($settlements as $s) {
        AdvanceSettlementService::decide($s, true, test()->board);
    }

    return $settlements->all();
}

function ivPayTransport($cost, bool $asPayable = false): Expense
{
    SourcingPayments::payCost($cost->refresh(), '2026-11-09', test()->finance, null, asPayable: $asPayable);
    $expense = $cost->refresh()->expense;
    ivVerify($expense);

    return $expense->refresh();
}

function ivFigures($line): array
{
    return InventoryValuation::lineFigures($line->refresh());
}

it('values fully accepted goods at purchase plus landed cost: BDT 55,000 for 100 units, BDT 550 each', function () {
    [$lot, [$line], $transport] = ivLot();
    ivEnrol($lot);
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '100', '100');

    // Accepted but not settled: no invented value, no stock counted yet.
    expect(ivFigures($line))->toBe(['purchase' => 0, 'landed' => 0, 'quantity' => '0.00'])->and(InventoryValuation::status(InventoryValuation::acceptedValue($line->refresh()), 0))->toBe('pending');

    ivPayTransport($transport);   // BDT 5,000 capitalised: Dr inventory / Cr cash
    ivSettle($lot);               // BDT 50,000 of accepted goods settled from the advance: Dr inventory / Cr advances

    $f = ivFigures($line);
    expect($f)->toBe(['purchase' => 5_000_000, 'landed' => 500_000, 'quantity' => '100.00'])->and(InventoryValuation::lineValue($line))->toBe(5_500_000)
        ->and(InventoryValuation::unitCost($line))->toBe('55000.0000')   // BDT 550 per unit, as a derived figure only
        ->and(dr('assets.inventory'))->toBe(5_500_000)->and(dr('expense'))->toBe(0);
    $row = InventoryReconciliation::lot($lot->refresh());
    expect($row['journal_value'])->toBe(5_500_000)->and($row['subledger_value'])->toBe(5_500_000)->and($row['difference'])->toBe(0)->and($row['ok'])->toBeTrue()
        ->and($row['lines'][0]['status'])->toBe('valued');
});

it('values only the accepted quantity when receipt is partial, and rejected goods never become inventory', function () {
    [$lot, [$line]] = ivLot(transport: null);
    ivEnrol($lot);
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '90', '80'); // 100 ordered, 90 arrived, 80 accepted, 10 rejected, 10 missing
    ivSettle($lot);

    expect(ivFigures($line))->toBe(['purchase' => 4_000_000, 'landed' => 0, 'quantity' => '80.00'])  // 80 x BDT 500
        ->and(dr('assets.inventory'))->toBe(4_000_000)->and(dr('assets.supplier_advances'))->toBe(1_000_000) // the rest of the advance stays an advance
        ->and(InventoryReconciliation::lot($lot->refresh())['ok'])->toBeTrue();

    // A later acceptance of more units is valued only when its own settlement is verified.
    ivArrive($line, '100', '100');
    expect(ivFigures($line)['purchase'])->toBe(4_000_000)->and(ivFigures($line)['quantity'])->toBe('0.00'); // accepted 100 but only 80 valued: pending again, nothing issuable
    expect(fn () => InventoryValuation::assertIssuable($line->refresh()))->toThrow(RuntimeException::class, 'not fully valued');
    ivSettle($lot);
    expect(ivFigures($line))->toBe(['purchase' => 5_000_000, 'landed' => 0, 'quantity' => '100.00']);
});

it('carries no value for accepted goods until a settlement is verified, and refuses to issue them', function () {
    [$lot, [$line], $transport] = ivLot();
    ivEnrol($lot);
    ivArrive($line, '100', '100');
    ivPayTransport($transport);

    $row = InventoryReconciliation::lot($lot->refresh());
    expect(ivFigures($line)['purchase'])->toBe(0)->and($row['unvalued_accepted'])->toBe(5_000_000)->and($row['lines'][0]['status'])->toBe('pending')
        ->and($row['ok'])->toBeFalse()->and(InventoryValueMovement::where('component', 'quantity')->count())->toBe(0)
        ->and(fn () => InventoryValuation::assertIssuable($line->refresh()))->toThrow(RuntimeException::class, 'not fully valued');
});

it('capitalises a transport payable only when verified, at its full cost whether or not it is paid, and takes a correction back out', function () {
    [$lot, [$line], $transport] = ivLot();
    ivEnrol($lot);
    ivArrive($line, '100', '100');

    SourcingPayments::payCost($transport->refresh(), '2026-11-09', $this->finance, null, asPayable: true); // incurred, unpaid: awaiting verification
    expect(ivFigures($line)['landed'])->toBe(0);
    $expense = $transport->refresh()->expense;
    ivVerify($expense);
    expect(ivFigures($line)['landed'])->toBe(500_000);

    // Part-paid: BDT 1,000 of BDT 5,000 paid. The cost is incurred in full, so the capitalised value does not change.
    ExpenseService::pay($expense->refresh(), ['amount' => 100_000, 'paid_on' => '2026-11-09'], $this->finance);
    expect(ivFigures($line)['landed'])->toBe(500_000)->and(InventoryReconciliation::lot($lot->refresh())['journal_value'])->toBe(500_000);

    // The bill is cancelled (after the payment is voided): the Board-verified correction takes the capitalised value back out.
    ExpenseService::voidPayment($expense->payments()->firstOrFail(), $this->finance);
    $cancel = ExpenseService::reverse($expense->refresh(), null, 'Supplier cancelled the delivery', $this->finance);
    ivVerify($cancel);
    expect(ivFigures($line)['landed'])->toBe(0)->and(InventoryValueMovement::where('type', 'correction')->count())->toBe(1)
        ->and(InventoryReconciliation::lot($lot->refresh())['journal_value'])->toBe(0);
});

it('spreads shared transport over several products by value, exactly', function () {
    [$lot, [$a, $b], $transport] = ivLot([['10', 500_000], ['4', 250_000]], 300_000); // BDT 50,000 and BDT 10,000 of goods, BDT 3,000 of transport
    ivEnrol($lot);
    ivArrive($a, '10', '10');
    ivArrive($b, '4', '4');
    ivPayTransport($transport);
    ivAdvance($lot, 6_000_000);
    ivSettle($lot);

    expect(ivFigures($a))->toBe(['purchase' => 5_000_000, 'landed' => 250_000, 'quantity' => '10.00'])
        ->and(ivFigures($b))->toBe(['purchase' => 1_000_000, 'landed' => 50_000, 'quantity' => '4.00'])
        ->and(InventoryValuation::lineValue($a) + InventoryValuation::lineValue($b))->toBe(6_300_000)->and(dr('assets.inventory'))->toBe(6_300_000)
        ->and(InventoryReconciliation::lot($lot->refresh())['ok'])->toBeTrue();
});

it('splits shared cost by quantity with largest-remainder rounding: no paisa is lost or invented', function () {
    [$lot, [$a, $b], $transport] = ivLot([['10', 500_000], ['4', 250_000]], 300_000, basis: 'quantity');
    ivEnrol($lot);
    ivArrive($a, '10', '10');
    ivArrive($b, '4', '4');
    ivPayTransport($transport);

    // 300,000 x 10/14 = 214,285.71 and 300,000 x 4/14 = 85,714.29: the larger remainder takes the spare paisa.
    expect(ivFigures($a)['landed'])->toBe(214_286)->and(ivFigures($b)['landed'])->toBe(85_714)->and(ivFigures($a)['landed'] + ivFigures($b)['landed'])->toBe(300_000)
        ->and(InventoryReconciliation::lot($lot->refresh())['journal_value'])->toBe(300_000);
});

it('applies every source event once: repeating a sync, a verification or a settlement adds nothing', function () {
    [$lot, [$line], $transport] = ivLot();
    ivEnrol($lot);
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '100', '100');
    ivPayTransport($transport);
    $settlements = ivSettle($lot);
    $count = InventoryValueMovement::count();

    InventoryValuation::syncLot($lot->id);
    InventoryValuation::syncLot($lot->id);
    expect(InventoryValueMovement::count())->toBe($count)
        ->and(fn () => AdvanceSettlementService::decide($settlements[0], true, $this->board))->toThrow(RuntimeException::class, 'Already decided')
        ->and(AdvanceSettlementService::propose($lot->refresh(), $this->supplier->id, $this->finance))->toBeEmpty()
        ->and(InventoryValueMovement::count())->toBe($count)->and(InventoryReconciliation::company()['duplicates'])->toBe(0)
        ->and(fn () => InventoryValueMovement::create(['sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $line->id, 'ownership' => 'company', 'component' => 'purchase', 'type' => 'add',
            'value_delta' => 1, 'valuation_status' => 'valued', 'source_type' => 'x', 'source_id' => 1, 'move_key' => InventoryValueMovement::first()->move_key, 'effective_on' => '2026-11-10']))->toThrow(Exception::class);
});

it('never values a lot whose ownership is not stated, until an approved classification says who owns it', function () {
    [$lot, [$line]] = ivLot(transport: null);
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '100', '100');
    ivSettle($lot);

    // No enrolment: no movements, ownership stays NULL (never guessed), and the reconciliation says why.
    expect(InventoryValueMovement::count())->toBe(0)->and($lot->refresh()->ownership)->toBeNull()
        ->and(InventoryReconciliation::lot($lot)['exclusion'])->toContain('ownership not stated')->and(InventoryReconciliation::lot($lot)['journal_value'])->toBe(5_000_000);

    // The classification needs its evidence, a second person's approval, and records who decided and when.
    expect(fn () => InventoryEngine::propose($lot, 'company', '  ', '2026-11-01', $this->finance))->toThrow(RuntimeException::class, 'evidence');
    $enrolment = InventoryEngine::propose($lot, 'company', 'Signed purchase order PO-1 in Sellbees\' name', '2026-11-01', $this->finance);
    expect(fn () => InventoryEngine::decide($enrolment, true, $this->finance))->toThrow(RuntimeException::class, 'you proposed');
    InventoryEngine::decide($enrolment, true, $this->board);

    expect($lot->refresh()->ownership)->toBe('company')->and($enrolment->refresh()->approved_by)->toBe($this->board->id)->and($enrolment->classification_source)->toContain('PO-1')
        ->and(ivFigures($line)['purchase'])->toBe(5_000_000)->and(InventoryValueMovement::orderBy('id')->first()->effective_on->toDateString())->toBe('2026-11-08'); // dated at the settlement, after the enrolment
});

it('keeps client-owned stock out of Sellbees\' valuation: custody only', function () {
    [$lot, [$line]] = ivLot(transport: null, ownership: 'client');
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '100', '100');
    ivSettle($lot);

    expect(fn () => InventoryEngine::propose($lot, 'company', 'PO', '2026-11-01', $this->finance))->toThrow(RuntimeException::class, 'already recorded as client-owned');
    $enrolment = ivEnrol($lot, 'client');

    expect($enrolment->isValued())->toBeFalse()->and(InventoryValueMovement::count())->toBe(0)->and(InventoryEngine::isValued($lot))->toBeFalse()
        ->and(InventoryReconciliation::lot($lot->refresh())['exclusion'])->toContain('client-owned');
});

it('keeps a lot whose cost was expensed under the cash basis outside the engine', function () {
    config(['finance.sourcing_basis' => 'cash']);
    [$lot] = ivLot(transport: null);
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $this->supplier->id, 'amount' => 1_000_000, 'paid_on' => '2026-11-03', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    ivVerify($payment->expense);
    config(['finance.sourcing_basis' => 'accrual']);

    expect($payment->expense->refresh()->classification)->toBe('operating')
        ->and(fn () => InventoryEngine::propose($lot, 'company', 'PO', '2026-11-01', $this->finance))->toThrow(RuntimeException::class, 'expensed under the cash basis');
});

it('keeps a lot whose cost was already released as a whole lot outside the engine, and stops the legacy release on an enrolled lot', function () {
    [$released] = ivLot(transport: null);
    CostRecognition::forceCreate(['sourcing_lot_id' => $released->id, 'amount' => 1_000_000, 'recognised_on' => '2026-11-05', 'trigger' => 'lot_completed', 'status' => 'verified', 'created_by' => $this->finance->id, 'verified_by' => $this->board->id]);
    expect(fn () => InventoryEngine::propose($released, 'company', 'PO', '2026-11-01', $this->finance))->toThrow(RuntimeException::class, 'released as a whole lot');

    [$enrolled] = ivLot(transport: null);
    ivEnrol($enrolled);
    config(['finance.sourcing_recognition_trigger' => 'lot_completed']);
    expect(CostRecognitionService::obstacles($enrolled->refresh())[0])->toContain('valued by the inventory engine');
    // A lot that is not enrolled keeps the legacy behaviour exactly.
    [$legacy] = ivLot(transport: null);
    expect(CostRecognitionService::obstacles($legacy->refresh())[0])->not->toContain('inventory engine');
});

it('reconciles the subledger to the ledger lot by lot and company-wide, with legacy lots alongside', function () {
    [$engineLot, [$line], $transport] = ivLot();
    ivEnrol($engineLot);
    ivAdvance($engineLot, 5_000_000);
    ivArrive($line, '100', '100');
    ivPayTransport($transport);
    ivSettle($engineLot);

    // A second lot on the legacy accrual treatment (never enrolled): the ledger carries its value too, and it must not blur the engine lot.
    [$legacyLot, [$legacyLine]] = ivLot([['20', 100_000]], null);
    ivAdvance($legacyLot, 2_000_000);
    ivArrive($legacyLine, '20', '20');
    ivSettle($legacyLot);

    $report = InventoryReconciliation::company();
    $engine = collect($report['lots'])->firstWhere('lot', $engineLot->number);
    $legacy = collect($report['lots'])->firstWhere('lot', $legacyLot->number);

    expect($engine['journal_value'])->toBe(5_500_000)->and($engine['subledger_value'])->toBe(5_500_000)->and($engine['difference'])->toBe(0)
        ->and($legacy['valued'])->toBeFalse()->and($legacy['journal_value'])->toBe(2_000_000)->and($legacy['subledger_value'])->toBeNull()
        ->and($report['gl_inventory'])->toBe(7_500_000)->and($report['journal_by_lot'])->toBe(7_500_000)->and($report['unattributed'])->toBe(0)
        ->and($report['engine_subledger'])->toBe(5_500_000)->and($report['duplicates'])->toBe(0)->and($report['negative_lines'])->toBe(0)->and($report['ok'])->toBeTrue();
    // The command shows the same, and changes nothing.
    $before = InventoryValueMovement::count();
    $this->artisan('inventory:reconcile')->assertSuccessful();
    expect(InventoryValueMovement::count())->toBe($before);
});

it('opens an enrolled lot forward-only: earlier events are valued at the enrolment date, in an open month', function () {
    [$lot, [$line]] = ivLot(transport: null);
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '100', '100');
    ivSettle($lot);
    expect(InventoryValueMovement::count())->toBe(0);   // the settlement happened before the lot was enrolled

    ivEnrol($lot, 'company', '2026-11-09');   // enrolled after the settlement (dated 8 Nov)

    // Nothing is dated before the enrolment: the 8 Nov settlement is carried at the enrolment date; the quantity is dated when it was valued (today).
    expect(InventoryValueMovement::pluck('effective_on')->map->toDateString()->unique()->sort()->values()->all())->toBe(['2026-11-09', '2026-11-10'])
        ->and(ivFigures($line)['purchase'])->toBe(5_000_000)->and(InventoryReconciliation::lot($lot->refresh())['difference'])->toBe(0);
});

it('refuses an enrolment dated before the engine starts or into a closed month, and re-checks at approval', function () {
    [$lot] = ivLot(transport: null);
    expect(fn () => InventoryEngine::propose($lot, 'company', 'PO', '2026-10-15', $this->finance))->toThrow(RuntimeException::class, 'before the month the engine starts');

    config(['finance.inventory_engine_from' => '2026-09-01']);
    AccountingPeriod::create(['month' => '2026-10', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    expect(fn () => InventoryEngine::propose($lot, 'company', 'PO', '2026-10-15', $this->finance))->toThrow(RuntimeException::class, 'already closed');

    $enrolment = InventoryEngine::propose($lot, 'company', 'PO', '2026-11-05', $this->finance);
    AccountingPeriod::create(['month' => '2026-11', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]); // closes before the Board decides
    expect(fn () => InventoryEngine::decide($enrolment, true, $this->board))->toThrow(RuntimeException::class, 'already closed')
        ->and($enrolment->refresh()->status)->toBe('pending')->and($lot->refresh()->ownership)->toBeNull();
});

it('reverses a settlement exactly, and the subledger can neither be edited nor deleted', function () {
    [$lot, [$line]] = ivLot(transport: null);   // (with capitalised cost still on the goods the reversal is refused: see the hardening tests)
    ivEnrol($lot);
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '100', '100');
    $settlements = ivSettle($lot);
    expect(ivFigures($line)['quantity'])->toBe('100.00');

    AdvanceSettlementService::reverse($settlements[0], 'Wrong delivery note', $this->board);

    $reversal = InventoryValueMovement::where('type', 'reversal')->firstOrFail();
    expect(ivFigures($line))->toBe(['purchase' => 0, 'landed' => 0, 'quantity' => '0.00'])   // value back out, quantity withdrawn
        ->and($reversal->value_delta)->toBe(-5_000_000)->and($reversal->reverses_id)->not->toBeNull()->and($reversal->journalEntry->source_type)->toBe('advance_settlement_rev')
        ->and(InventoryReconciliation::lot($lot->refresh())['journal_value'])->toBe(0)->and(InventoryReconciliation::lot($lot)['subledger_value'])->toBe(0);
    $count = InventoryValueMovement::count();
    InventoryValuation::syncLot($lot->id);
    expect(InventoryValueMovement::count())->toBe($count)  // a second sync does not reverse again
        ->and(fn () => $reversal->update(['value_delta' => 0]))->toThrow(LogicException::class)
        ->and(fn () => $reversal->delete())->toThrow(LogicException::class)
        ->and(InventoryReconciliation::company()['negative_lines'])->toBe(0);
});

it('flags value above what was accepted instead of hiding it, when an arrival is corrected downwards after settlement', function () {
    [$lot, [$line]] = ivLot(transport: null);
    ivEnrol($lot);
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '100', '100');
    ivSettle($lot);

    ivArrive($line, '100', '90'); // 10 units found unacceptable after the goods were already settled

    $row = InventoryReconciliation::lot($lot->refresh());
    expect($row['over_valued'])->toBe(500_000)->and($row['ok'])->toBeFalse()->and(collect($row['exceptions'])->implode(' '))->toContain('value above what was accepted');
});

it('puts nothing in the ledger itself: the engine adds no journal entry', function () {
    [$lot, [$line], $transport] = ivLot();
    ivAdvance($lot, 5_000_000);
    ivArrive($line, '100', '100');
    ivPayTransport($transport);
    ivSettle($lot);
    $entries = JournalEntry::count();
    $inventory = dr('assets.inventory');

    ivEnrol($lot); // valuing an existing lot records the subledger only

    expect(JournalEntry::count())->toBe($entries)->and(dr('assets.inventory'))->toBe($inventory)->and(InventoryValueMovement::whereNotNull('journal_entry_id')->count())->toBeGreaterThan(0)
        ->and(InventoryValueMovement::where('value_delta', '!=', 0)->whereNull('journal_entry_id')->count())->toBe(0)
        ->and(Ledger::debitBalance('assets.inventory'))->toBe(InventoryReconciliation::company()['engine_subledger']);
});
