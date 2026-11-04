<?php

use App\Models\AccountingPeriod;
use App\Models\CostRecognition;
use App\Models\FinancialAccount;
use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Services\AdvanceSettlementService;
use App\Services\Inventory;
use App\Services\InventoryEngine;
use App\Services\InventoryHandoverService;
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
| Phase 2C-1: client handover (transfer of control) and quantity-based cost of goods sold. Amounts are integer paisa. The worked lot is 100 units bought at
| BDT 500 (BDT 50,000) with BDT 5,000 of capitalised transport: BDT 55,000 of inventory, BDT 550 a unit. "Today" is 2026-11-10.
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
    $this->client = $this->co->client('Handover Client');
    $this->project = $this->co->project($this->client, 'Warehouse fit-out');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A lot enrolled as company-owned, accepted, settled and (optionally) costed: returns [lot, line, allocation].
 */
function hoLot(string $quantity = '100', int $price = 50_000, ?int $transport = 500_000, bool $settle = true, ?string $allocate = null, ?string $ownership = 'company'): array
{
    $finance = test()->finance;
    $lot = Sourcing::createLot(['title' => 'Lot '.Str::random(4)], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => test()->supplier->id, 'description' => 'Zipper Hoodie', 'quantity' => $quantity, 'unit_price' => $price], $finance);
    $cost = $transport ? Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => $transport, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance) : null;
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 10_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);
    $lot->refresh();
    if ($ownership !== null) {
        InventoryEngine::decide(InventoryEngine::propose($lot, $ownership, 'Board resolution 2026/11 and the signed purchase order', '2026-11-01', $finance), true, test()->board);
    }
    $payment = SourcingPayments::recordSupplierPayment($lot->refresh(), ['supplier_id' => test()->supplier->id, 'amount' => (int) round($price * (float) $quantity), 'paid_on' => '2026-11-03', 'method' => 'bank',
        'purchase_order' => 'PO-1', 'reference' => 'R'.Str::random(4), 'evidence_reference' => 'E-1', 'idempotency_key' => (string) Str::uuid()], $finance)->load('expense');
    hoVerify($payment->expense);
    SourcingArrival::record($line->refresh(), $quantity, $quantity, '2026-11-08', $finance, 'DN-'.$line->id);
    if ($cost) {
        SourcingPayments::payCost($cost->refresh(), '2026-11-09', $finance);
        hoVerify($cost->refresh()->expense);
    }
    if ($settle) {
        foreach (AdvanceSettlementService::propose($lot->refresh(), test()->supplier->id, $finance) as $s) {
            AdvanceSettlementService::decide($s, true, test()->board);
        }
    }
    $allocation = Sourcing::allocate($lot->refresh(), ['project_id' => test()->project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => $allocate ?? $quantity], $finance);

    return [$lot->refresh(), $line->refresh(), $allocation];
}

function hoVerify($expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

function hoPropose($allocation, string $quantity, string $on = '2026-11-09', string $evidence = 'Signed receiving note RN-2026-114', ?string $key = null)
{
    return InventoryHandoverService::propose($allocation->refresh(), $quantity, '2026-11-09', $on, $evidence, null, $key ?? (string) Str::uuid(), test()->finance);
}

function hoApprove(InventoryHandover $h): InventoryHandover
{
    InventoryHandoverService::decide($h, true, test()->board);

    return $h->refresh();
}

function hoCogs(): int
{
    return dr('cogs');
}

it('posts nothing for a request, then releases the exact cost of the whole quantity on approval: BDT 55,000 of inventory to cost of goods sold', function () {
    [$lot, $line, $allocation] = hoLot();
    expect(dr('assets.inventory'))->toBe(5_500_000)->and(InventoryValuation::lineValue($line))->toBe(5_500_000);

    $handover = hoPropose($allocation, '100');
    // A proposal, an allocation and an invoiced charge move nothing.
    expect($handover->status)->toBe('pending')->and(hoCogs())->toBe(0)->and(dr('assets.inventory'))->toBe(5_500_000)->and(InventoryValueMovement::where('type', 'issue')->count())->toBe(0)
        ->and(JournalEntry::where('source_type', 'inventory_handover')->count())->toBe(0);

    hoApprove($handover);

    // Dr cogs 5,500,000 / Cr inventory 5,500,000: purchase 5,000,000 + landed 500,000, exactly what the layers held.
    $entry = JournalEntry::where('source_type', 'inventory_handover')->where('source_id', $handover->id)->firstOrFail();
    expect($handover->status)->toBe('approved')->and($handover->cogs_value)->toBe(5_500_000)->and($handover->purchase_value)->toBe(5_000_000)->and($handover->landed_value)->toBe(500_000)
        ->and(hoCogs())->toBe(5_500_000)->and(dr('assets.inventory'))->toBe(0)->and($entry->lines->pluck('account')->sort()->values()->all())->toBe(['assets.inventory', 'cogs'])
        ->and(InventoryValuation::lineFigures($line->refresh()))->toBe(['purchase' => 0, 'landed' => 0, 'quantity' => '0.00', 'purchase_valued' => 5_000_000])
        ->and(InventoryValueMovement::where('type', 'issue')->where('project_id', $this->project->id)->count())->toBe(3)
        ->and(InventoryReconciliation::company()['ok'])->toBeTrue()->and(ledgerAudit()['unbalanced'])->toBe(0);
});

it('recognises only the transferred quantity of a partial handover and leaves the rest valued exactly', function () {
    [$lot, $line, $allocation] = hoLot();

    $first = hoApprove(hoPropose($allocation, '40'));
    // 40 of 100 units at BDT 550 = BDT 22,000; BDT 33,000 stays in inventory.
    expect($first->cogs_value)->toBe(2_200_000)->and($first->purchase_value)->toBe(2_000_000)->and($first->landed_value)->toBe(200_000)->and(hoCogs())->toBe(2_200_000)->and(dr('assets.inventory'))->toBe(3_300_000)
        ->and(InventoryValuation::lineValue($line->refresh()))->toBe(3_300_000)->and(InventoryValuation::lineFigures($line)['quantity'])->toBe('60.00');

    // The last units take the remainder: what is issued over time adds up to exactly what was valued.
    $second = hoApprove(hoPropose($allocation, '60'));
    expect($second->cogs_value)->toBe(3_300_000)->and(hoCogs())->toBe(5_500_000)->and(dr('assets.inventory'))->toBe(0)->and(InventoryValuation::lineValue($line->refresh()))->toBe(0)
        ->and(InventoryHandoverService::eligibleQuantity($allocation->refresh()))->toBe('0.00')->and(InventoryReconciliation::handovers()['ok'])->toBeTrue();
});

it('never drifts on rounding: three single units of an indivisible value add up to the exact total', function () {
    [$lot, $line, $allocation] = hoLot('3', 33_334, 100);   // purchase 100,002 + landed 100 = 100,102 over three units
    expect(InventoryValuation::lineValue($line))->toBe(100_102);

    $costs = [];
    foreach ([1, 2, 3] as $i) {
        $costs[] = hoApprove(hoPropose($allocation, '1'))->cogs_value;
    }

    expect($costs)->toBe([33_367, 33_368, 33_367])->and(array_sum($costs))->toBe(100_102)->and(hoCogs())->toBe(100_102)->and(dr('assets.inventory'))->toBe(0)
        ->and(InventoryReconciliation::company()['ok'])->toBeTrue();
});

it('keeps several allocations of one lot apart and traces cost of goods sold to each project', function () {
    [$lot, $line, $first] = hoLot(allocate: '50');
    $otherProject = $this->co->project($this->client, 'Second project');
    $second = Sourcing::allocate($lot->refresh(), ['project_id' => $otherProject->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '50'], $this->finance);

    hoApprove(hoPropose($first, '50'));
    hoApprove(hoPropose($second, '50'));

    $by = InventoryReconciliation::handovers()['by_project'];
    expect($by[$this->project->id])->toBe(2_750_000)->and($by[$otherProject->id])->toBe(2_750_000)->and(hoCogs())->toBe(5_500_000)
        ->and(InventoryValueMovement::where('type', 'issue')->where('project_id', $otherProject->id)->sum('value_delta'))->toBe(-2_750_000);
});

it('refuses more than the eligible quantity, counting pending requests, and frees it when a request is cancelled or rejected', function () {
    [$lot, $line, $allocation] = hoLot();

    expect(fn () => hoPropose($allocation, '101'))->toThrow(RuntimeException::class, 'Only 100.00')
        ->and(fn () => hoPropose($allocation, '0'))->toThrow(RuntimeException::class, 'greater than zero');
    $pending = hoPropose($allocation, '60');
    expect(fn () => hoPropose($allocation, '50'))->toThrow(RuntimeException::class, 'Only 40.00');   // the pending 60 is counted

    expect(fn () => InventoryHandoverService::cancel($pending, $this->finance, '  '))->toThrow(RuntimeException::class, 'reason');
    InventoryHandoverService::cancel($pending, $this->finance, 'Client postponed');
    expect($pending->refresh()->status)->toBe('cancelled')->and(InventoryHandoverService::eligibleQuantity($allocation->refresh()))->toBe('100.00')
        ->and(hoCogs())->toBe(0);

    $rejected = hoPropose($allocation, '60');
    expect(fn () => InventoryHandoverService::decide($rejected, false, $this->board))->toThrow(RuntimeException::class, 'reason');
    InventoryHandoverService::decide($rejected, false, $this->board, 'Evidence does not match the delivery');
    expect($rejected->refresh()->status)->toBe('rejected')->and(InventoryHandoverService::eligibleQuantity($allocation->refresh()))->toBe('100.00')->and(hoCogs())->toBe(0)
        ->and(fn () => InventoryHandoverService::cancel($rejected, $this->finance, 'late'))->toThrow(RuntimeException::class, 'not been decided');

    $approved = hoApprove(hoPropose($allocation, '10'));
    expect(fn () => InventoryHandoverService::cancel($approved, $this->finance, 'Changed mind'))->toThrow(RuntimeException::class, 'undone by a return');
});

it('applies a handover once: the same request twice, a second approval, and a second posting all change nothing', function () {
    [$lot, $line, $allocation] = hoLot();
    $key = (string) Str::uuid();

    $a = hoPropose($allocation, '40', key: $key);
    $b = hoPropose($allocation, '40', key: $key);
    expect($b->id)->toBe($a->id)->and(InventoryHandover::count())->toBe(1);

    hoApprove($a);
    $count = InventoryValueMovement::count();
    expect(fn () => InventoryHandoverService::decide($a->refresh(), true, $this->board))->toThrow(RuntimeException::class, 'Already decided')
        ->and(fn () => Ledger::post('2026-11-09', 'again', 'inventory_handover', $a->id, [['cogs', null, 1, 0], ['assets.inventory', null, 0, 1]], $this->board->id))->toThrow(RuntimeException::class, 'already been posted')
        ->and(InventoryValueMovement::count())->toBe($count)->and(hoCogs())->toBe(2_200_000)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('does not hand over stock that is not fully valued, and checks again at approval', function () {
    [$lot, $line, $allocation] = hoLot(settle: false);   // accepted, but no settlement verified: no valued quantity, no invented cost
    $handover = hoPropose($allocation, '10');

    expect(fn () => InventoryHandoverService::decide($handover, true, $this->board))->toThrow(RuntimeException::class, 'not fully valued')
        ->and($handover->refresh()->status)->toBe('pending')->and(hoCogs())->toBe(0)->and(InventoryValueMovement::where('type', 'issue')->count())->toBe(0);
});

it('refuses ownership that is not Sellbees-owned and engine-valued: client custody, unclassified and unenrolled lots', function () {
    [$clientLot, $clientLine, $clientAllocation] = hoLot(ownership: 'client');
    expect(fn () => hoPropose($clientAllocation, '10'))->toThrow(RuntimeException::class, 'Only Sellbees-owned stock');

    [$unclassified, $unclassifiedLine, $unclassifiedAllocation] = hoLot(ownership: null);
    expect($unclassified->ownership)->toBeNull()->and(fn () => hoPropose($unclassifiedAllocation, '10'))->toThrow(RuntimeException::class, 'Only Sellbees-owned stock')
        ->and(hoCogs())->toBe(0)->and(InventoryHandover::count())->toBe(0);
});

it('requires real evidence of the transfer of control, and the date cannot be in the future', function () {
    [$lot, $line, $allocation] = hoLot();

    expect(fn () => hoPropose($allocation, '10', evidence: ''))->toThrow(RuntimeException::class, 'evidence')
        ->and(fn () => hoPropose($allocation, '10', evidence: 'inv'))->toThrow(RuntimeException::class, 'An invoice or a payment is not evidence')
        ->and(fn () => hoPropose($allocation, '10', on: '2026-11-11'))->toThrow(RuntimeException::class, 'future date');
});

it('lets nobody approve their own request, and only an authorised Board approver decide', function () {
    [$lot, $line, $allocation] = hoLot();
    $handover = hoPropose($allocation, '10');

    expect(fn () => InventoryHandoverService::decide($handover, true, $this->finance))->toThrow(RuntimeException::class, 'you proposed')
        ->and(fn () => InventoryHandoverService::decide($handover, true, $this->co->users['clients']))->toThrow(RuntimeException::class, 'Board member')
        ->and($handover->refresh()->status)->toBe('pending')->and(hoCogs())->toBe(0);
    // A second Board member can decide it.
    InventoryHandoverService::decide($handover, true, $this->co->users['board2']);
    expect($handover->refresh()->approved_by)->toBe($this->co->users['board2']->id);
});

it('refuses a handover while the stock is reserved for an order or the month of the handover is closed', function () {
    [$lot, $line, $allocation] = hoLot();
    Inventory::move($line->refresh(), 'reserve', ['available' => '-5', 'reserved' => '5']);
    $reserved = hoPropose($allocation, '10');
    expect(fn () => InventoryHandoverService::decide($reserved, true, $this->board))->toThrow(RuntimeException::class, 'reserved');
    Inventory::move($line->refresh(), 'release', ['reserved' => '-5', 'available' => '5']);
    InventoryHandoverService::cancel($reserved, $this->finance, 'retry');

    $closing = hoPropose($allocation, '10', on: '2026-10-30');
    AccountingPeriod::create(['month' => '2026-10', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    expect(fn () => InventoryHandoverService::decide($closing, true, $this->board))->toThrow(RuntimeException::class, 'already closed')
        ->and($closing->refresh()->status)->toBe('pending')->and(hoCogs())->toBe(0);
});

it('restores the exact original cost when resalable goods come back, in parts, and never more than was handed over', function () {
    [$lot, $line, $allocation] = hoLot();
    $handover = hoApprove(hoPropose($allocation, '40'));   // cost released 2,200,000

    $first = InventoryHandoverService::proposeReturn($handover, '10', 'resalable', '2026-11-10', 'Return note RT-77 signed by the client', 'Wrong size', (string) Str::uuid(), $this->finance);
    expect(hoCogs())->toBe(2_200_000);   // a request restores nothing
    InventoryHandoverService::decideReturn($first, true, $this->board);

    // 10 of 40 units: 550,000 back at the original cost (BDT 550 a unit), Dr inventory / Cr cogs.
    expect($first->refresh()->restored_value)->toBe(550_000)->and(hoCogs())->toBe(1_650_000)->and(dr('assets.inventory'))->toBe(3_850_000)->and(InventoryValuation::lineFigures($line->refresh())['quantity'])->toBe('70.00');
    expect(fn () => InventoryHandoverService::proposeReturn($handover->refresh(), '31', 'resalable', '2026-11-10', 'Return note RT-78', 'x', (string) Str::uuid(), $this->finance))->toThrow(RuntimeException::class, 'Only 30.00');

    $second = InventoryHandoverService::proposeReturn($handover->refresh(), '30', 'resalable', '2026-11-10', 'Return note RT-78 signed by the client', 'Order cancelled', (string) Str::uuid(), $this->finance);
    InventoryHandoverService::decideReturn($second, true, $this->board);

    expect($second->refresh()->restored_value)->toBe(1_650_000)->and(hoCogs())->toBe(0)->and(dr('assets.inventory'))->toBe(5_500_000)->and($handover->refresh()->netQuantity())->toBe('0.00')
        ->and(InventoryValuation::lineValue($line->refresh()))->toBe(5_500_000)->and(InventoryReconciliation::company()['ok'])->toBeTrue()
        ->and(fn () => InventoryHandoverService::decideReturn($second, true, $this->board))->toThrow(RuntimeException::class, 'Already decided')
        ->and(fn () => InventoryHandoverService::decideReturn(InventoryHandoverService::proposeReturn($handover->refresh(), '1', 'resalable', '2026-11-10', 'Return note RT-79', 'x', (string) Str::uuid(), $this->finance), true, $this->board))->toThrow(RuntimeException::class, 'Only 0.00');
});

it('does not turn a damaged return back into resalable inventory, and keeps the cost of goods sold until it is classified', function () {
    [$lot, $line, $allocation] = hoLot();
    $handover = hoApprove(hoPropose($allocation, '40'));
    $entries = JournalEntry::count();

    $damaged = InventoryHandoverService::proposeReturn($handover, '10', 'damaged', '2026-11-10', 'Return note RT-80 noting water damage', 'Damaged in transit', (string) Str::uuid(), $this->finance);
    InventoryHandoverService::decideReturn($damaged, true, $this->board);

    // Control is back and the quantity is recorded, but no value is restored: nothing is posted and the stock is not valued again.
    expect(JournalEntry::count())->toBe($entries)->and(hoCogs())->toBe(2_200_000)->and(dr('assets.inventory'))->toBe(3_300_000)->and(InventoryValuation::lineFigures($line->refresh())['quantity'])->toBe('60.00')
        ->and($handover->refresh()->damaged_returned_quantity)->toBe('10.00')->and($handover->netQuantity())->toBe('30.00')->and($damaged->refresh()->restored_value)->toBe(0)
        ->and(InventoryReconciliation::handovers()['damaged_awaiting_classification'])->toBe(1);

    // The rest comes back resalable: 30 of the 40 units' cost returns, and the damaged 10 units' cost stays in cost of goods sold.
    $rest = InventoryHandoverService::proposeReturn($handover->refresh(), '30', 'resalable', '2026-11-10', 'Return note RT-81 signed by the client', 'Order cancelled', (string) Str::uuid(), $this->finance);
    InventoryHandoverService::decideReturn($rest, true, $this->board);
    expect($rest->refresh()->restored_value)->toBe(1_650_000)->and(hoCogs())->toBe(550_000)->and(InventoryReconciliation::handovers()['ok'])->toBeTrue();
});

it('corrects a return in the open period when the original handover month is closed, leaving the original journal untouched', function () {
    Carbon::setTestNow('2026-11-25');
    [$lot, $line, $allocation] = hoLot();
    $handover = hoApprove(hoPropose($allocation, '40', on: '2026-11-20'));
    $original = JournalEntry::where('source_type', 'inventory_handover')->where('source_id', $handover->id)->firstOrFail();
    $snapshot = $original->lines->map(fn ($l) => [$l->account, $l->debit, $l->credit])->all();

    Carbon::setTestNow('2026-12-05');
    AccountingPeriod::create(['month' => '2026-11', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    $return = InventoryHandoverService::proposeReturn($handover, '10', 'resalable', '2026-11-28', 'Return note RT-90 signed by the client', 'Wrong colour', (string) Str::uuid(), $this->finance);
    InventoryHandoverService::decideReturn($return, true, $this->board);

    $correction = JournalEntry::where('source_type', 'inventory_handover_return')->where('source_id', $return->id)->firstOrFail();
    expect($correction->entry_date->toDateString())->toBe('2026-12-05')->and($correction->memo)->toContain('original period closed')
        ->and($original->fresh()->lines->map(fn ($l) => [$l->account, $l->debit, $l->credit])->all())->toBe($snapshot)->and($original->fresh()->entry_date->toDateString())->toBe('2026-11-20')
        ->and(hoCogs())->toBe(1_650_000)->and(expect(fn () => InventoryHandoverService::proposeReturn($handover->refresh(), '1', 'resalable', '2026-11-10', 'Return note RT-91', 'x', (string) Str::uuid(), $this->finance))->toThrow(RuntimeException::class, 'before control passed'));
});

it('reconciles cost of goods sold to the handovers, projects and journals, inventory to the ledger, and flags what cannot be right', function () {
    [$lot, $line, $allocation] = hoLot();
    hoApprove(hoPropose($allocation, '40'));
    $report = InventoryReconciliation::company();

    expect($report['ok'])->toBeTrue()->and($report['gl_inventory'])->toBe(3_300_000)->and($report['engine_subledger'])->toBe(3_300_000)->and($report['engine_journal'])->toBe(3_300_000)
        ->and($report['handovers']['gl_cogs'])->toBe(2_200_000)->and($report['handovers']['handover_net_cogs'])->toBe(2_200_000)->and($report['handovers']['difference'])->toBe(0)
        ->and($report['handovers']['rows'][0])->toMatchArray(['cogs_value' => 2_200_000, 'journal_cogs' => 2_200_000, 'movement_value' => 2_200_000]);
    // Quantities: 60 valued, 40 handed over and still in our warehouse as the client's custody, 100 physical.
    $q = collect(InventoryReconciliation::quantities())->firstWhere('line_id', $line->id);
    expect($q)->toMatchArray(['accepted' => '100.00', 'valued' => '60.00', 'handed_over' => '40.00', 'physical_held' => '100.00', 'ok' => true]);

    // A lot whose cost was released as a whole lot after the fact (recognised twice) is reported, never hidden.
    CostRecognition::forceCreate(['sourcing_lot_id' => $lot->id, 'amount' => 1, 'recognised_on' => '2026-11-09', 'trigger' => 'lot_completed', 'status' => 'verified', 'created_by' => $this->finance->id, 'verified_by' => $this->board->id]);
    expect(InventoryReconciliation::handovers()['ok'])->toBeFalse()->and(implode(' ', InventoryReconciliation::handovers()['problems']))->toContain('recognised twice');
});

it('serves the handover screens with the same rules: request, pending approval, own request, history, detail and permissions', function () {
    [$lot, $line, $allocation] = hoLot();

    $page = $this->co->as('finance')->get(route('sourcing.handovers'))->assertOk()->getContent();
    expect($page)->toContain('New handover request')->and($page)->toContain('Zipper Hoodie')->and($page)->toContain('100.00 left to hand over');

    $input = ['sourcing_allocation_id' => $allocation->id, 'quantity' => '40', 'proposed_on' => '2026-11-09', 'transferred_on' => '2026-11-09', 'evidence_reference' => 'Signed receiving note RN-2026-114', 'idempotency_key' => (string) Str::uuid()];
    $this->co->post('finance', 'sourcing.handovers.store', $input)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $handover = InventoryHandover::firstOrFail();
    $this->co->post('finance', 'sourcing.handovers.store', $input);   // the same form twice
    $this->co->post('finance', 'sourcing.handovers.store', [...$input, 'evidence_reference' => 'x', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('evidence_reference');
    expect(InventoryHandover::count())->toBe(1);

    $own = $this->co->as('finance')->get(route('sourcing.handovers'))->getContent();
    expect($own)->toContain('Cancel')->and($own)->not->toContain('name="decision" value="approve"');   // the proposer can cancel but is offered no approval
    $this->co->post('clients', 'sourcing.handovers.decide', ['decision' => 'approve'], $handover)->assertForbidden();
    $this->co->post('finance', 'sourcing.handovers.decide', ['decision' => 'approve'], $handover)->assertForbidden();   // finance holds no approval right
    expect($this->co->as('board')->get(route('sourcing.handovers'))->getContent())->toContain('name="decision" value="approve"');

    $this->co->post('board', 'sourcing.handovers.decide', ['decision' => 'approve'], $handover)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($handover->refresh()->status)->toBe('approved')->and(hoCogs())->toBe(2_200_000);
    expect($this->co->as('finance')->get(route('sourcing.handovers'))->getContent())->toContain('Return goods');   // a requester can ask for a return
    $history = $this->co->as('board')->get(route('sourcing.handovers'))->getContent();
    expect($history)->not->toContain('Return goods')->and($history)->toContain('Reconciled');
    $detail = $this->co->as('board')->get(route('sourcing.handovers.show', $handover))->assertOk()->getContent();
    expect($detail)->toContain('Source journal entry')->and($detail)->toContain('cogs')->and($detail)->toContain('Inventory movements');
    $this->co->post('finance', 'sourcing.handovers.returns.store', ['quantity' => '5', 'condition' => 'resalable', 'returned_on' => '2026-11-10', 'evidence_reference' => 'Return note RT-5 signed', 'reason' => 'Wrong item'], $handover)
        ->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(InventoryHandoverReturn::count())->toBe(1);
});
