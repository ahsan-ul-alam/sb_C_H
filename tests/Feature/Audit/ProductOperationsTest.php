<?php

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\ClientSettlement;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ReceiptClassification;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\StockMovement;
use App\Models\StorageCharge;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\ClientSettlementService;
use App\Services\FinancialStatements;
use App\Services\Inventory;
use App\Services\LotFinancials;
use App\Services\StorageBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__ . '/../../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn() => Carbon::setTestNow());

/**
 * A client's own stock, made the way the business makes it: supplier → product → Quick Lot → billed to the client → arrival recorded.
 *
 * @return array{co: SellbeesCompany, client: Client, project: Project, product: SupplierProduct, lot: SourcingLot, line: SourcingLotLine}
 */
function clientStock(SellbeesCompany $co, string $qty = '100', ?string $received = null, ?string $accepted = null, string $clientName = 'Client Stock', string $price = '120', string $arrivedOn = '2026-02-10'): array
{
    $co->post('clients', 'sourcing.suppliers.store', ['name' => 'Supplier ' . Str::random(4), 'phone' => '017'])->assertSessionHasNoErrors();
    $supplier = Supplier::latest('id')->first();
    $co->post('clients', 'sourcing.products.store', ['supplier_id' => $supplier->id, 'name' => 'Hair Oil ' . Str::random(3), 'reference_price' => $price])->assertSessionHasNoErrors();
    $product = SupplierProduct::latest('id')->first();
    $client = $co->client($clientName);
    $project = $co->project($client, 'P' . Str::random(3));
    $co->post('clients', 'sourcing.lots.store', [
        'supplier_id' => $supplier->id,
        'delivery_cost' => '1000',
        'submission_key' => (string) Str::uuid(),
        'project_id' => $project->id,
        'items' => [['product_id' => $product->id, 'quantity' => $qty, 'profit' => '20']],
    ])->assertSessionHasNoErrors();
    $lot = SourcingLot::latest('id')->first();
    $line = $lot->lines()->first();
    // Arrival first when some units are short, so the client is billed for what really arrived.
    if ($received !== null) {
        $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => $received, 'accepted_quantity' => $accepted ?? $received, 'arrived_on' => $arrivedOn], $line)->assertSessionHasNoErrors();
    }
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $project->id], $lot)->assertSessionHasNoErrors();
    if ($received === null) {
        $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => $qty, 'arrived_on' => $arrivedOn], $line)->assertSessionHasNoErrors();
    }

    return ['co' => $co, 'client' => $client, 'project' => $project, 'product' => $product, 'lot' => $lot->refresh(), 'line' => $line->refresh()];
}

function placeOrder(SellbeesCompany $co, $project, $product, string $qty, string $price, string $ref = 'ORD-1')
{
    return $co->post('clients', 'product.orders.store', [
        'project_id' => $project->id,
        'reference' => $ref,
        'ordered_on' => '2026-03-06',
        'customer_name' => 'Customer',
        'items' => [['product_id' => $product->id, 'quantity' => $qty, 'price' => $price]],
    ]);
}

function orderStep(SellbeesCompany $co, string $step, ClientOrder $order, array $data = [])
{
    return $co->post('clients', 'product.orders.' . $step, $data, $order);
}

function bootProduct($test): SellbeesCompany
{
    $co = SellbeesCompany::boot($test);
    Carbon::setTestNow('2026-02-10 10:00:00');
    $co->standingPolicy('quick_lot', '100000000');

    return $co;
}

it('keeps the sourcing result and puts only what really arrived into stock', function () {
    $co = bootProduct($this);
    // Scenario 1: 100 × 120 + 1,000 delivery → landed 13,000, client 15,000, profit 2,000.
    $s = clientStock($co);
    $f = LotFinancials::for($s['lot']);
    expect($f['landed'])->toBe(1_300_000)->and($f['revenue'])->toBe(1_500_000)->and($f['profit'])->toBe(200_000)->and($s['lot']->ownership)->toBe('client');
    expect(Inventory::balances($s['line'])['available'])->toBe('100.00');

    // Ordered quantity is not stock: a fresh lot has none until its arrival is recorded.
    $co->post('clients', 'sourcing.suppliers.store', ['name' => 'Late Supplier']);
    $co->post('clients', 'sourcing.products.store', ['supplier_id' => Supplier::latest('id')->first()->id, 'name' => 'Soap', 'reference_price' => '10']);
    $co->post('clients', 'sourcing.lots.store', [
        'supplier_id' => Supplier::latest('id')->first()->id,
        'delivery_cost' => '0',
        'submission_key' => (string) Str::uuid(),
        'project_id' => $s['project']->id,
        'items' => [['product_id' => SupplierProduct::latest('id')->first()->id, 'quantity' => '50', 'profit' => '2']],
    ]);
    $unreceived = SourcingLot::latest('id')->first()->lines()->first();
    expect(Inventory::balances($unreceived)['available'])->toBe('0.00')->and(StockMovement::where('sourcing_lot_line_id', $unreceived->id)->count())->toBe(0);
});

it('receives 95 usable of 100 ordered (3 damaged, 2 missing) once, even when submitted twice', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '100', '98', '95');            // arrival: 98 came, 95 accepted → 2 missing, 3 damaged
    $line = $s['line'];
    $b = Inventory::balances($line);
    expect($b['received'])->toBe('95.00')->and($b['available'])->toBe('95.00')
        ->and(bcsub((string) $line->quantity, (string) $line->received_quantity, 2))->toBe('2.00')
        ->and(bcsub((string) $line->received_quantity, (string) $line->usable_quantity, 2))->toBe('3.00');
    expect(LotFinancials::for($s['lot'])['landed'])->toBe(1_295_000 - 0 + 5_000); // the cost of the lot is not reduced by shortage; it is spread over what arrived
    $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => '98', 'accepted_quantity' => '95', 'arrived_on' => '2026-02-10'], $line)->assertSessionHasNoErrors();   // the same request again
    expect(Inventory::balances($line)['available'])->toBe('95.00')->and(StockMovement::where('sourcing_lot_line_id', $line->id)->where('type', 'receipt')->count())->toBe(1);
    $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => '101', 'arrived_on' => '2026-02-10'], $line)->assertSessionHas('error');          // more than ordered
    expect(Inventory::balances($line)['available'])->toBe('95.00')->and(Inventory::reconciles($line))->toBeTrue();
});

it('reserves, dispatches and delivers stock exactly, and earns commission only on delivery', function () {
    $co = bootProduct($this);
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1'])->assertSessionHasNoErrors();
    $s = clientStock($co);
    expect(placeOrder($co, $s['project'], $s['product'], '10', '10')->assertSessionHasNoErrors() && true)->toBeTrue(); // 10 units × BDT 10 = BDT 100
    $order = ClientOrder::firstOrFail();
    expect($order->delivery_status)->toBe('pending')->and(Inventory::balances($s['line'])['available'])->toBe('100.00'); // pending does not reserve

    orderStep($co, 'confirm', $order)->assertSessionHasNoErrors();
    orderStep($co, 'confirm', $order)->assertSessionHas('error');                       // repeated request: no second reservation
    $b = Inventory::balances($s['line']);
    expect($b['available'])->toBe('90.00')->and($b['reserved'])->toBe('10.00')->and(ClientOrder::first()->commission)->toBe(0);

    orderStep($co, 'dispatch', $order)->assertSessionHasNoErrors();
    orderStep($co, 'deliver', $order)->assertSessionHasNoErrors();
    orderStep($co, 'deliver', $order)->assertSessionHas('error');                       // no second deduction
    $b = Inventory::balances($s['line']);
    expect($b['available'])->toBe('90.00')->and($b['reserved'])->toBe('0.00')->and($b['dispatched'])->toBe('0.00')->and($b['delivered'])->toBe('10.00')->and(Inventory::reconciles($s['line']))->toBeTrue();

    $order->refresh();
    expect($order->status)->toBe('successful')->and($order->commission)->toBe(100)->and($order->rate_bps)->toBe(100)  // BDT 100 × 1% = BDT 1.00
        ->and($order->invoice->total)->toBe(100)->and(dr('income'))->toBe(0)->and(dr('client.funds'))->toBe(0);          // delivery is not a collection and not revenue
    $key = (string) Str::uuid();
    $collect = fn(string $amount, string $k) => $co->post('clients', 'product.orders.collect', ['amount' => $amount, 'collected_on' => '2026-02-11', 'idempotency_key' => $k], $order);
    $collect('100', $key)->assertSessionHasNoErrors();
    $collect('100', $key);
    $collect('0.01', (string) Str::uuid())->assertSessionHas('error');                  // nothing left to collect
    expect(dr('client.funds'))->toBe(-10_000)->and(dr('cash'))->toBe(10_000)->and(ClientSettlementService::fundsHeld($s['client']))->toBe(10_000);
    // Client proceeds: 100 − 1 commission = 99.
    expect(ClientSettlementService::statement($s['client'])['payable'])->toBe(9_900);
});

it('refuses overselling, other clients\' stock, unowned and company stock, and handles cancel, failure, partial delivery and returns', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '100');
    $other = $co->client('Another Client');
    $otherProject = $co->project($other, 'OP');

    placeOrder($co, $s['project'], $s['product'], '101', '10', 'BIG');
    orderStep($co, 'confirm', ClientOrder::where('reference', 'BIG')->first())->assertSessionHas('error');         // more than available
    placeOrder($co, $otherProject, $s['product'], '1', '10', 'WRONG');
    orderStep($co, 'confirm', ClientOrder::where('reference', 'WRONG')->first())->assertSessionHas('error');       // another client's stock is invisible
    expect(Inventory::balances($s['line'])['reserved'])->toBe('0.00')->and(Inventory::balances($s['line'])['available'])->toBe('100.00');

    // Cancel releases a reservation; a failed delivery returns it and earns nothing.
    placeOrder($co, $s['project'], $s['product'], '10', '10', 'CANCEL');
    $cancel = ClientOrder::where('reference', 'CANCEL')->first();
    orderStep($co, 'confirm', $cancel);
    expect(Inventory::balances($s['line'])['reserved'])->toBe('10.00');
    orderStep($co, 'cancel', $cancel)->assertSessionHasNoErrors();
    expect(Inventory::balances($s['line'])['available'])->toBe('100.00')->and($cancel->refresh()->status)->toBe('cancelled')->and($cancel->commission)->toBe(0);

    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1']);
    placeOrder($co, $s['project'], $s['product'], '10', '10', 'FAIL');
    $fail = ClientOrder::where('reference', 'FAIL')->first();
    orderStep($co, 'confirm', $fail);
    orderStep($co, 'dispatch', $fail);
    orderStep($co, 'fail', $fail)->assertSessionHasNoErrors();
    $b = Inventory::balances($s['line']);
    expect($b['available'])->toBe('100.00')->and($b['dispatched'])->toBe('0.00')->and($fail->refresh()->status)->toBe('cancelled')->and($fail->commission)->toBe(0)->and($fail->billed_invoice_id)->toBeNull();
    $co->post('clients', 'product.orders.collect', ['amount' => '1', 'collected_on' => '2026-02-11', 'idempotency_key' => (string) Str::uuid()], $fail)->assertSessionHas('error'); // no money for an undelivered order

    // Partial delivery: 6 of 10 delivered, 4 go back; commission on 6 × 10 = 60 → 0.60.
    placeOrder($co, $s['project'], $s['product'], '10', '10', 'PART');
    $part = ClientOrder::where('reference', 'PART')->first();
    orderStep($co, 'confirm', $part);
    orderStep($co, 'dispatch', $part);
    orderStep($co, 'deliver', $part, ['delivered' => [$part->items()->first()->id => '6']])->assertSessionHasNoErrors();
    $b = Inventory::balances($s['line']);
    expect($b['delivered'])->toBe('6.00')->and($b['available'])->toBe('94.00')->and($part->refresh()->net_value)->toBe(6_000)->and($part->commission)->toBe(60);

    // A returned unit is NOT sellable until inspected.
    $item = $part->items()->first();
    orderStep($co, 'return', $part, ['returned' => [$item->id => '2']])->assertSessionHasNoErrors();
    $b = Inventory::balances($s['line']);
    expect($b['returned'])->toBe('2.00')->and($b['available'])->toBe('94.00')->and($part->refresh()->commission)->toBe(40)->and($part->net_value)->toBe(4_000);
    $co->post('clients', 'product.items.inspect', ['usable' => '1', 'damaged' => '1'], $item)->assertSessionHasNoErrors();
    $b = Inventory::balances($s['line']);
    expect($b['available'])->toBe('95.00')->and($b['damaged'])->toBe('1.00')->and($b['returned'])->toBe('0.00')->and(Inventory::reconciles($s['line']))->toBeTrue();
    $co->post('clients', 'product.items.inspect', ['usable' => '1', 'damaged' => '0'], $item)->assertSessionHas('error');   // nothing left to inspect

    // Unowned and company-owned stock cannot be sold through client settlement.
    $lot = $s['lot'];
    $lot->update(['ownership' => null]);
    placeOrder($co, $s['project'], $s['product'], '1', '10', 'NOOWNER');
    orderStep($co, 'confirm', ClientOrder::where('reference', 'NOOWNER')->first())->assertSessionHas('error');
    $co->post('clients', 'product.lots.ownership', ['ownership' => 'company'], $lot)->assertSessionHasNoErrors();
    orderStep($co, 'confirm', ClientOrder::where('reference', 'NOOWNER')->first())->assertSessionHas('error');
    $co->post('clients', 'product.lots.ownership', ['ownership' => 'client'], $lot)->assertSessionHas('error');         // ownership is fixed once stated
    expect($lot->refresh()->ownership)->toBe('company');
});

it('takes the oldest stock first, across lots, and keeps the source lot on each line', function () {
    $co = bootProduct($this);
    Carbon::setTestNow('2026-02-01 10:00:00');
    $old = clientStock($co, '30', null, null, 'FIFO Client', '120', '2026-02-01');
    Carbon::setTestNow('2026-02-05 10:00:00');
    // A second lot of the SAME product for the same client, arriving later.
    $co->post('clients', 'sourcing.lots.store', [
        'supplier_id' => $old['line']->supplier_id,
        'delivery_cost' => '0',
        'submission_key' => (string) Str::uuid(),
        'project_id' => $old['project']->id,
        'items' => [['product_id' => $old['product']->id, 'quantity' => '100', 'profit' => '5']],
    ])->assertSessionHasNoErrors();
    $newLot = SourcingLot::latest('id')->first();
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $old['project']->id], $newLot)->assertSessionHasNoErrors();
    $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => '100', 'arrived_on' => '2026-02-05'], $newLot->lines()->first())->assertSessionHasNoErrors();

    placeOrder($co, $old['project'], $old['product'], '50', '20', 'MIX');
    $order = ClientOrder::where('reference', 'MIX')->first();
    orderStep($co, 'confirm', $order)->assertSessionHasNoErrors();
    $items = $order->items()->get();
    expect($items)->toHaveCount(2)->and($items[0]->sourcing_lot_line_id)->toBe($old['line']->id)->and((string) $items[0]->quantity)->toBe('30.00')
        ->and($items[1]->sourcing_lot_line_id)->toBe($newLot->lines()->first()->id)->and((string) $items[1]->quantity)->toBe('20.00');
    expect(Inventory::balances($old['line'])['available'])->toBe('0.00')->and(Inventory::balances($newLot->lines()->first())['available'])->toBe('80.00');
});

it('bills storage monthly only under the Board policy, once per lot and month, never from an invented area', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '200');                         // 200 units on hand
    $co->post('finance', 'finance.orders.storage', ['storage_rate' => '50', 'storage_threshold' => '100'])->assertSessionHasNoErrors();
    Carbon::setTestNow('2026-03-05 10:00:00');

    expect(fn() => StorageBilling::runMonth('2026-02'))->toThrow(RuntimeException::class, 'not authorised');       // no policy: nothing is charged
    $co->standingPolicy('storage');
    $out = StorageBilling::runMonth('2026-02');                                                                    // approved, but the area was never measured
    expect($out['needs_measurement'])->toBe([$s['lot']->number])->and($out['billed'])->toBe([])->and(Invoice::where('purpose', 'Warehouse storage')->count())->toBe(0);

    $co->post('clients', 'product.lots.area', ['storage_sqft' => '20'], $s['lot'])->assertSessionHasNoErrors();
    Carbon::setTestNow('2026-03-06 10:00:00');
    $out = StorageBilling::runMonth('2026-02');
    $charge = StorageCharge::firstOrFail();
    expect($out['billed'])->toBe([$s['lot']->number])->and($charge->amount)->toBe(100_000)->and($charge->quantity)->toBe('200.00')->and($charge->sqft)->toBe('20.00')->and($charge->rate)->toBe(5_000)
        ->and($charge->invoice->total)->toBe(100_000)->and($charge->invoice->items()->first()->unit)->toBe('sq ft');          // 20 sq ft × BDT 50 = BDT 1,000
    StorageBilling::runMonth('2026-02');
    StorageBilling::runMonth('2026-02');
    expect(StorageCharge::count())->toBe(1)->and(Invoice::where('purpose', 'Warehouse storage')->count())->toBe(1);       // repeated runs add nothing

    // A later rate change never touches the issued bill.
    $co->post('finance', 'finance.orders.storage', ['storage_rate' => '80', 'storage_threshold' => '100']);
    expect($charge->refresh()->amount)->toBe(100_000)->and($charge->invoice->refresh()->total)->toBe(100_000);

    // By the next cut-off the lot is down to 100 units: not MORE than the threshold → no charge. (190 sold + delivered in March.)
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1']);
    foreach ([['A1', '100']] as [$ref, $qty]) {
        placeOrder($co, $s['project'], $s['product'], $qty, '10', $ref);
        $o = ClientOrder::where('reference', $ref)->first();
        orderStep($co, 'confirm', $o);
        orderStep($co, 'dispatch', $o);
        orderStep($co, 'deliver', $o)->assertSessionHasNoErrors();
    }
    expect(StorageBilling::onHand($s['lot'], '2026-03-31'))->toBe('100.00');
    Carbon::setTestNow('2026-04-05 10:00:00');
    $out = StorageBilling::runMonth('2026-03');
    expect($out['billed'])->toBe([])->and(StorageCharge::where('period', '2026-03')->count())->toBe(0);
    expect(fn() => StorageBilling::runMonth('2026-04'))->toThrow(RuntimeException::class, 'finished month');
});

it('settles a client: collected sales less commission and unpaid storage, partial payouts, balanced books, income recognised once', function () {
    $co = bootProduct($this);
    $co->invest('A', '100000', '2026-02-01');
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1']);
    $co->post('finance', 'finance.orders.storage', ['storage_rate' => '50', 'storage_threshold' => '100', 'storage_deduct' => '1'])->assertSessionHasNoErrors();
    $co->standingPolicy('storage');
    $s = clientStock($co, '200');
    $co->post('clients', 'product.lots.area', ['storage_sqft' => '10'], $s['lot']);
    // Supplier and delivery are paid in February so the company has no open sourcing obligations.
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $s['line']->supplier_id, 'amount' => '24000', 'paid_on' => '2026-02-12', 'idempotency_key' => (string) Str::uuid()], $s['lot'])->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.costs.pay', ['paid_on' => '2026-02-12'], $s['lot']->costs()->first())->assertSessionHasNoErrors();
    $co->verifyAllExpenses();

    Carbon::setTestNow('2026-03-05 10:00:00');
    StorageBilling::runMonth('2026-02');                                          // 10 sq ft × BDT 50 = BDT 500 (unpaid)
    Carbon::setTestNow('2026-03-06 10:00:00');
    placeOrder($co, $s['project'], $s['product'], '10', '1000');                    // BDT 10,000
    $order = ClientOrder::firstOrFail();
    orderStep($co, 'confirm', $order);
    orderStep($co, 'dispatch', $order);
    orderStep($co, 'deliver', $order)->assertSessionHasNoErrors();
    expect($order->refresh()->commission)->toBe(10_000);                           // BDT 100
    $co->post('clients', 'product.orders.collect', ['amount' => '10000', 'collected_on' => '2026-03-07', 'idempotency_key' => (string) Str::uuid()], $order)->assertSessionHasNoErrors();

    $st = ClientSettlementService::statement($s['client']);
    expect([$st['collected'], $st['commission_due'], $st['storage_due'], $st['netting'], $st['payable']])->toBe([1_000_000, 10_000, 50_000, 60_000, 940_000]); // 10,000 − 100 − 500 = 9,400
    $co->as('finance')->get(route('product.settlements.show', $s['client']))->assertOk()->assertSee('9,400.00')->assertSee('Record client settlement');

    // Scenario 8: pay 5,000 first, 4,400 stays payable. Real payouts need Finance, a reference and sufficient bank cash.
    $settle = fn(string $pay, string $key, string $ref = 'TRX-1') => $co->post('finance', 'product.settlements.store', ['pay' => $pay, 'paid_on' => '2026-03-08', 'reference' => $ref, 'idempotency_key' => $key], $s['client']);
    $co->post('clients', 'product.settlements.store', ['paid_on' => '2026-03-08', 'idempotency_key' => (string) Str::uuid()], $s['client'])->assertForbidden();
    $settle('9400.01', (string) Str::uuid())->assertSessionHas('error');                                                  // more than payable
    $settle('5000', (string) Str::uuid(), '')->assertSessionHas('error');                                                 // a payout needs its transfer reference
    $key = (string) Str::uuid();
    $settle('5000', $key)->assertSessionHasNoErrors();
    $settle('5000', $key);                                                                                                // duplicate request
    $st = ClientSettlementService::statement($s['client']);
    expect(ClientSettlement::count())->toBe(1)->and($st['payable'])->toBe(440_000)->and($st['funds_held'])->toBe(440_000)->and($st['commission_due'])->toBe(0)->and($st['storage_due'])->toBe(0);
    expect(dr('client.funds'))->toBe(-440_000);                                                                         // ledger agrees with the statement
    // The fees were netted as ordinary ClientHub payments on their own invoices: both invoices are paid, exactly once.
    expect($order->invoice->refresh()->balance())->toBe(0)->and(StorageCharge::first()->invoice->refresh()->balance())->toBe(0)
        ->and(Payment::where('method', 'other')->sum('amount'))->toBe(60_000)->and(ReceiptClassification::count())->toBe(2);
    $settle('4400', (string) Str::uuid(), 'TRX-2')->assertSessionHasNoErrors();
    expect(dr('client.funds'))->toBe(0)->and(ClientSettlementService::fundsHeld($s['client']))->toBe(0);
    $settle('1', (string) Str::uuid(), 'TRX-3')->assertSessionHas('error');                                              // nothing left to settle

    // Main Finance: close March. Only the 600 of fees is company income (not the 10,000 sale); the settlement payouts are not expenses.
    Carbon::setTestNow('2026-04-10 10:00:00');
    expect(FinancialStatements::monthFigures('2026-03'))->toMatchArray(['income' => 60_000, 'expenses' => 0]);
    $co->post('finance', 'finance.periods.close', ['month' => '2026-03'])->assertSessionHasNoErrors();
    $period = AccountingPeriod::firstWhere('month', '2026-03');
    expect([$period->income, $period->expenses, $period->net_profit])->toBe([60_000, 0, 60_000]);
    $co->post('board', 'finance.periods.verify', [], $period)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.periods.prepare', [], $period)->assertSessionHasNoErrors();
    $a = Allocation::firstOrFail();
    $co->post('board', 'finance.allocations.approve', [], $a);
    $co->post('finance', 'finance.allocations.post', [], $a)->assertSessionHasNoErrors();
    $A = Partner::firstWhere('code', 'A')->id;
    $B = Partner::firstWhere('code', 'B')->id;
    expect(-dr('fund.salary'))->toBe(12_000)->and(-dr('fund.office'))->toBe(3_000)->and(-dr('fund.emergency'))->toBe(15_000)   // 20% / 5% / 25% of BDT 600
        ->and(-dr('partner.payable', $A))->toBe(12_000)->and(-dr('partner.payable', $B))->toBe(9_000);                         // pool 300: 40% / 30%
    // Cash: capital +100,000 − supplier/delivery 25,000 + collections 10,000 − settlements 10,000 (all of it) + March fee income 600.
    expect(dr('cash'))->toBe(10_000_000 - 2_500_000 + 1_000_000 - 1_000_000 + 60_000)->and(FinancialStatements::position()['balanced'])->toBeTrue()
        ->and(ledgerAudit())->toMatchArray(['unbalanced' => 0, 'duplicates' => 0, 'orphans' => []]);
    $co->as('board')->get(route('finance.position'))->assertOk()->assertSee('Client funds held');
});

it('deducts only what is still owed on a storage bill the client part-paid', function () {
    $co = bootProduct($this);
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '0']);
    $co->post('finance', 'finance.orders.storage', ['storage_rate' => '50', 'storage_threshold' => '100', 'storage_deduct' => '1']);
    $co->standingPolicy('storage');
    $s = clientStock($co, '200');
    $co->post('clients', 'product.lots.area', ['storage_sqft' => '20'], $s['lot']);
    Carbon::setTestNow('2026-03-05 10:00:00');
    StorageBilling::runMonth('2026-02');                                            // BDT 1,000
    $invoice = StorageCharge::first()->invoice;
    $co->payInvoice($invoice, '600', '2026-03-05')->assertSessionHasNoErrors();      // the client already paid 600 directly
    placeOrder($co, $s['project'], $s['product'], '1', '2000');
    $o = ClientOrder::first();
    orderStep($co, 'confirm', $o);
    orderStep($co, 'dispatch', $o);
    orderStep($co, 'deliver', $o);
    $co->post('clients', 'product.orders.collect', ['amount' => '2000', 'collected_on' => '2026-03-06', 'idempotency_key' => (string) Str::uuid()], $o);
    $st = ClientSettlementService::statement($s['client']);
    expect($st['storage_due'])->toBe(40_000)->and($st['payable'])->toBe(160_000);                                    // owes 400, not 1,000; client gets 1,600
    $co->post('finance', 'product.settlements.store', ['paid_on' => '2026-03-07', 'reference' => 'T1', 'idempotency_key' => (string) Str::uuid()], $s['client'])->assertSessionHasNoErrors();
    expect($invoice->refresh()->balance())->toBe(0)->and($invoice->amount_paid)->toBe(100_000)->and(dr('client.funds'))->toBe(0);

    // Not enough client money to cover the fee: only what is collectible is deducted; the rest stays a client receivable.
    Carbon::setTestNow('2026-02-12 10:00:00');
    $s2 = clientStock($co, '200', null, null, 'Poor Client');
    $co->post('clients', 'product.lots.area', ['storage_sqft' => '20'], $s2['lot']);
    Carbon::setTestNow('2026-03-08 10:00:00');
    StorageBilling::runMonth('2026-02');
    $bill = StorageCharge::where('client_id', $s2['client']->id)->first();
    expect($bill)->not->toBeNull();
    placeOrder($co, $s2['project'], $s2['product'], '1', '300', 'POOR');
    $p = ClientOrder::where('reference', 'POOR')->first();
    orderStep($co, 'confirm', $p);
    orderStep($co, 'dispatch', $p);
    orderStep($co, 'deliver', $p);
    $co->post('clients', 'product.orders.collect', ['amount' => '300', 'collected_on' => '2026-03-08', 'idempotency_key' => (string) Str::uuid()], $p);
    $co->post('finance', 'product.settlements.store', ['paid_on' => '2026-03-09', 'reference' => 'T2', 'idempotency_key' => (string) Str::uuid()], $s2['client'])->assertSessionHasNoErrors();
    expect($bill->invoice->refresh()->balance())->toBe(70_000)->and(ClientSettlementService::fundsHeld($s2['client']))->toBe(0)           // 300 netted, 700 remains owed by the client
        ->and(ClientSettlement::where('client_id', $s2['client']->id)->first()->paid)->toBe(0);                                          // never a negative payout
});

it('keeps product operations inside the permission map', function () {
    $co = bootProduct($this);
    $s = clientStock($co);
    foreach (['employee', 'hr', 'partnerA'] as $who) {
        $co->as($who)->get(route('product.inventory'))->assertForbidden();
        $co->as($who)->get(route('product.orders'))->assertForbidden();
    }
    foreach (['auditor', 'board', 'finance', 'clients'] as $who) {
        $co->as($who)->get(route('product.inventory'))->assertOk();
        $co->as($who)->get(route('product.inventory.show', $s['line']))->assertOk();
    }
    $co->as('clients')->get(route('product.orders', ['project' => $s['project']->id]))->assertOk()->assertSee('Hair Oil');
    $co->as('finance')->get(route('product.settlements'))->assertOk();
    $co->as('employee')->get(route('product.settlements'))->assertForbidden();
    $co->post('auditor', 'product.orders.store', ['project_id' => $s['project']->id])->assertForbidden();
    $co->post('clients', 'product.inventory.adjust', ['delta' => '-1', 'reason' => 'x'], $s['line'])->assertForbidden();         // stock adjustments are Finance's
    $co->post('finance', 'product.inventory.adjust', ['delta' => '-5', 'reason' => ''], $s['line'])->assertSessionHasErrors('reason');
    $co->post('finance', 'product.inventory.adjust', ['delta' => '-5', 'reason' => 'stock-take difference'], $s['line'])->assertSessionHasNoErrors();
    $co->post('finance', 'product.inventory.adjust', ['delta' => '-500', 'reason' => 'too many'], $s['line'])->assertSessionHas('error');  // never negative
    expect(Inventory::balances($s['line'])['available'])->toBe('95.00')->and(Inventory::balances($s['line'])['adjusted'])->toBe('-5.00')->and(Inventory::reconciles($s['line']))->toBeTrue();
    $co->as('clients')->get(route('product.inventory', ['client' => $s['client']->id, 'availability' => 'in_stock']))->assertOk()->assertSee('Hair Oil');
    expect(Expense::count())->toBe(0);                                                                                  // none of this created an expense
});
