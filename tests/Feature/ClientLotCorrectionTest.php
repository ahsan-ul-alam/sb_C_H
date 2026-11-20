<?php

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\SourcingArrivalEvent;
use App\Models\SourcingLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Services\Inventory;
use App\Services\LotFinancials;
use App\Services\ProjectEconomics;
use App\Services\Sourcing;
use App\Services\StockLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Edit lot: an admin changes a client lot at any stage. Reference lot: 100 × BDT 150, delivery BDT 800, budget BDT 20,000 → cost 15,800, profit 4,200.
| "Today" is 2026-11-10; amounts in paisa. No payment is needed anywhere in the workflow.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'cash', 'finance.sourcing_recognition_trigger' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->co->invest('A', '100000', '2026-11-02');
    $this->supplier = Supplier::create(['name' => 'Dhaka Ceramics']);
    $this->product = SupplierProduct::create(['supplier_id' => $this->supplier->id, 'name' => 'Coffee Mug', 'unit' => 'pcs', 'reference_price' => 15_000]);
    $this->client = $this->co->client('Acme Retail');
    $this->project = $this->co->project($this->client, 'Corporate Gifts');
});

afterEach(fn () => Carbon::setTestNow());

function ceCreate(): SourcingLot
{
    test()->co->post('finance', 'sourcing.client-lots.store', ['supplier_id' => test()->supplier->id, 'supplier_product_id' => test()->product->id, 'project_id' => test()->project->id,
        'quantity' => '100', 'unit_cost' => '150', 'client_budget' => '20000', 'submission_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertSessionMissing('error');

    return SourcingLot::latest('id')->firstOrFail();
}

function ceOrder(SourcingLot $lot): SourcingLot
{
    test()->co->post('finance', 'sourcing.lots.order', ['delivery_charge' => '800'], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');

    return $lot->refresh();
}

function ceComplete(SourcingLot $lot, array $extra = []): SourcingLot
{
    test()->co->post('finance', 'sourcing.lots.complete', $extra + ['arrived_on' => '2026-11-09', 'reference' => 'GRN-1'], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');

    return $lot->refresh();
}

/** Post the edit form: the lot's current values, with the given changes. */
function ceEdit(SourcingLot $lot, array $changes, string $who = 'finance')
{
    $lot = $lot->fresh();
    $line = $lot->lines()->first();
    $delivery = (int) $lot->costs()->where('category', 'Delivery')->sum('amount');
    $event = SourcingArrivalEvent::where('sourcing_lot_line_id', $line->id)->latest('id')->first();

    return test()->co->post($who, 'sourcing.lots.correct', $changes + [
        'supplier_id' => $line->supplier_id, 'supplier_product_id' => $line->supplier_product_id, 'project_id' => $lot->project_id,
        'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'), 'unit_cost' => (string) ($line->unit_price / 100), 'client_budget' => (string) ($lot->client_budget / 100),
        'delivery_charge' => (string) ($delivery / 100), 'stage' => $lot->stage(), 'arrived_on' => $line->arrived_on?->toDateString(), 'reference' => $event?->reference,
        'location_id' => $event?->stock_location_id, 'notes' => $lot->notes,
    ], $lot);
}

function ceOk($response): void
{
    $response->assertSessionHasNoErrors()->assertSessionMissing('error')->assertSessionHas('status');
}

it('edits a Processing lot: quantity, cost and budget, and the figures follow', function () {
    $lot = ceCreate();

    ceOk(ceEdit($lot, ['quantity' => '120', 'unit_cost' => '140', 'client_budget' => '25000']));

    $f = LotFinancials::for($lot->refresh());
    expect($lot->stage())->toBe('processing')->and($f['purchase'])->toBe(1_680_000)->and($f['revenue'])->toBe(2_500_000)->and($f['profit'])->toBe(820_000)
        ->and(StockMovement::count())->toBe(0);
});

it('edits an In order lot: the delivery charge changes the total cost and the expected profit', function () {
    $lot = ceOrder(ceCreate());

    ceOk(ceEdit($lot, ['delivery_charge' => '1200']));

    $f = LotFinancials::for($lot->refresh());
    expect($lot->stage())->toBe('in_order')->and($f['landed'])->toBe(1_620_000)->and($f['profit'])->toBe(380_000)->and($lot->costs()->count())->toBe(1);
});

it('edits a Completed lot: budget, base cost and delivery after completion, with the exact profit and the project profitability updated', function () {
    $lot = ceComplete(ceOrder(ceCreate()));
    expect(ProjectEconomics::for($this->project)['sourcing_margin'])->toBe(420_000);

    ceOk(ceEdit($lot, ['client_budget' => '22000']));
    expect(LotFinancials::for($lot->refresh())['profit'])->toBe(620_000)->and(ProjectEconomics::for($this->project)['sourcing_margin'])->toBe(620_000);

    ceOk(ceEdit($lot, ['unit_cost' => '160']));
    expect(LotFinancials::for($lot->refresh())['profit'])->toBe(2_200_000 - 1_600_000 - 80_000);

    ceOk(ceEdit($lot, ['delivery_charge' => '500']));
    $f = LotFinancials::for($lot->refresh());
    expect($f['purchase'])->toBe(1_600_000)->and($f['landed'])->toBe(1_650_000)->and($f['profit'])->toBe(550_000)
        ->and($lot->stage())->toBe('completed')->and($lot->approved_cost)->toBe(1_650_000)->and($lot->approved_revenue)->toBe(2_200_000)
        ->and(ProjectEconomics::for($this->project)['sourcing_margin'])->toBe(550_000);
});

it('shows a negative profit when an edit puts the budget below the cost', function () {
    $lot = ceComplete(ceOrder(ceCreate()));

    ceOk(ceEdit($lot, ['client_budget' => '15000']));

    expect(LotFinancials::for($lot->refresh())['profit'])->toBe(-80_000);
    $this->co->as('finance')->get(route('sourcing.lots.show', $lot))->assertOk()->assertSee('Final loss')->assertSee('-800.00');
});

it('changes the quantity after receiving by adjusting the existing stock, never receiving again, and a repeated edit adds nothing', function () {
    $lot = ceComplete(ceOrder(ceCreate()));
    $line = $lot->lines()->first();

    ceOk(ceEdit($lot, ['quantity' => '90']));

    $line->refresh();
    $event = SourcingArrivalEvent::sole();
    expect((string) $line->quantity)->toBe('90.00')->and((string) $line->received_quantity)->toBe('90.00')->and((string) $line->usable_quantity)->toBe('90.00')
        ->and(Inventory::onHand($line))->toBe('90.00')->and(Inventory::reconciles($line))->toBeTrue()
        ->and((string) $event->received_quantity)->toBe('90.00')->and((string) $event->accepted_quantity)->toBe('90.00')
        ->and(SourcingArrivalEvent::count())->toBe(1)->and(LotFinancials::for($lot->refresh())['purchase'])->toBe(1_350_000);
    $movements = StockMovement::count();

    ceEdit($lot, ['quantity' => '90'])->assertSessionHas('error', 'Nothing was changed.');
    expect(StockMovement::count())->toBe($movements);

    ceOk(ceEdit($lot, ['quantity' => '110']));
    expect(Inventory::onHand($line))->toBe('110.00')->and(Inventory::reconciles($line->refresh()))->toBeTrue()->and(SourcingArrivalEvent::count())->toBe(1);
});

it('refuses to lower the quantity below what is still in stock and explains why, changing nothing', function () {
    $lot = ceComplete(ceOrder(ceCreate()));
    $line = $lot->lines()->first();
    Inventory::adjust($line, '-95', 'Sold outside the system', $this->co->users['finance']); // only 5 left on the shelf

    ceEdit($lot, ['quantity' => '80'])->assertSessionHas('error', fn ($e) => str_contains($e, 'Only 5 of Coffee Mug are still in stock'));

    expect((string) $line->refresh()->quantity)->toBe('100.00')->and(Inventory::balances($line)['available'])->toBe('5.00');
});

it('changes the product after receiving without receiving again: the stock stays on the lot', function () {
    $tea = SupplierProduct::create(['supplier_id' => $this->supplier->id, 'name' => 'Tea Cup', 'unit' => 'pcs', 'reference_price' => 9_500]);
    $lot = ceComplete(ceOrder(ceCreate()));
    $movements = StockMovement::count();

    ceOk(ceEdit($lot, ['supplier_product_id' => $tea->id]));

    $line = $lot->lines()->first();
    expect($line->supplier_product_id)->toBe($tea->id)->and($line->description)->toBe('Tea Cup')->and(Inventory::onHand($line))->toBe('100.00')
        ->and(StockMovement::count())->toBe($movements)->and(SourcingArrivalEvent::count())->toBe(1);
});

it('refuses a product change once units were reserved or sold, with the way to correct it', function () {
    $tea = SupplierProduct::create(['supplier_id' => $this->supplier->id, 'name' => 'Tea Cup', 'unit' => 'pcs', 'reference_price' => 9_500]);
    $lot = ceComplete(ceOrder(ceCreate()));
    Inventory::move($lot->lines()->first(), 'reserve', ['available' => '-5', 'reserved' => '5'], null, null, $this->co->users['finance'], 'Reserved for an order');

    ceEdit($lot, ['supplier_product_id' => $tea->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'already sold, reserved or handed over'));

    expect($lot->lines()->first()->supplier_product_id)->toBe($this->product->id);
});

it('changes the supplier and product after completion', function () {
    $other = Supplier::create(['name' => 'Chittagong Pottery']);
    $bowl = SupplierProduct::create(['supplier_id' => $other->id, 'name' => 'Bowl', 'unit' => 'pcs', 'reference_price' => 12_000]);
    $lot = ceComplete(ceOrder(ceCreate()));

    ceOk(ceEdit($lot, ['supplier_id' => $other->id, 'supplier_product_id' => $bowl->id]));

    $line = $lot->lines()->first();
    expect($line->supplier_id)->toBe($other->id)->and($line->description)->toBe('Bowl')->and(Inventory::onHand($line))->toBe('100.00');
    ceEdit($lot, ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $bowl->id])->assertSessionHas('error', 'Choose a product supplied by Dhaka Ceramics.');
});

it('changes the stock location after receiving with a transfer of the units, and a repeat moves nothing', function () {
    $main = StockLocations::create('MAIN', 'Main warehouse');
    $uttara = StockLocations::create('UTT', 'Uttara store');
    $lot = ceComplete(ceOrder(ceCreate()), ['location_id' => $main->id]);
    $line = $lot->lines()->first();

    ceOk(ceEdit($lot, ['location_id' => $uttara->id]));

    expect(StockLocations::availableAt($line, $uttara->id))->toBe('100.00')->and(StockLocations::availableAt($line, $main->id))->toBe('0.00')
        ->and(Inventory::onHand($line))->toBe('100.00')->and(SourcingArrivalEvent::sole()->stock_location_id)->toBe($uttara->id);
    $movements = StockMovement::count();
    ceEdit($lot, ['location_id' => $uttara->id])->assertSessionHas('error', 'Nothing was changed.');
    expect(StockMovement::count())->toBe($movements);
});

it('changes the received date and the delivery note after receiving', function () {
    $lot = ceComplete(ceOrder(ceCreate()));

    ceOk(ceEdit($lot, ['arrived_on' => '2026-11-07', 'reference' => 'GRN-7']));

    $line = $lot->lines()->first();
    expect($line->arrived_on->toDateString())->toBe('2026-11-07')->and($line->acceptance_reference)->toBe('GRN-7')
        ->and(SourcingArrivalEvent::sole()->reference)->toBe('GRN-7')->and(SourcingArrivalEvent::sole()->arrived_on->toDateString())->toBe('2026-11-07');
});

it('changes the project after completion and the profit moves to the new project', function () {
    $lot = ceComplete(ceOrder(ceCreate()));
    $other = $this->co->project($this->co->client('Beta Shop'), 'Beta Launch');

    ceOk(ceEdit($lot, ['project_id' => $other->id]));

    expect($lot->refresh()->project_id)->toBe($other->id)
        ->and(ProjectEconomics::for($this->project->refresh())['sourcing_margin'])->toBe(0)->and(ProjectEconomics::for($other)['sourcing_margin'])->toBe(420_000);
});

it('posts no cash, expense, journal or invoice for any edit, and leaves payments entered separately untouched', function () {
    $lot = ceOrder(ceCreate());
    $this->co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $this->supplier->id, 'amount' => '15000', 'paid_on' => '2026-11-05',
        'reference' => 'PAY-1', 'evidence_reference' => 'INV-1', 'idempotency_key' => (string) Str::uuid()], $lot)->assertSessionMissing('error');
    $this->co->post('finance', 'sourcing.costs.pay', ['paid_on' => '2026-11-06'], $lot->costs()->sole())->assertSessionMissing('error');
    $lot = ceComplete($lot);
    $journals = JournalEntry::count();
    $expenses = Expense::orderBy('id')->get(['id', 'amount', 'status'])->toArray();

    ceOk(ceEdit($lot, ['unit_cost' => '140', 'delivery_charge' => '500', 'client_budget' => '21000', 'quantity' => '95']));

    expect(JournalEntry::count())->toBe($journals)->and(Expense::orderBy('id')->get(['id', 'amount', 'status'])->toArray())->toBe($expenses)
        ->and(SupplierPayment::sole()->amount)->toBe(1_500_000)->and(Invoice::count())->toBe(0)
        ->and($lot->costs()->sole()->amount)->toBe(50_000)->and(LotFinancials::for($lot->refresh())['landed'])->toBe(1_330_000 + 50_000);
});

it('moves the stage freely after receiving without touching stock, and only Mark Completed completes a lot the first time', function () {
    $processing = ceCreate();
    ceEdit($processing, ['stage' => 'completed'])->assertSessionHas('error', 'Use Mark Completed to receive the goods; the lot becomes Completed then.');

    $lot = ceComplete(ceOrder(ceCreate()));
    ceOk(ceEdit($lot, ['stage' => 'in_order']));
    expect($lot->refresh()->stage())->toBe('in_order')->and(Inventory::onHand($lot->lines()->first()))->toBe('100.00');
    ceOk(ceEdit($lot, ['stage' => 'completed']));
    expect($lot->refresh()->stage())->toBe('completed')->and(SourcingArrivalEvent::count())->toBe(1);
});

it('keeps stock and quantity consistent when two admins edit from the same old form one after the other', function () {
    $lot = ceComplete(ceOrder(ceCreate()));
    $line = $lot->lines()->first();
    $stale = ['supplier_id' => $line->supplier_id, 'supplier_product_id' => $line->supplier_product_id, 'project_id' => $lot->project_id, 'quantity' => '100', 'unit_cost' => '150',
        'client_budget' => '20000', 'delivery_charge' => '800', 'stage' => 'completed', 'arrived_on' => '2026-11-09', 'reference' => 'GRN-1'];

    ceOk($this->co->post('finance', 'sourcing.lots.correct', ['quantity' => '90'] + $stale, $lot));
    ceOk($this->co->post('clients', 'sourcing.lots.correct', ['client_budget' => '21000'] + $stale, $lot)); // the second admin still had quantity 100 on screen

    $line->refresh();
    expect(Inventory::onHand($line))->toBe(bcadd((string) $line->usable_quantity, '0', 2))->and(Inventory::reconciles($line))->toBeTrue()
        ->and((string) SourcingArrivalEvent::sole()->accepted_quantity)->toBe((string) $line->usable_quantity)->and($lot->refresh()->client_budget)->toBe(2_100_000);
});

it('lets every lot manager edit, refuses others, and records each change in the activity history', function () {
    $lot = ceComplete(ceOrder(ceCreate()));

    $this->co->as('auditor')->post(route('sourcing.lots.correct', $lot), ['quantity' => '1'])->assertForbidden();
    ceOk(ceEdit($lot, ['client_budget' => '21000', 'reason' => 'Client agreed a higher price'], 'clients'));

    $this->co->as('finance')->get(route('sourcing.lots.show', $lot))->assertOk()->assertSee('Edit lot')->assertSee('Activity')
        ->assertSee('client budget 20,000.00 → 21,000.00')->assertSee('Client agreed a higher price');
});

it('leaves an older lot priced per line to the detailed view', function () {
    $finance = $this->co->users['finance'];
    $lot = Sourcing::createLot(['title' => 'Legacy lot'], $finance);
    Sourcing::addLine($lot, ['supplier_id' => $this->supplier->id, 'description' => 'Hoodie', 'quantity' => '10', 'unit_price' => 400_000], $finance);

    $this->co->post('finance', 'sourcing.lots.correct', ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'project_id' => $this->project->id,
        'quantity' => '10', 'unit_cost' => '4000', 'client_budget' => '50000', 'delivery_charge' => '0', 'stage' => 'processing'], $lot)
        ->assertSessionHas('error', 'Only a lot made on the simple client-lot form can be edited here; manage this lot from the detailed view.');

    expect($lot->refresh()->client_budget)->toBeNull();
});
