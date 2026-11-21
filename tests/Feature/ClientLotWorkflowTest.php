<?php

use App\Models\AdvanceSettlement;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\SourcingArrivalEvent;
use App\Models\SourcingLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Services\ClientLot;
use App\Services\Inventory;
use App\Services\LotFinancials;
use App\Services\ProjectEconomics;
use App\Services\ProjectSourcingReport;
use App\Services\Sourcing;
use App\Services\StockLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| The simple client-lot workflow: Processing → In order (delivery charge) → Completed (goods received). Cash sourcing basis with real-time
| posting, as configured today. Amounts are paisa: BDT 150 = 15_000. The reference lot is 100 units × BDT 150, delivery BDT 800, client
| budget BDT 20,000 → base 15,000, total cost 15,800, profit 4,200. "Today" is 2026-11-10.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'cash', 'finance.sourcing_recognition_trigger' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->co->invest('A', '100000', '2026-11-02');
    $this->supplier = Supplier::create(['name' => 'Dhaka Ceramics']);
    $this->product = SupplierProduct::create(['supplier_id' => $this->supplier->id, 'name' => 'Coffee Mug', 'unit' => 'pcs', 'reference_price' => 15_000]);
    $this->project = $this->co->project($this->co->client('Acme Retail'), 'Corporate Gifts');
});

afterEach(fn () => Carbon::setTestNow());

function clCreate(array $overrides = [], string $who = 'finance'): SourcingLot
{
    test()->co->post($who, 'sourcing.client-lots.store', $overrides + [
        'supplier_id' => test()->supplier->id, 'supplier_product_id' => test()->product->id, 'project_id' => test()->project->id,
        'quantity' => '100', 'unit_cost' => '150', 'client_budget' => '20000', 'submission_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors()->assertSessionMissing('error');

    return SourcingLot::latest('id')->firstOrFail();
}

function clOrder(SourcingLot $lot, string $delivery = '800'): SourcingLot
{
    test()->co->post('finance', 'sourcing.lots.order', ['delivery_charge' => $delivery], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');

    return $lot->refresh();
}

function clPaySupplier(SourcingLot $lot, string $amount = '15000'): void
{
    test()->co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => test()->supplier->id, 'amount' => $amount, 'paid_on' => '2026-11-05',
        'reference' => 'PAY-'.Str::random(4), 'evidence_reference' => 'INV-1', 'idempotency_key' => (string) Str::uuid()], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

function clComplete(SourcingLot $lot, array $extra = [], string $who = 'finance')
{
    return test()->co->post($who, 'sourcing.lots.complete', $extra + ['arrived_on' => '2026-11-09', 'reference' => 'GRN-100'], $lot);
}

/** The ledger's cash balance (debits minus credits on the cash account). */
function clCash(): int
{
    return (int) JournalLine::where('account', 'cash')->sum('debit') - (int) JournalLine::where('account', 'cash')->sum('credit');
}

function clIncome(): int
{
    return (int) JournalLine::where('account', 'income')->sum('credit') - (int) JournalLine::where('account', 'income')->sum('debit');
}

// ---------------------------------------------------------------- Creation

it('creates a Processing lot with the base cost and estimated profit, and records no money', function () {
    $journals = JournalEntry::count();

    $lot = clCreate();

    $f = LotFinancials::for($lot);
    expect($lot->stage())->toBe('processing')->and($lot->status)->toBe('approved')
        ->and($lot->client_budget)->toBe(2_000_000)->and($lot->project_id)->toBe($this->project->id)
        ->and($f['purchase'])->toBe(1_500_000)->and($f['revenue'])->toBe(2_000_000)->and($f['profit'])->toBe(500_000)
        ->and($lot->lines()->sole()->supplier_product_id)->toBe($this->product->id)
        // The budget is agreed revenue, not cash: no receipt, invoice or journal is created by saving the lot.
        ->and(Payment::count())->toBe(0)->and(Invoice::count())->toBe(0)->and(JournalEntry::count())->toBe($journals)
        ->and($f['billed'])->toBe(0)->and($f['received'])->toBe(0);
});

it('shows only the simple form with product rows, with no advanced lot forms', function () {
    $this->co->as('finance')->get(route('sourcing.lots.create'))->assertOk()
        ->assertSeeInOrder(['Project', 'Supplier', 'Product', 'Quantity', 'Unit base cost (BDT)', 'Add another product', 'Client lot budget (BDT)', 'Create lot'])
        ->assertDontSee('Advanced')->assertDontSee('empty lot')
        ->assertSee('Corporate Gifts — Acme Retail')->assertSee('Dhaka Ceramics');
});

it('shows a client lot page with only the steps, the financial summary, Edit and the activity: no advance, payment, billing or settlement', function () {
    $lot = clOrder(clCreate());
    clComplete($lot)->assertSessionMissing('error');

    $this->co->as('finance')->get(route('sourcing.lots.show', $lot))->assertOk()
        ->assertSee('Processing')->assertSee('In order')->assertSee('Completed')->assertSee('Financial summary')->assertSee('Edit lot')->assertSee('Activity')
        ->assertDontSee('Advanced')->assertDontSee('Emergency Fund')->assertDontSee('Reason for audited adjustment')->assertDontSee('Record a payment')
        ->assertDontSee('Close lot')->assertDontSee('Bill client')->assertDontSee('Advance settlement')->assertDontSee('name="supplier_paid"', false);
});

it('does not create a second lot when the same form is submitted twice', function () {
    $data = ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'project_id' => $this->project->id,
        'quantity' => '100', 'unit_cost' => '150', 'client_budget' => '20000', 'submission_key' => (string) Str::uuid()];

    $this->co->post('finance', 'sourcing.client-lots.store', $data);
    $this->co->post('finance', 'sourcing.client-lots.store', $data)->assertSessionHas('warning', 'This lot was already saved; nothing was duplicated.');

    expect(SourcingLot::count())->toBe(1);
});

it('takes the budget the admin entered as agreed, with no approval wait, whatever the size of the lot', function () {
    $lot = clCreate(['quantity' => '10000', 'client_budget' => '2000000']); // no standing policy exists in this test

    expect($lot->status)->toBe('approved')->and($lot->approved_by)->toBe($this->co->users['finance']->id)
        ->and($lot->approved_revenue)->toBe(200_000_000)->and($lot->approved_cost)->toBe(150_000_000);
    expect(clOrder($lot, '0')->stage())->toBe('in_order');
});

it('accepts a budget below cost as an expected loss', function () {
    $lot = clCreate(['client_budget' => '14000']);

    expect($lot->status)->toBe('approved')->and(LotFinancials::for($lot)['profit'])->toBe(-100_000);
});

it('lets a client lot saved before budgets were approved on entry go straight into order', function () {
    $lot = clCreate();
    $lot->update(['status' => 'priced']);
    $lot->lines()->update(['approved_price' => null]);

    expect(clOrder($lot, '800')->stage())->toBe('in_order')->and($lot->lines()->sole()->approved_price)->toBe(20_000);
});

it('refuses a product that the chosen supplier does not supply', function () {
    $other = Supplier::create(['name' => 'Other Supplier']);
    $foreign = SupplierProduct::create(['supplier_id' => $other->id, 'name' => 'Soap', 'unit' => 'pcs', 'reference_price' => 900]);

    $this->co->post('finance', 'sourcing.client-lots.store', ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $foreign->id, 'project_id' => $this->project->id,
        'quantity' => '10', 'unit_cost' => '9', 'client_budget' => '200'])->assertSessionHas('error', 'Choose a product supplied by Dhaka Ceramics.');

    expect(SourcingLot::count())->toBe(0);
});

/** A second supplier with its own product, for lots that mix suppliers. */
function clPlate(): SupplierProduct
{
    $glass = Supplier::create(['name' => 'Chittagong Glassware']);

    return SupplierProduct::create(['supplier_id' => $glass->id, 'name' => 'Dinner Plate', 'unit' => 'pcs', 'reference_price' => 25_000]);
}

it('creates one lot with several products from different suppliers, splitting the budget across them exactly', function () {
    $plate = clPlate();

    $lot = clCreate(['items' => [
        ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'quantity' => '100', 'unit_cost' => '150'],
        ['supplier_id' => $plate->supplier_id, 'supplier_product_id' => $plate->id, 'quantity' => '40', 'unit_cost' => '250'],
    ], 'client_budget' => '30000']);

    $f = LotFinancials::for($lot);
    expect($lot->lines()->count())->toBe(2)
        ->and($lot->lines()->orderBy('position')->pluck('supplier_product_id')->all())->toBe([$this->product->id, $plate->id])
        ->and($lot->lines()->orderBy('position')->pluck('supplier_id')->all())->toBe([$this->supplier->id, $plate->supplier_id])
        ->and($f['purchase'])->toBe(2_500_000)->and($f['revenue'])->toBe(3_000_000)->and($f['profit'])->toBe(500_000)
        ->and(array_sum(LotFinancials::budgetShares($lot)))->toBe(3_000_000)
        ->and($lot->status)->toBe('approved');

    $this->co->as('finance')->get(route('sourcing.lots.show', $lot))->assertOk()->assertSee('Coffee Mug')->assertSee('Dinner Plate')->assertSee('2 products')
        ->assertSee('Suppliers')->assertSee('Dhaka Ceramics')->assertSee('Chittagong Glassware');
});

it('refuses a row whose product is not supplied by that row\'s supplier', function () {
    $plate = clPlate();

    $this->co->post('finance', 'sourcing.client-lots.store', ['project_id' => $this->project->id, 'client_budget' => '2000', 'items' => [
        ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $plate->id, 'quantity' => '5', 'unit_cost' => '250'],
    ]])->assertSessionHas('error', 'Choose a product supplied by Dhaka Ceramics.');

    expect(SourcingLot::count())->toBe(0);
});

it('receives every product of a multi-supplier lot on completion and bills the whole budget to the paisa', function () {
    $plate = clPlate();
    $lot = clOrder(clCreate(['items' => [
        ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '150'],
        ['supplier_id' => $plate->supplier_id, 'supplier_product_id' => $plate->id, 'quantity' => '7', 'unit_cost' => '250'],
    ], 'client_budget' => '10000']));

    clComplete($lot)->assertSessionMissing('error');

    expect($lot->refresh()->stage())->toBe('completed')
        ->and($lot->lines()->whereNull('received_quantity')->count())->toBe(0);

    $this->co->post('finance', 'sourcing.lots.bill-client', ['project_id' => $this->project->id], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect((int) Invoice::sole()->total)->toBe(1_000_000)->and(LotFinancials::for($lot->refresh())['revenue'])->toBe(1_000_000);
});

it('refuses the same product twice on one lot', function () {
    $this->co->post('finance', 'sourcing.client-lots.store', ['project_id' => $this->project->id, 'client_budget' => '2000', 'items' => [
        ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '150'],
        ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '150'],
    ]])->assertSessionHasErrors(['items.0.supplier_product_id' => 'Each product can be added only once; change its quantity instead.']);

    expect(SourcingLot::count())->toBe(0);
});

it('edits every product of a multi-supplier lot, including moving a product to another supplier', function () {
    $plate = clPlate();
    $bowl = SupplierProduct::create(['supplier_id' => $this->supplier->id, 'name' => 'Soup Bowl', 'unit' => 'pcs', 'reference_price' => 20_000]);
    $lot = clCreate(['items' => [
        ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'quantity' => '100', 'unit_cost' => '150'],
        ['supplier_id' => $plate->supplier_id, 'supplier_product_id' => $plate->id, 'quantity' => '40', 'unit_cost' => '250'],
    ], 'client_budget' => '30000']);
    [$mug, $plateLine] = $lot->lines()->orderBy('position')->get()->all();

    $this->co->post('finance', 'sourcing.lots.correct', ['project_id' => $this->project->id, 'client_budget' => '30000',
        'delivery_charge' => '0', 'stage' => 'processing', 'lines' => [
            $mug->id => ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'quantity' => '100', 'unit_cost' => '150'],
            $plateLine->id => ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $bowl->id, 'quantity' => '50', 'unit_cost' => '240'],
        ]], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');

    $plateLine->refresh();
    expect((string) $plateLine->quantity)->toBe('50.00')->and((int) $plateLine->unit_price)->toBe(24_000)
        ->and($plateLine->supplier_id)->toBe($this->supplier->id)->and($plateLine->supplier_product_id)->toBe($bowl->id)
        ->and(LotFinancials::for($lot->refresh())['purchase'])->toBe(2_700_000);
});

it('refuses an inactive product and an archived project', function () {
    $this->product->update(['is_active' => false]);
    $this->co->post('finance', 'sourcing.client-lots.store', ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'project_id' => $this->project->id,
        'quantity' => '10', 'unit_cost' => '150', 'client_budget' => '2000'])->assertSessionHas('error', 'Coffee Mug is inactive.');

    $this->product->update(['is_active' => true]);
    $this->project->forceFill(['archived_at' => now()])->save();
    $this->co->post('finance', 'sourcing.client-lots.store', ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'project_id' => $this->project->id,
        'quantity' => '10', 'unit_cost' => '150', 'client_budget' => '2000'])->assertSessionHas('error', 'Choose an active client project.');

    expect(SourcingLot::count())->toBe(0);
});

it('requires a whole positive quantity and every field of the form', function (array $input, string $field, string $message) {
    $this->co->post('finance', 'sourcing.client-lots.store', $input + ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id,
        'project_id' => $this->project->id, 'quantity' => '10', 'unit_cost' => '150', 'client_budget' => '2000'])->assertSessionHasErrors([$field => $message]);

    expect(SourcingLot::count())->toBe(0);
})->with([
    'fractional quantity' => [['quantity' => '2.5'], 'quantity', 'Quantity must be a whole number greater than zero.'],
    'zero quantity' => [['quantity' => '0'], 'quantity', 'Quantity must be a whole number greater than zero.'],
    'missing project' => [['project_id' => ''], 'project_id', 'The project id field is required.'],
    'invalid budget' => [['client_budget' => '-5'], 'client_budget', 'Enter the client lot budget as an amount, e.g. 20000.'],
]);

it('takes the unit base cost the admin enters, whatever the catalog price, with no override approval', function () {
    $this->co->post('clients', 'sourcing.client-lots.store', ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $this->product->id, 'project_id' => $this->project->id,
        'quantity' => '10', 'unit_cost' => '140', 'client_budget' => '2000', 'submission_key' => (string) Str::uuid()])->assertSessionMissing('error');

    expect(SourcingLot::sole()->lines()->sole()->unit_price)->toBe(14_000)->and(SourcingLot::sole()->stage())->toBe('processing');
});

// ---------------------------------------------------------------- In order

it('puts the lot in order with the delivery charge: total cost 15,800 and expected profit 4,200, no profit posted', function () {
    $lot = clCreate();
    $journals = JournalEntry::count();

    $lot = clOrder($lot, '800');

    $f = LotFinancials::for($lot);
    $delivery = $lot->costs()->sole();
    expect($lot->stage())->toBe('in_order')->and($lot->ordered_by)->toBe($this->co->users['finance']->id)->and($lot->ordered_at)->not->toBeNull()
        ->and($f['purchase'])->toBe(1_500_000)->and($f['landed'])->toBe(1_580_000)->and($f['profit'])->toBe(420_000)
        ->and($delivery->category)->toBe('Delivery')->and($delivery->amount)->toBe(80_000)->and($delivery->is_unexpected)->toBeFalse()
        ->and(JournalEntry::count())->toBe($journals)->and(Expense::count())->toBe(0);
});

it('accepts a zero delivery charge for free delivery without recording a cost', function () {
    $lot = clOrder(clCreate(), '0');

    expect($lot->stage())->toBe('in_order')->and($lot->costs()->count())->toBe(0)->and(LotFinancials::for($lot)['profit'])->toBe(500_000);
});

it('requires the delivery charge in the In order step', function () {
    $lot = clCreate();

    $this->co->post('finance', 'sourcing.lots.order', [], $lot)->assertSessionHasErrors(['delivery_charge' => 'Enter the delivery charge (0 for free delivery).']);

    expect($lot->refresh()->stage())->toBe('processing');
});

it('shows the delivery charge confirmation with the lot figures on a Processing lot', function () {
    $lot = clCreate();

    $this->co->as('finance')->get(route('sourcing.lots.show', $lot))->assertOk()
        ->assertSee('Ready to order')->assertSee('Mark In order')->assertSee('Delivery charge (BDT)')->assertSee('150.00 / unit', false)->assertSee('15,000.00')->assertSee('20,000.00')
        ->assertDontSee('Mark Completed')->assertDontSee('Waiting for approval')->assertDontSee('Board verification');
});

it('shows a lot in order as awaiting product receiving, with the completion popup', function () {
    $lot = clOrder(clCreate());

    $this->co->as('finance')->get(route('sourcing.lots.show', $lot))->assertOk()
        ->assertSee('Awaiting product receiving')->assertSee('Mark Completed')->assertSee('Received on')->assertSee('Delivery note / GRN no.')->assertSee('15,800.00')->assertSee('4,200.00')
        ->assertDontSee('name="supplier_paid"', false)->assertDontSee('name="delivery_paid"', false)->assertDontSee('Supplier has been paid') // no payment questions
        ->assertDontSee('Mark In order')->assertDontSee('Bill client');
});

it('refuses to put a lot in order twice', function () {
    $lot = clOrder(clCreate(), '800');

    $this->co->post('finance', 'sourcing.lots.order', ['delivery_charge' => '500'], $lot)->assertSessionHas('error', 'This lot is already in order; nothing was changed.');

    expect($lot->costs()->count())->toBe(1)->and((int) $lot->costs()->sum('amount'))->toBe(80_000);
});

it('refuses the workflow actions to a user without the sourcing management right', function () {
    $lot = clCreate();

    $this->co->as('auditor')->post(route('sourcing.lots.order', $lot), ['delivery_charge' => '0'])->assertForbidden();

    expect($lot->refresh()->stage())->toBe('processing');
});

// ---------------------------------------------------------------- Completion

it('completes a lot whose supplier is not paid at all: payment never blocks the lot and none is recorded', function () {
    $lot = clOrder(clCreate());

    clComplete($lot)->assertSessionMissing('error')->assertSessionHas('status', 'Lot completed: goods received into stock.');

    expect($lot->refresh()->stage())->toBe('completed')->and(Inventory::onHand($lot->lines()->sole()))->toBe('100.00')
        ->and(SupplierPayment::count())->toBe(0)->and(Expense::count())->toBe(0)->and(AdvanceSettlement::count())->toBe(0)->and(Invoice::count())->toBe(0);
});

it('completes the lot: receives the stock and realises a profit of 4,200 without invoicing anything', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);
    $line = $lot->lines()->sole();

    clComplete($lot)->assertSessionHasNoErrors()->assertSessionMissing('error')->assertSessionHas('status', 'Lot completed: goods received into stock.');

    $lot->refresh();
    $f = LotFinancials::for($lot);
    expect($lot->stage())->toBe('completed')->and($lot->completed_by)->toBe($this->co->users['finance']->id)->and($lot->completed_at)->not->toBeNull()
        ->and(Inventory::onHand($line))->toBe('100.00')->and(StockMovement::where('sourcing_lot_line_id', $line->id)->count())->toBe(1)
        ->and(SourcingArrivalEvent::sole()->reference)->toBe('GRN-100')->and($line->refresh()->acceptance_reference)->toBe('GRN-100')
        ->and($f['landed'])->toBe(1_580_000)->and($f['revenue'])->toBe(2_000_000)->and($f['profit'])->toBe(420_000)
        // Client lots are independent of client billing: no invoice, invoice line, receivable or client payment is created.
        ->and(Invoice::count())->toBe(0)->and(InvoiceItem::count())->toBe(0)->and(Payment::count())->toBe(0)
        ->and($lot->allocations()->count())->toBe(0)->and($f['billed'])->toBe(0)->and($f['receivable'])->toBe(0);
});

it('completes a second lot into the only warehouse without asking for a location', function () {
    foreach (['GRN-A', 'GRN-B'] as $grn) { // the first receipt creates the main warehouse; the second must not then fail for want of a choice
        $lot = clOrder(clCreate());
        clPaySupplier($lot);
        clComplete($lot, ['reference' => $grn])->assertSessionMissing('error');
    }

    expect(SourcingLot::where('status', 'completed')->count())->toBe(2)->and(StockMovement::distinct()->count('stock_location_id'))->toBe(1);
});

it('still asks for a location when there is more than one warehouse', function () {
    StockLocations::create('MAIN', 'Main warehouse');
    StockLocations::create('UTT', 'Uttara store');
    $lot = clOrder(clCreate());
    clPaySupplier($lot);

    clComplete($lot)->assertSessionHas('error', 'Choose the warehouse location the accepted units were put in.');

    expect($lot->refresh()->stage())->toBe('in_order');
});

it('records a loss as a negative result, never as zero', function () {
    $lot = clCreate(['client_budget' => '15000']); // budget equal to base cost: auto-approved, then delivery makes it a loss
    $lot = clOrder($lot, '800');
    clPaySupplier($lot);

    clComplete($lot)->assertSessionMissing('error');

    expect(LotFinancials::for($lot->refresh())['profit'])->toBe(-80_000);
    $this->co->as('finance')->get(route('sourcing.lots.show', $lot))->assertOk()->assertSee('Final loss')->assertSee('-800.00');
});

it('never runs a settlement on completion, even when everything was paid separately', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);
    $this->co->post('finance', 'sourcing.costs.pay', ['paid_on' => '2026-11-06'], $lot->costs()->sole())->assertSessionMissing('error');

    clComplete($lot)->assertSessionHas('status', 'Lot completed: goods received into stock.');

    expect($lot->refresh()->status)->toBe('completed')->and($lot->stage())->toBe('completed')->and($lot->settled_at)->toBeNull();
});

it('completes with the delivery charge unpaid and shows no settlement warning', function () {
    $lot = clOrder(clCreate());

    clComplete($lot)->assertSessionMissing('warning')->assertSessionMissing('error');

    expect($lot->refresh()->status)->toBe('completed');
});

it('does not settle for a user who may complete lots but not settle them', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);
    $this->co->post('finance', 'sourcing.costs.pay', ['paid_on' => '2026-11-06'], $lot->costs()->sole());

    clComplete($lot, [], 'clients')->assertSessionMissing('error');

    expect($lot->refresh()->status)->toBe('completed');
});

it('records no supplier or delivery payment on completion, even when payment fields are sent', function () {
    $lot = clOrder(clCreate());

    clComplete($lot, ['supplier_paid' => '1', 'delivery_paid' => '1', 'paid_on' => '2026-11-08', 'payment_reference' => 'BKASH-9'])->assertSessionMissing('error');

    expect(SupplierPayment::count())->toBe(0)->and(Expense::count())->toBe(0)->and($lot->costs()->sole()->expense_id)->toBeNull()
        ->and($lot->refresh()->status)->toBe('completed');
});

it('leaves a supplier payment entered separately untouched and does not depend on its verification', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);

    expect(SupplierPayment::sole()->expense->status)->toBe('pending');
    clComplete($lot)->assertSessionMissing('error');
    expect(SupplierPayment::count())->toBe(1)->and(SupplierPayment::sole()->amount)->toBe(1_500_000)->and(SupplierPayment::sole()->expense->status)->toBe('pending')
        ->and($lot->refresh()->stage())->toBe('completed');
});

it('adds no payment on completion when part of the supplier price was paid earlier', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot, '10000');

    clComplete($lot, ['supplier_paid' => '1'])->assertSessionMissing('error');

    expect(SupplierPayment::orderBy('id')->pluck('amount')->all())->toBe([1_000_000])->and(LotFinancials::for($lot)['supplier_outstanding'])->toBe(500_000);
});

it('puts a completed lot\'s profit into the project\'s profitability without any invoice', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);
    $before = ProjectSourcingReport::build()['projects'][$this->project->id];
    expect(ProjectEconomics::for($this->project)['sourcing_margin'])->toBe(0)
        ->and($before['agreed'])->toBe(2_000_000)->and($before['estimated_margin'])->toBe(420_000)->and($before['recognised_margin'])->toBe(0); // expected, not yet realised

    clComplete($lot)->assertSessionMissing('error');

    $economics = ProjectEconomics::for($this->project->refresh());
    $report = ProjectSourcingReport::build()['projects'][$this->project->id];
    expect(Invoice::count())->toBe(0)
        ->and($economics['sourcing_margin'])->toBe(420_000)
        ->and($report['agreed'])->toBe(2_000_000)->and($report['cost'])->toBe(1_580_000)->and($report['recognised_margin'])->toBe(420_000)
        ->and($report['billed'])->toBe(0)->and($report['receivable'])->toBe(0)
        ->and(ProjectSourcingReport::build()['projects'])->not->toHaveKey('unallocated');
});

it('counts a lot that was also billed manually only once in the project\'s profitability', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);
    $this->co->post('finance', 'sourcing.lots.bill-client', ['project_id' => $this->project->id], $lot)->assertSessionMissing('error'); // e.g. an older lot, billed separately

    clComplete($lot)->assertSessionMissing('error');

    $report = ProjectSourcingReport::build()['projects'][$this->project->id];
    expect(Invoice::count())->toBe(1)->and((int) Invoice::sum('total'))->toBe(2_000_000) // the existing invoice is untouched and not repeated
        ->and($report['agreed'])->toBe(2_000_000)->and($report['billed'])->toBe(2_000_000)->and($report['recognised_margin'])->toBe(420_000)
        ->and(ProjectEconomics::for($this->project->refresh())['sourcing_margin'])->toBe(420_000);
});

it('keeps an independently created project invoice out of the lot\'s profit', function () {
    $this->co->invoice($this->project, [['description' => 'Design service', 'quantity' => '1', 'unit_price' => '5000']], '2026-11-03', 'INV-SVC-1');
    $lot = clOrder(clCreate());
    clPaySupplier($lot);

    clComplete($lot)->assertSessionMissing('error');

    $report = ProjectSourcingReport::build()['projects'][$this->project->id];
    expect(Invoice::count())->toBe(1)->and($report['agreed'])->toBe(2_000_000)->and($report['recognised_margin'])->toBe(420_000)
        ->and(ProjectEconomics::for($this->project->refresh())['sourcing_margin'])->toBe(420_000);
});

it('bills a budget that does not divide by the quantity to the exact paisa', function () {
    $lot = clCreate(['quantity' => '3', 'unit_cost' => '2000', 'client_budget' => '10000']);

    $this->co->post('finance', 'sourcing.lots.bill-client', ['project_id' => $this->project->id], $lot)->assertSessionMissing('error');

    $items = Invoice::sole()->items()->orderBy('id')->get();
    expect(Invoice::sole()->total)->toBe(1_000_000)
        ->and($items->map(fn ($i) => [(string) $i->quantity, $i->unit_price])->all())->toBe([['2.00', 333_333], ['1.00', 333_334]])
        ->and(LotFinancials::for($lot)['revenue'])->toBe(1_000_000)->and(LotFinancials::for($lot)['unbilled'])->toBe(0);
});

// ---------------------------------------------------------------- Accounting integrity

it('never posts revenue or cost twice: completion writes no journal, income arrives only with the client payment', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);
    $this->co->verifyAllExpenses(); // the supplier payment reaches the ledger once, as its own verified expense
    $expenses = Expense::count();
    $journals = JournalEntry::count();
    $cash = clCash();
    $income = clIncome();

    clComplete($lot)->assertSessionMissing('error');

    expect(JournalEntry::count())->toBe($journals)->and(Expense::count())->toBe($expenses)->and(clCash())->toBe($cash)->and(clIncome())->toBe($income);

    // A completion attempted again changes nothing at all.
    clComplete($lot->refresh(), ['reference' => 'GRN-101'])->assertSessionHas('error', 'This lot is already completed; nothing was received, billed or posted again.');
    expect(JournalEntry::count())->toBe($journals)->and(Invoice::count())->toBe(0)->and(SourcingArrivalEvent::count())->toBe(1)
        ->and(Inventory::onHand($lot->lines()->sole()))->toBe('100.00');
});

it('moves cash only for real payments: ordering and completing leave the cash balance untouched', function () {
    $cash = clCash();
    $lot = clOrder(clCreate());
    expect(clCash())->toBe($cash);

    clPaySupplier($lot);
    $this->co->verifyAllExpenses();
    expect(clCash())->toBe($cash - 1_500_000);

    clComplete($lot)->assertSessionMissing('error');
    expect(clCash())->toBe($cash - 1_500_000);
});

it('rolls everything back when a step of completion fails', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);
    // The very last write of a completion fails (after the payment, the stock receipt and the status change have run).
    AuditLog::creating(fn (AuditLog $log) => $log->action === 'sourcing.client_lot.completed' ? throw new RuntimeException('The audit log is unavailable.') : null);

    clComplete($lot, ['delivery_paid' => '1'])->assertSessionHas('error', 'The audit log is unavailable.');

    $lot->refresh();
    expect($lot->status)->toBe('in_progress')->and($lot->completed_at)->toBeNull()
        ->and(SourcingArrivalEvent::count())->toBe(0)->and(StockMovement::count())->toBe(0)
        ->and($lot->lines()->sole()->received_quantity)->toBeNull()->and($lot->costs()->sole()->expense_id)->toBeNull();
});

it('refuses a second completion that arrives with a stale copy of the lot', function () {
    $lot = clOrder(clCreate());
    clPaySupplier($lot);
    $stale = SourcingLot::findOrFail($lot->id); // loaded while the lot was still In order, as a simultaneous request would have it
    $finance = $this->co->users['finance'];

    ClientLot::complete($lot, ['arrived_on' => '2026-11-09', 'reference' => 'GRN-A', 'delivery_paid' => true], $finance);

    expect(fn () => ClientLot::complete($stale, ['arrived_on' => '2026-11-09', 'reference' => 'GRN-B', 'delivery_paid' => true], $finance))
        ->toThrow(RuntimeException::class, 'This lot is already completed; nothing was received, billed or posted again.');
    expect(SourcingArrivalEvent::count())->toBe(1)->and(Expense::where('category', 'Sourcing: Delivery')->count())->toBe(0)->and(Inventory::onHand($lot->lines()->sole()))->toBe('100.00');
});

it('refuses invalid stage jumps', function () {
    $lot = clCreate();

    clComplete($lot)->assertSessionHas('error', 'Mark the lot In order first.');

    expect($lot->refresh()->stage())->toBe('processing')->and(StockMovement::count())->toBe(0);
});

it('under the accrual basis completes without proposing any advance settlement or Board step', function () {
    config(['finance.sourcing_basis' => 'accrual', 'finance.sourcing_accrual_from' => '2026-11-01']);
    $lot = clOrder(clCreate(['purchase_order' => 'PO-77']), '0');
    clPaySupplier($lot); // a payment the admins entered separately (an advance under this basis) is left as it is
    $this->co->verifyAllExpenses();
    $journals = JournalEntry::count();

    clComplete($lot)->assertSessionMissing('warning')->assertSessionMissing('error');

    expect(AdvanceSettlement::count())->toBe(0)->and($lot->refresh()->status)->toBe('completed')->and(JournalEntry::count())->toBe($journals);
});

// ---------------------------------------------------------------- Existing lots

it('maps every existing status to exactly one simple stage', function (string $status, string $stage) {
    expect((new SourcingLot(['status' => $status]))->stage())->toBe($stage);
})->with([
    ['draft', 'processing'], ['costing', 'processing'], ['priced', 'processing'], ['approved', 'processing'],
    ['in_progress', 'in_order'], ['completed', 'completed'], ['settled', 'completed'], ['cancelled', 'cancelled'],
]);

it('keeps the figures of a lot priced per line, and leaves it to the detailed view', function () {
    $finance = $this->co->users['finance'];
    $lot = Sourcing::createLot(['title' => 'Legacy lot'], $finance);
    Sourcing::addLine($lot, ['supplier_id' => $this->supplier->id, 'description' => 'Hoodie', 'quantity' => '10', 'unit_price' => 400_000], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 75_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, $this->co->users['board']);

    $f = LotFinancials::for($lot->refresh());
    expect($lot->client_budget)->toBeNull()->and($lot->stage())->toBe('processing')
        ->and($f['revenue'])->toBe(4_750_000)->and($f['profit'])->toBe(750_000);
    $this->co->post('finance', 'sourcing.lots.order', ['delivery_charge' => '0'], $lot)
        ->assertSessionHas('error', 'This lot was not made on the simple client-lot form; manage it from the detailed view.');
    $this->co->as('finance')->get(route('sourcing.lots.show', $lot))->assertOk()->assertSee('Products')->assertDontSee('Mark In order');
});

it('flags an older lot marked completed without a receipt for review instead of guessing', function () {
    $finance = $this->co->users['finance'];
    $lot = Sourcing::createLot(['title' => 'Historical lot'], $finance);
    Sourcing::addLine($lot, ['supplier_id' => $this->supplier->id, 'description' => 'Hoodie', 'quantity' => '10', 'unit_price' => 400_000], $finance);
    $lot->update(['status' => 'completed']); // as an older record may stand

    expect(ClientLot::reviewFlags($lot))->toBe(['Marked completed before the goods received were recorded.']);
    $this->co->as('finance')->get(route('sourcing.lots.index'))->assertOk()->assertSee($lot->number)->assertSee('Needs review');
});

it('lists lots by simple stage with project, supplier, product and the realised result', function () {
    $processing = clCreate();
    $done = clOrder(clCreate());
    clPaySupplier($done);
    clComplete($done)->assertSessionMissing('error');

    $this->co->as('finance')->get(route('sourcing.lots.index', ['stage' => 'completed']))->assertOk()
        ->assertSee($done->number)->assertDontSee($processing->number)
        ->assertSee('Corporate Gifts')->assertSee('Dhaka Ceramics')->assertSee('Coffee Mug')->assertSee('15,800.00')->assertSee('4,200.00')->assertSee('realised');
});
