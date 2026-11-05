<?php

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\FinancialAccount;
use App\Models\InventoryEngineEnrolment;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\ReconciliationException;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\AdvanceSettlementService;
use App\Services\EnrolmentReadiness;
use App\Services\InventoryEngine;
use App\Services\InventoryValuation;
use App\Services\ProjectInventory;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Sellbees-owned enrolment through the screens. The worked lot is 100 hoodies at BDT 500 (50,000.00) with BDT 5,000.00 transport: 55,000.00 of inventory at 550.00 a unit.
| "Today" is 2026-11-10 and the engine starts in November.
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
    $this->project = $this->co->project($this->co->client('Enrol Client'), 'Warehouse fit-out');
});

afterEach(fn () => Carbon::setTestNow());

/** A priced and approved lot that is NOT enrolled yet (the screens do that). @return array{0: App\Models\SourcingLot, 1: App\Models\SourcingLotLine, 2: App\Models\SourcingLotCost} */
function enLot(bool $document = true): array
{
    $finance = test()->finance;
    $lot = Sourcing::createLot(['title' => 'Lot '.Str::random(4), 'purchase_order' => 'PO-1'], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => test()->supplier->id, 'description' => 'Zipper Hoodie', 'quantity' => '100', 'unit_price' => 50_000], $finance);
    $cost = Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => 500_000, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 10_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);
    if ($document) {
        Document::create(['subject_type' => 'sourcing_lot', 'subject_id' => $lot->id, 'name' => 'Signed purchase order', 'path' => 'finance-documents/po.pdf', 'uploaded_by' => $finance->id]);
    }

    return [$lot->refresh(), $line->refresh(), $cost->refresh()];
}

function enRequest(string $who, $lot, string $evidence = 'Board resolution 2026/11 and signed purchase order PO-1')
{
    return test()->co->post($who, 'sourcing.enrolment.store', ['classification_source' => $evidence, 'effective_on' => '2026-11-01'], $lot);
}

/** Everything that follows enrolment: supplier payment, arrival, transport, settlement and allocation, as the screens and services do them. */
function enFinish($lot, $line, $cost): void
{
    $finance = test()->finance;
    $payment = SourcingPayments::recordSupplierPayment($lot->refresh(), ['supplier_id' => test()->supplier->id, 'amount' => 5_000_000, 'paid_on' => '2026-11-03', 'method' => 'bank', 'purchase_order' => 'PO-1',
        'reference' => 'R'.Str::random(4), 'evidence_reference' => 'E-1', 'idempotency_key' => (string) Str::uuid()], $finance)->load('expense');
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $payment->expense)->assertSessionHasNoErrors();
    SourcingArrival::record($line->refresh(), '100', '100', '2026-11-08', $finance, 'DN-1');
    SourcingPayments::payCost($cost->refresh(), '2026-11-09', $finance);
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $cost->refresh()->expense)->assertSessionHasNoErrors();
    foreach (AdvanceSettlementService::propose($lot->refresh(), test()->supplier->id, $finance) as $s) {
        AdvanceSettlementService::decide($s, true, test()->board);
    }
    Sourcing::allocate($lot->refresh(), ['project_id' => test()->project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '100'], $finance);
}

it('shows each lot with its facts and blocks a lot whose ownership has no evidence, however it is requested', function () {
    [$withDocument] = enLot();
    [$bare] = enLot(false);                                                                   // ownership unknown, nothing uploaded: the SL-000001 situation
    $ready = EnrolmentReadiness::lots()->keyBy(fn ($r) => $r['lot']->id);
    expect($ready[$withDocument->id]['ready'])->toBeTrue()->and($ready[$withDocument->id]['warnings'])->not->toBeEmpty()
        ->and($ready[$bare->id]['ready'])->toBeFalse()->and($ready[$bare->id]['blockers'][0])->toContain('no supporting document');
    $this->co->as('finance')->get(route('sourcing.enrolment'))->assertOk()->assertSee($bare->number)->assertSee('no supporting document is on the lot')->assertSee('Request enrolment');

    enRequest('finance', $bare)->assertSessionHas('error');                                    // the server refuses it even if a form were forged
    expect(InventoryEngineEnrolment::count())->toBe(0)->and($bare->refresh()->ownership)->toBeNull();

    ReconciliationException::create(['key' => 'lot:ownership:'.$withDocument->id, 'type' => 'lot_ownership', 'subject' => 'x', 'detail' => 'x', 'evidence_required' => 'x', 'opened_by' => $this->finance->id]);
    enRequest('finance', $withDocument)->assertSessionHas('error');                           // an open historical ownership item also blocks it
    expect(EnrolmentReadiness::for($withDocument)['blockers'][0])->toContain('Historical Reconciliation');
});

it('is requested by Finance and decided by a different Board member; nothing is posted or moved by the enrolment itself', function () {
    [$lot, $line] = enLot();
    $journals = JournalEntry::count();
    $stock = StockMovement::count();
    enRequest('finance', $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $e = InventoryEngineEnrolment::firstOrFail();
    expect($e->status)->toBe('pending')->and($e->prepared_by)->toBe($this->finance->id)->and($lot->refresh()->ownership)->toBeNull();

    $this->co->post('finance', 'sourcing.enrolment.decide', ['decision' => 'approve'], $e)->assertForbidden();            // only the Board decides
    $this->co->post('clients', 'sourcing.enrolment.decide', ['decision' => 'approve'], $e)->assertForbidden();
    $this->co->post('board', 'sourcing.enrolment.decide', ['decision' => 'reject'], $e)->assertSessionHas('error');         // a rejection needs its reason
    $this->co->post('board', 'sourcing.enrolment.decide', ['decision' => 'approve'], $e)->assertSessionHasNoErrors();

    expect($e->refresh()->status)->toBe('approved')->and($e->approved_by)->toBe($this->board->id)->and($lot->refresh()->ownership)->toBe('company')
        ->and(InventoryEngine::isValued($lot))->toBeTrue()->and(JournalEntry::count())->toBe($journals)->and(StockMovement::count())->toBe($stock);
    expect(AuditLog::where('action', 'inventory.engine_proposed')->count())->toBe(1)->and(AuditLog::where('action', 'inventory.engine_approved')->count())->toBe(1);
});

it('lets nobody approve their own request', function () {
    [$lot] = enLot();
    $both = SellbeesCompany::user('both@audit.test', 'finance', 'board_member');
    $this->co->users['both'] = $both;
    enRequest('both', $lot)->assertSessionHasNoErrors();
    $e = InventoryEngineEnrolment::firstOrFail();
    $this->co->post('both', 'sourcing.enrolment.decide', ['decision' => 'approve'], $e)->assertSessionHas('error');
    expect($e->refresh()->status)->toBe('pending')->and($lot->refresh()->ownership)->toBeNull();
});

it('refuses a second enrolment of the same lot at every stage, and lets a rejected one be requested again with the history kept', function () {
    [$lot] = enLot();
    enRequest('finance', $lot)->assertSessionHasNoErrors();
    enRequest('finance', $lot)->assertSessionHas('error');                                     // while pending
    $e = InventoryEngineEnrolment::firstOrFail();
    $this->co->post('board', 'sourcing.enrolment.decide', ['decision' => 'reject', 'note' => 'The purchase order is not attached'], $e)->assertSessionHasNoErrors();
    expect($e->refresh()->status)->toBe('rejected')->and(InventoryEngine::isValued($lot))->toBeFalse();

    enRequest('finance', $lot, 'Signed purchase order PO-1 attached to the lot')->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(InventoryEngineEnrolment::count())->toBe(1)->and($e->refresh()->status)->toBe('pending')->and($e->decision_note)->toBeNull();
    $this->co->post('board', 'sourcing.enrolment.decide', ['decision' => 'approve'], $e)->assertSessionHasNoErrors();
    enRequest('finance', $lot)->assertSessionHas('error');                                     // after approval
    $this->co->post('board', 'sourcing.enrolment.decide', ['decision' => 'approve'], $e)->assertSessionHas('error');   // and a decision cannot be repeated
    expect(InventoryEngineEnrolment::count())->toBe(1)->and(AuditLog::where('action', 'like', 'inventory.engine_%')->pluck('action')->all())
        ->toBe(['inventory.engine_proposed', 'inventory.engine_rejected', 'inventory.engine_reproposed', 'inventory.engine_approved']);
    $this->co->as('finance')->get(route('sourcing.enrolment'))->assertOk()->assertSee('reproposed')->assertSee('rejected')->assertSee('The purchase order is not attached', false);
});

it('values the accepted goods once, puts them in the project inventory position, and reconciles stock, subledger and journal', function () {
    [$lot, $line, $cost] = enLot();
    enRequest('finance', $lot)->assertSessionHasNoErrors();
    $this->co->post('board', 'sourcing.enrolment.decide', ['decision' => 'approve'], InventoryEngineEnrolment::firstOrFail())->assertSessionHasNoErrors();
    enFinish($lot, $line, $cost);

    expect(dr('assets.inventory'))->toBe(5_500_000)->and(InventoryValuation::lineValue($line->refresh()))->toBe(5_500_000)->and(StockMovement::where('type', 'receipt')->count())->toBe(1);
    $row = ProjectInventory::positions(['project' => $this->project->id])->first();
    expect($row['project']->id)->toBe($this->project->id)->and($row['ownership'])->toBe('sellbees')->and($row['accepted'])->toBe('100.00')->and($row['b']['available'])->toBe('100.00')
        ->and($row['valuation'])->toBe('valued')->and($row['asset_value'])->toBe(5_500_000);

    // Re-running the valuation (a double click, a retry) applies nothing twice.
    $movements = InventoryValueMovement::count();
    InventoryValuation::syncLot($lot->id);
    InventoryValuation::syncLot($lot->id);
    expect(InventoryValueMovement::count())->toBe($movements)->and(dr('assets.inventory'))->toBe(5_500_000);

    Artisan::call('inventory:reconcile');
    $out = Artisan::output();
    expect($out)->toContain('Reconciled.')->and($out)->toContain('Duplicate movements: 0');
});

it('shows the screen to staff who may read it and the controls only to those who may use them', function () {
    [$lot] = enLot();
    enRequest('finance', $lot)->assertSessionHasNoErrors();
    $this->co->as('board')->get(route('sourcing.enrolment'))->assertOk()->assertSee('Approve')->assertSee('Reject');
    $this->co->as('finance')->get(route('sourcing.enrolment'))->assertOk()->assertDontSee('name="decision"', false);
    $this->co->as('auditor')->get(route('sourcing.enrolment'))->assertOk()->assertDontSee('Request enrolment')->assertDontSee('name="decision"', false);
    $this->co->as('employee')->get(route('sourcing.enrolment'))->assertForbidden();
    $this->co->post('auditor', 'sourcing.enrolment.store', ['classification_source' => 'Signed purchase order PO-1', 'effective_on' => '2026-11-01'], $lot)->assertForbidden();
});

it('explains a switched-off engine instead of offering enrolment', function () {
    config(['finance.inventory_engine_from' => null]);
    [$lot] = enLot();
    $this->co->as('finance')->get(route('sourcing.enrolment'))->assertOk()->assertSee('switched off');
    expect(EnrolmentReadiness::for($lot)['ready'])->toBeFalse();
    enRequest('finance', $lot)->assertSessionHas('error');
    expect(InventoryEngineEnrolment::count())->toBe(0);
});
