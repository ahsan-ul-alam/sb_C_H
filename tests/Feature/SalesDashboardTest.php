<?php

use App\Models\ClientOrder;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\SourcingLot;
use App\Models\StockThreshold;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Inventory;
use App\Services\ProjectEconomics;
use App\Services\SalesAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** A client's own stock: supplier BDT 3, client BDT 5, received. @return array{project: App\Models\Project, product: SupplierProduct, line: App\Models\SourcingLotLine, client: App\Models\Client} */
function sdStock(SellbeesCompany $co, string $clientName, string $qty = '10', string $projectName = 'Main'): array
{
    $co->post('clients', 'sourcing.suppliers.store', ['name' => 'Supplier '.Str::random(4), 'phone' => '017'])->assertSessionHasNoErrors();
    $supplier = Supplier::latest('id')->firstOrFail();
    $co->post('clients', 'sourcing.products.store', ['supplier_id' => $supplier->id, 'name' => 'Mug '.Str::random(3), 'reference_price' => '3'])->assertSessionHasNoErrors();
    $product = SupplierProduct::latest('id')->firstOrFail();
    $client = $co->client($clientName);
    $project = $co->project($client, $projectName);
    $co->post('clients', 'sourcing.lots.store', ['supplier_id' => $supplier->id, 'delivery_cost' => '0', 'submission_key' => (string) Str::uuid(), 'project_id' => $project->id, 'items' => [['product_id' => $product->id, 'quantity' => $qty, 'profit' => '2']]])->assertSessionHasNoErrors();
    $lot = SourcingLot::latest('id')->firstOrFail();
    $line = $lot->lines()->firstOrFail();
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $project->id], $lot)->assertSessionHasNoErrors();
    $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => $qty, 'arrived_on' => '2026-02-10'], $line)->assertSessionHasNoErrors();

    return ['project' => $project, 'product' => $product, 'line' => $line->refresh(), 'client' => $client];
}

/** Place, confirm and (optionally) dispatch / deliver an order. */
function sdOrder(SellbeesCompany $co, array $s, string $ref, string $qty, string $upTo = 'draft', array $extra = []): ClientOrder
{
    $co->post('clients', 'product.orders.store', ['project_id' => $s['project']->id, 'reference' => $ref, 'ordered_on' => '2026-02-10', 'customer_name' => $extra['customer'] ?? 'Customer '.$ref,
        'customer_phone' => $extra['phone'] ?? '01700000000', 'items' => [['product_id' => $s['product']->id, 'quantity' => $qty, 'price' => '10']]])->assertSessionMissing('error');
    $o = ClientOrder::where('reference', $ref)->firstOrFail();
    $steps = ['draft' => [], 'confirmed' => ['confirm'], 'dispatched' => ['confirm', 'dispatch'], 'delivered' => ['confirm', 'dispatch', 'deliver'], 'failed' => ['confirm', 'dispatch', 'fail'], 'cancelled' => ['confirm', 'cancel']][$upTo];
    foreach ($steps as $step) {
        $data = $step === 'dispatch' ? ['courier' => $extra['courier'] ?? null, 'tracking_reference' => $extra['tracking'] ?? null, 'delivery_charge' => $extra['charge'] ?? '0', 'delivery_charge_bearer' => 'client', 'delivery_charge_status' => 'courier_deducted'] : [];
        $co->post('clients', 'product.orders.'.$step, $data, $o)->assertSessionMissing('error');
    }

    return $o->refresh();
}

function sdBoot($test): SellbeesCompany
{
    Carbon::setTestNow('2026-02-10 10:00:00');
    $co = SellbeesCompany::boot($test);
    $co->standingPolicy('quick_lot', '100000000');
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1'])->assertSessionHasNoErrors();

    return $co;
}

it('calculates the dashboard KPIs from the order records and keeps the exact Mug figures', function () {
    $co = sdBoot($this);
    $s = sdStock($co, 'Mug Client');
    sdOrder($co, $s, 'OPEN', '1', 'draft');                                                                  // an order still in draft
    sdOrder($co, $s, 'CANCEL-1', '3', 'cancelled');
    sdOrder($co, $s, 'FAIL-1', '2', 'failed', ['charge' => '2']);
    $co->post('clients', 'product.items.inspect', ['usable' => '2', 'damaged' => '0'], ClientOrder::where('reference', 'FAIL-1')->firstOrFail()->items()->firstOrFail())->assertSessionHasNoErrors();
    sdOrder($co, $s, 'ONE', '1', 'delivered', ['charge' => '1', 'courier' => 'Pathao', 'tracking' => 'TRK-1']);
    sdOrder($co, $s, 'NINE', '9', 'delivered', ['charge' => '9', 'courier' => 'Pathao', 'tracking' => 'TRK-9']);

    $orders = ClientOrder::where('project_id', $s['project']->id)->with(['items', 'project'])->get();
    $a = SalesAnalytics::figures($orders);
    expect($a['counts'])->toMatchArray(['total' => 5, 'draft' => 1, 'delivered' => 2, 'failed' => 1, 'cancelled' => 1, 'dispatched' => 0, 'with_returns' => 0])
        ->and($a['totals'])->toBe(['sales' => 10_000, 'delivery' => 1_000, 'net' => 9_000, 'commission' => 90, 'entitlement' => 8_910])           // BDT 100, 10, 90, 0.90, 89.10
        ->and($a['successful_orders'])->toBe(2)->and($a['success_rate_bps'])->toBe(6_667)->and($a['failure_rate_bps'])->toBe(3_333)->and($a['return_rate_bps'])->toBe(0)
        ->and($a['average_order_value'])->toBe(5_000)                                                                                                    // 100.00 / 2 successful orders
        ->and($a['monthly']['2026-02'])->toBe(['orders' => 2, 'sales' => 10_000])->and($a['daily']['2026-02-10'])->toBe(['orders' => 2, 'sales' => 10_000])
        ->and($a['top_products']->first()['units'])->toBe('10.00')->and($a['top_products']->first()['sales'])->toBe(10_000)
        ->and($a['projects']->first()['entitlement'])->toBe(8_910)->and($a['projects']->first()['success_rate_bps'])->toBe(6_667);

    $e = ProjectEconomics::for($s['project']);
    expect([$e['entitlement'], $e['client_profit'], $e['commission'], $e['sourcing_margin']])->toBe([8_910, 3_910, 90, 2_000]);                           // the Mug example, unchanged

    $page = $co->as('clients')->get(route('product.projects.show', $s['project']))->assertOk();
    $page->assertSee('Sales performance')->assertSee('Top-selling products')->assertSee('Low-stock warnings')->assertSee('Exceptions to chase')->assertSee('66.7%')->assertSee('50.00');   // AOV BDT 50.00
    $co->as('clients')->get(route('product.analytics'))->assertOk()->assertSee('Project-wise performance')->assertSee('Mug Client' === 'x' ? '' : $s['project']->name);
});

it('measures returns: the return rate counts orders and units, and sales fall by what came back', function () {
    $co = sdBoot($this);
    $s = sdStock($co, 'Return Client');
    $o = sdOrder($co, $s, 'RET-1', '10', 'delivered', ['charge' => '10']);
    $co->post('clients', 'product.orders.return', ['returned' => [$o->items()->firstOrFail()->id => '2']], $o)->assertSessionMissing('error');
    $a = SalesAnalytics::figures(ClientOrder::with(['items', 'project'])->get());
    expect($a['counts']['with_returns'])->toBe(1)->and($a['return_rate_bps'])->toBe(10_000)->and($a['unit_return_rate_bps'])->toBe(2_000)                  // 100% of orders, 20% of units
        ->and($a['totals']['sales'])->toBe(8_000)->and($a['top_products']->first()['units'])->toBe('8.00');                                            // 10 sold, 2 back
    expect(SalesAnalytics::exceptions(ClientOrder::with(['items', 'project'])->get())['awaiting_inspection'][0]['quantity'])->toBe('2.00');            // the return waits for inspection
});

it('searches, filters, sorts and paginates the orders screen, and shows courier, collection, charge, commission and entitlement', function () {
    $co = sdBoot($this);
    $a = sdStock($co, 'Alpha Client', '10', 'Alpha Shop');
    $b = sdStock($co, 'Beta Client', '10', 'Beta Shop');
    sdOrder($co, $a, 'A-DRAFT', '1', 'draft', ['customer' => 'Rahim Uddin', 'phone' => '01711111111']);
    sdOrder($co, $a, 'A-SENT', '2', 'dispatched', ['courier' => 'Pathao', 'tracking' => 'PTH-777', 'customer' => 'Karim']);
    sdOrder($co, $a, 'A-DONE', '3', 'delivered', ['courier' => 'Pathao', 'tracking' => 'PTH-888', 'charge' => '3', 'customer' => 'Salma']);
    sdOrder($co, $b, 'B-SENT', '2', 'dispatched', ['courier' => 'Steadfast', 'tracking' => 'STF-1', 'customer' => 'Jamal']);
    sdOrder($co, $b, 'B-FAIL', '1', 'failed', ['customer' => 'Nasir']);
    $get = fn (array $q = []) => $co->as('clients')->get(route('product.orders', $q))->assertOk();

    $get()->assertSee('A-DRAFT')->assertSee('B-FAIL')->assertSee('Order summary');
    $get(['q' => 'A-SENT'])->assertSee('A-SENT')->assertDontSee('A-DRAFT')->assertDontSee('B-SENT');                              // order number
    $get(['q' => 'Rahim'])->assertSee('A-DRAFT')->assertDontSee('A-SENT');                                                       // customer name
    $get(['q' => '01711111111'])->assertSee('A-DRAFT')->assertDontSee('B-SENT');                                                 // phone
    $get(['q' => 'STF-1'])->assertSee('B-SENT')->assertDontSee('A-SENT');                                                        // tracking
    $get(['q' => 'Beta Shop'])->assertSee('B-SENT')->assertSee('B-FAIL')->assertDontSee('A-DONE');                               // project name
    $get(['product' => $a['product']->name])->assertSee('A-DONE')->assertDontSee('B-SENT');                                      // product
    $get(['client' => $b['client']->id])->assertSee('B-SENT')->assertDontSee('A-DRAFT');                                         // client
    $get(['fproject' => $a['project']->id])->assertSee('A-DONE')->assertDontSee('B-FAIL');                                       // project
    $get(['status' => 'dispatched'])->assertSee('A-SENT')->assertSee('B-SENT')->assertDontSee('A-DONE')->assertDontSee('A-DRAFT');
    $get(['status' => 'failed'])->assertSee('B-FAIL')->assertSee('Failed')->assertDontSee('A-SENT');
    $get(['courier' => 'Steadfast'])->assertSee('B-SENT')->assertDontSee('A-SENT');                                              // courier
    $get(['from' => '2026-02-11'])->assertDontSee('A-DRAFT');                                                                    // date range: all ordered on 02-10
    $get(['to' => '2026-02-09'])->assertDontSee('A-DRAFT');
    $get(['from' => '2026-02-10', 'to' => '2026-02-10'])->assertSee('A-DRAFT');
    $get(['sort' => 'reference', 'dir' => 'asc'])->assertSeeInOrder(['A-DONE', 'A-DRAFT', 'A-SENT', 'B-FAIL', 'B-SENT']);
    $get(['sort' => 'reference', 'dir' => 'desc'])->assertSeeInOrder(['B-SENT', 'B-FAIL', 'A-SENT', 'A-DRAFT', 'A-DONE']);
    $get(['sort' => 'value', 'dir' => 'desc'])->assertSeeInOrder(['A-DONE', 'B-SENT', 'A-SENT', 'B-FAIL', 'A-DRAFT']);          // by order value (3, 2, 2, 1, 1 units at BDT 10; ties newest first)
    $co->as('clients')->get(route('product.orders', ['sort' => 'DROP TABLE']))->assertSessionHasErrors('sort');                 // a sort key is never raw SQL
    $page = $get(['per_page' => 25]);
    expect($page->viewData('orders')->perPage())->toBe(25);
    $get(['per_page' => 100]);
    // The delivered order shows its tracking, delivery charge, commission and client entitlement; the summary matches the scope.
    $get(['q' => 'A-DONE'])->assertSee('PTH-888')->assertSee('client entitlement')->assertSee('delivery charge');
    $summary = $get(['client' => $a['client']->id])->viewData('summary');
    expect($summary['counts']['delivered'])->toBe(1)->and($summary['totals']['sales'])->toBe(3_000)->and($summary['totals']['delivery'])->toBe(300)->and($summary['totals']['commission'])->toBe(27);   // 30.00, 3.00, 0.27
    expect($get(['client' => $a['client']->id, 'status' => 'failed'])->viewData('summary')['counts']['draft'])->toBe(1);          // the summary ignores the status filter
});

it('keeps every dashboard, order screen and analytics page inside the user\'s projects and client', function () {
    $co = sdBoot($this);
    $a = sdStock($co, 'Alpha Client', '10', 'Alpha Shop');
    $b = sdStock($co, 'Beta Client', '10', 'Beta Shop');
    sdOrder($co, $a, 'A-ONE', '2', 'delivered', ['charge' => '2']);
    sdOrder($co, $b, 'B-ONE', '2', 'delivered', ['charge' => '2']);

    $staff = SellbeesCompany::user('assigned@audit.test', 'client_management');
    $this->actingAs($co->users['super'])->post(route('users.access', $staff), ['project_ids' => [$a['project']->id]])->assertSessionHas('status');
    $staff->refresh();
    $this->actingAs($staff)->get(route('product.orders'))->assertOk()->assertSee('A-ONE')->assertDontSee('B-ONE')->assertDontSee('Beta Shop');
    $this->actingAs($staff)->get(route('product.orders', ['fproject' => $b['project']->id]))->assertForbidden();
    expect($this->actingAs($staff)->get(route('product.orders', ['q' => 'B-ONE']))->assertOk()->viewData('orders')->total())->toBe(0);      // search never reaches another project
    $this->actingAs($staff)->get(route('product.analytics', ['fproject' => $b['project']->id]))->assertForbidden();
    $analytics = $this->actingAs($staff)->get(route('product.analytics'))->assertOk()->assertSee('Alpha Shop')->assertDontSee('Beta Shop');
    expect($analytics->viewData('a')['counts']['total'])->toBe(1)->and($analytics->viewData('a')['totals']['sales'])->toBe(2_000);
    $this->actingAs($staff)->get(route('product.projects.show', $a['project']))->assertOk()->assertSee('Sales performance');
    $this->actingAs($staff)->get(route('product.projects.show', $b['project']))->assertForbidden();

    $plain = SellbeesCompany::user('plain@audit.test', 'client_management');
    $plain->forceFill(['all_projects' => false])->save();                                                                         // no grant, no assignment: sees nothing
    expect($this->actingAs($plain)->get(route('product.analytics'))->assertOk()->viewData('a')['counts']['total'])->toBe(0);
    $this->actingAs($plain)->get(route('product.orders'))->assertOk()->assertDontSee('A-ONE')->assertDontSee('B-ONE');

    $bound = SellbeesCompany::user('bound@audit.test', 'client_management');
    $this->actingAs($co->users['super'])->post(route('users.access', $bound), ['client_id' => $b['client']->id])->assertSessionHas('status');
    $bound->refresh();
    expect($this->actingAs($bound)->get(route('product.analytics'))->viewData('a')['counts']['total'])->toBe(1);                   // only the bound client's project
    $this->actingAs($bound)->get(route('product.projects.show', $a['project']))->assertForbidden();

    $all = $this->actingAs($co->users['finance'])->get(route('product.analytics'))->assertOk();                                   // management sees everything
    expect($all->viewData('a')['counts']['total'])->toBe(2)->and($all->viewData('a')['totals']['sales'])->toBe(4_000);
});

it('flags pending deliveries, returns awaiting inspection and low stock, and leaves the books untouched', function () {
    $co = sdBoot($this);
    $s = sdStock($co, 'Alert Client', '10', 'Alert Shop');
    $sent = sdOrder($co, $s, 'SENT-OLD', '2', 'dispatched', ['courier' => 'Pathao', 'tracking' => 'OLD-1']);
    sdOrder($co, $s, 'SENT-NEW', '1', 'dispatched', ['courier' => 'Pathao', 'tracking' => 'NEW-1']);
    $sent->forceFill(['dispatched_at' => Carbon::now()->subDays(5)])->save();                                                        // dispatched 5 days ago, still not delivered
    sdOrder($co, $s, 'FAILED-1', '1', 'failed');                                                                                      // its unit waits for inspection
    StockThreshold::create(['supplier_product_id' => $s['product']->id, 'project_id' => $s['project']->id, 'minimum_quantity' => '8']);   // 6 are available: under the minimum

    $before = [JournalEntry::count(), Payment::count(), (int) JournalEntry::sum('id'), DB::table('stock_movements')->count(), DB::table('audit_logs')->count(), Inventory::balances($s['line'])];
    $ex = SalesAnalytics::exceptions(ClientOrder::where('project_id', $s['project']->id)->with(['items', 'project'])->get());
    expect(collect($ex['overdue'])->pluck('order.reference')->all())->toBe(['SENT-OLD'])->and($ex['overdue'][0]['days'])->toBe(5)
        ->and(collect($ex['awaiting_inspection'])->pluck('order.reference')->all())->toBe(['FAILED-1']);
    $page = $co->as('clients')->get(route('product.projects.show', $s['project']))->assertOk();
    $page->assertSee('Pending delivery')->assertSee('Return awaiting inspection')->assertSee('Low')->assertSee('SENT-OLD');
    expect(substr_count($page->getContent(), '>Pending delivery<'))->toBe(1);                                                           // SENT-NEW (just dispatched) is not an exception
    $co->as('clients')->get(route('product.orders'))->assertOk()->assertSee('Pending 5d');
    $co->as('clients')->get(route('product.analytics'))->assertOk();
    expect([JournalEntry::count(), Payment::count(), (int) JournalEntry::sum('id'), DB::table('stock_movements')->count(), DB::table('audit_logs')->count(), Inventory::balances($s['line'])])->toBe($before);   // all read-only

    // A product well above its minimum raises no warning.
    StockThreshold::query()->update(['minimum_quantity' => '1']);
    $co->as('clients')->get(route('product.projects.show', $s['project']))->assertOk()->assertSee('No low-stock warnings');
});
