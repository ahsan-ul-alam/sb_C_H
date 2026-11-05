<?php

use App\Models\ClientOrder;
use App\Models\InventoryHandover;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Models\StockThreshold;
use App\Services\Inventory;
use App\Services\InventoryExceptions;
use App\Services\InventoryReports;
use App\Services\ProjectInventory;
use App\Services\Receiving;
use App\Services\SalesDashboard;
use App\Services\SalesStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function hoodieOrder(array $s, string $ref, string $qty, string $price = '600')
{
    $s['co']->post('clients', 'product.orders.store', ['project_id' => $s['project']->id, 'reference' => $ref, 'ordered_on' => '2026-02-10', 'customer_name' => 'Customer '.$ref,
        'items' => [['product_id' => $s['product']->id, 'quantity' => $qty, 'price' => $price]]])->assertSessionHasNoErrors();

    return ClientOrder::where('reference', $ref)->firstOrFail();
}

function hoodieStep(SellbeesCompany $co, string $step, ClientOrder $order, array $data = [])
{
    return $co->post('clients', 'product.orders.'.$step, $data, $order);
}

function hoodieBalances(array $s): array
{
    $b = Inventory::balances($s['line']->refresh());

    return ['received' => $b['received'], 'available' => $b['available'], 'reserved' => $b['reserved'], 'dispatched' => $b['dispatched'], 'delivered' => $b['delivered'], 'returned' => $b['returned'], 'damaged' => $b['damaged']];
}

it('runs the Premium Hoodie lot end to end with exact balances at every step', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this));
    $co = $s['co'];
    $user = $s['user'];

    // Receiving: 300 arrive (280 accepted, 20 rejected), then 200 (all accepted).
    Receiving::receive($s['line'], '300', '280', '0', '2026-02-10', null, 'GRN-1', null, null, $user);
    expect(hoodieBalances($s))->toBe(['received' => '280.00', 'available' => '280.00', 'reserved' => '0.00', 'dispatched' => '0.00', 'delivered' => '0.00', 'returned' => '0.00', 'damaged' => '0.00'])
        ->and(Receiving::expected($s['line']->refresh()))->toBe('200.00');
    Receiving::receive($s['line'], '200', '200', '0', '2026-02-10', null, 'GRN-2', null, null, $user);
    $line = $s['line']->refresh();
    expect((string) $line->received_quantity)->toBe('500.00')->and((string) $line->usable_quantity)->toBe('480.00')->and(bcsub('500', (string) $line->usable_quantity, 2))->toBe('20.00')
        ->and(hoodieBalances($s)['available'])->toBe('480.00');

    // The client pays for what arrived; the lot is then the client's stock.
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $s['project']->id], $s['lot'])->assertSessionHasNoErrors();

    // Reserve 100 across three orders: A 60, B 10, C 30. Over-reservation is refused.
    $a = hoodieOrder($s, 'A', '60');
    $b = hoodieOrder($s, 'B', '10');
    $c = hoodieOrder($s, 'C', '30');
    $tooBig = hoodieOrder($s, 'BIG', '1000');
    hoodieStep($co, 'confirm', $tooBig)->assertSessionHas('error');
    expect($tooBig->refresh()->delivery_status)->toBe('pending');
    foreach ([$a, $b, $c] as $o) {
        hoodieStep($co, 'confirm', $o)->assertSessionHasNoErrors();
    }
    expect(hoodieBalances($s))->toMatchArray(['available' => '380.00', 'reserved' => '100.00', 'received' => '480.00']);
    hoodieStep($co, 'confirm', $a);                                              // confirming again does not reserve twice
    expect(hoodieBalances($s)['reserved'])->toBe('100.00');

    // Dispatch A (60) and B (10): 70 leave the warehouse. Dispatch is not delivery.
    hoodieStep($co, 'dispatch', $a)->assertSessionHasNoErrors();
    hoodieStep($co, 'dispatch', $b)->assertSessionHasNoErrors();
    expect(hoodieBalances($s))->toMatchArray(['available' => '380.00', 'reserved' => '30.00', 'dispatched' => '70.00', 'delivered' => '0.00']);
    expect(SalesStatus::of($a->refresh()))->toBe(['order' => 'Confirmed', 'fulfilment' => 'In Transit', 'payment' => 'Unpaid']);

    // Deliver A (60); B's 10 stay in transit until their outcome is recorded. Commission is 10% of the delivered value, earned once.
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '10'])->assertSessionHasNoErrors();
    hoodieStep($co, 'deliver', $a)->assertSessionHasNoErrors();
    expect(hoodieBalances($s))->toMatchArray(['available' => '380.00', 'reserved' => '30.00', 'dispatched' => '10.00', 'delivered' => '60.00']);
    $a->refresh();
    expect($a->delivery_status)->toBe('delivered')->and($a->commission)->toBe(360_000)->and($a->billed_invoice_id)->not->toBeNull();
    $commission = $a->commission;
    hoodieStep($co, 'deliver', $a);                                              // a second deliver cannot deduct again or earn commission again
    expect(hoodieBalances($s)['delivered'])->toBe('60.00')->and($a->refresh()->commission)->toBe($commission);

    // Return 5 delivered units; inspection decides: 3 resalable, 2 damaged.
    $item = $a->items()->first();
    hoodieStep($co, 'return', $a, ['returned' => [$item->id => '5']])->assertSessionHasNoErrors();
    expect(hoodieBalances($s))->toMatchArray(['delivered' => '55.00', 'returned' => '5.00', 'available' => '380.00']);
    $co->post('clients', 'product.items.inspect', ['usable' => '3', 'damaged' => '2'], $item)->assertSessionHasNoErrors();
    expect(hoodieBalances($s))->toBe(['received' => '480.00', 'available' => '383.00', 'reserved' => '30.00', 'dispatched' => '10.00', 'delivered' => '55.00', 'returned' => '0.00', 'damaged' => '2.00']);

    expect($a->refresh()->commission)->toBe(330_000);                           // commission follows the 55 that stay delivered

    // Physical conservation: every accepted unit is in exactly one bucket.
    $sum = collect(['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'])->reduce(fn ($t, $k) => bcadd($t, hoodieBalances($s)[$k], 2), '0');
    expect($sum)->toBe('480.00')->and(Inventory::reconciles($s['line']))->toBeTrue();
    // Reservation changes availability, not the units in the warehouse: on hand = available + reserved + awaiting inspection.
    expect(Inventory::onHand($s['line']))->toBe('413.00');

    // Project position and product report agree with the ledger.
    $row = ProjectInventory::positions(['project' => $s['project']->id])->first();
    expect($row['b']['available'])->toBe('383.00')->and($row['rejected'])->toBe('20.00')->and($row['ownership'])->toBe('client');
    $report = InventoryReports::build('project-stock', ['project' => $s['project']->id]);
    expect($report['rows'][0][9])->toBe('383.00');
    $rec = InventoryReports::build('reconciliation')['rows'][0];
    expect($rec[8])->toBe('0.00')->and($rec[9])->toBe('Yes');                  // ledger buckets agree with what the orders say

    // Money: only A was delivered; collection is the client's money, partial, and never recorded twice.
    $key = (string) Str::uuid();
    $co->post('clients', 'product.orders.collect', ['amount' => '20000', 'collected_on' => '2026-02-10', 'idempotency_key' => $key], $a)->assertSessionHasNoErrors();
    $co->post('clients', 'product.orders.collect', ['amount' => '20000', 'collected_on' => '2026-02-10', 'idempotency_key' => $key], $a);
    expect(JournalEntry::where('source_type', 'order_collection')->count())->toBe(1)->and(SalesStatus::of($a->refresh())['payment'])->toBe('Partially Paid');
    $sales = SalesDashboard::figures();
    expect($sales['delivered'])->toBe(55 * 60_000)->and($sales['collected'])->toBe(2_000_000)->and($sales['receivable'])->toBe(55 * 60_000 - 2_000_000)
        ->and($sales['commission'])->toBe($a->commission)->and($sales['counts']['dispatched'])->toBe(1)->and($sales['cogs'])->toBe(0);
    expect(InventoryHandover::count())->toBe(0);                                // client-owned goods: no Sellbees COGS on an end-customer delivery

    // Cancelling C releases its reservation; failed delivery of B returns its units.
    hoodieStep($co, 'cancel', $c)->assertSessionHasNoErrors();
    expect(hoodieBalances($s))->toMatchArray(['available' => '413.00', 'reserved' => '0.00']);
    hoodieStep($co, 'fail', $b)->assertSessionHasNoErrors();
    expect(hoodieBalances($s))->toMatchArray(['available' => '423.00', 'dispatched' => '0.00'])->and(Inventory::reconciles($s['line']))->toBeTrue();
    expect(StockMovement::where('sourcing_lot_line_id', $line->id)->where('type', 'receipt')->count())->toBe(2);
});

it('keeps one client\'s stock from another client\'s order', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this), '50');
    Receiving::receive($s['line'], '50', '50', '0', '2026-02-10', null, 'GRN-1', null, null, $s['user']);
    $s['co']->post('clients', 'sourcing.lots.bill-client', ['project_id' => $s['project']->id], $s['lot'])->assertSessionHasNoErrors();
    $otherProject = $s['co']->project($s['co']->client('Other Client'), 'Other Project');
    $s['co']->post('clients', 'product.orders.store', ['project_id' => $otherProject->id, 'reference' => 'X', 'ordered_on' => '2026-02-10', 'items' => [['product_id' => $s['product']->id, 'quantity' => '5', 'price' => '600']]]);
    $x = ClientOrder::where('reference', 'X')->firstOrFail();
    hoodieStep($s['co'], 'confirm', $x)->assertSessionHas('error');
    expect(hoodieBalances($s)['reserved'])->toBe('0.00');
});

it('flags stock without a project, low stock and stale reservations, and never creates anything itself', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this), '100');
    Receiving::receive($s['line'], '100', '100', '0', '2026-02-10', null, 'GRN-1', null, null, $s['user']);
    $s['lot']->update(['project_id' => null]);
    expect(InventoryExceptions::all()->pluck('type'))->toContain('Accepted stock without project attribution');

    $s['lot']->update(['project_id' => $s['project']->id]);
    StockThreshold::create(['supplier_product_id' => $s['product']->id, 'minimum_quantity' => '150']);
    expect(InventoryExceptions::lowStock()->first()['state'])->toBe('low');
    StockThreshold::query()->update(['minimum_quantity' => '20']);
    expect(InventoryExceptions::lowStock()->first()['state'])->toBe('ok')->and(StockMovement::count())->toBe(1);
});

it('renders every screen and exports consistent CSV, and enforces permissions', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this), '120');
    $co = $s['co'];
    Receiving::receive($s['line'], '100', '90', '2', '2026-02-10', null, 'GRN-1', 'photo-77', null, $s['user']);
    foreach (['stock.overview', 'stock.projects', 'stock.movements', 'stock.receiving', 'stock.reservations', 'stock.transfers', 'stock.exceptions', 'stock.sales', 'stock.reports'] as $route) {
        $co->as('finance')->get(route($route))->assertOk();
    }
    foreach (array_keys(InventoryReports::TYPES) as $type) {
        $co->as('finance')->get(route('stock.report', $type))->assertOk();
        $csv = $co->as('finance')->get(route('stock.report', ['type' => $type, 'export' => 'csv']));
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
    $csv = $co->as('finance')->get(route('stock.report', ['type' => 'project-stock', 'export' => 'csv']))->streamedContent();
    expect($csv)->toContain('Example Project A')->and($csv)->toContain('90.00');

    // Reads need inventory.view; the employee has none, the auditor can read but not receive or transfer.
    $co->as('employee')->get(route('stock.overview'))->assertForbidden();
    $co->as('auditor')->get(route('stock.projects'))->assertOk();
    $co->post('auditor', 'stock.receive', ['reference' => 'Z', 'received_quantity' => '1', 'accepted_quantity' => '1', 'arrived_on' => '2026-02-10'], $s['line'])->assertForbidden();
    $co->post('auditor', 'stock.transfers.store', [], null)->assertForbidden();

    // The receiving form shows its validation errors and records nothing on a bad submission.
    $co->post('clients', 'stock.receive', ['reference' => '', 'received_quantity' => 'x', 'accepted_quantity' => '1', 'arrived_on' => '2026-02-10'], $s['line'])->assertSessionHasErrors(['reference', 'received_quantity']);
    $co->post('clients', 'stock.receive', ['reference' => 'GRN-9', 'received_quantity' => '5', 'accepted_quantity' => '5', 'arrived_on' => '2026-02-10'], $s['line'])->assertSessionHasNoErrors();
    expect(hoodieBalances($s)['available'])->toBe('95.00');
});
