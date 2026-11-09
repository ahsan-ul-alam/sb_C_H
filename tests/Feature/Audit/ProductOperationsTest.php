<?php

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\ClientOrderItem;
use App\Models\ClientSettlement;
use App\Models\CourierAdvance;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OrderCollection;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ReceiptClassification;
use App\Models\SourcingAllocation;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\StockMovement;
use App\Models\StorageCharge;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\AccountingService;
use App\Services\ClientSettlementService;
use App\Services\CourierAdvanceService;
use App\Services\FinancialStatements;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\LotFinancials;
use App\Services\OrderCommission;
use App\Services\OrderFulfilment;
use App\Services\PeriodProcessor;
use App\Services\ProjectEconomics;
use App\Services\ProjectStockAllocation;
use App\Services\RevenueRecognition;
use App\Services\StorageBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/**
 * A client's own stock, made the way the business makes it: supplier → product → Quick Lot → billed to the client → arrival recorded.
 *
 * @return array{co: SellbeesCompany, client: Client, project: Project, product: SupplierProduct, lot: SourcingLot, line: SourcingLotLine}
 */
function clientStock(SellbeesCompany $co, string $qty = '100', ?string $received = null, ?string $accepted = null, string $clientName = 'Client Stock', string $price = '120', string $arrivedOn = '2026-02-10', string $profit = '20', string $deliveryCost = '1000'): array
{
    $co->post('clients', 'sourcing.suppliers.store', ['name' => 'Supplier '.Str::random(4), 'phone' => '017'])->assertSessionHasNoErrors();
    $supplier = Supplier::latest('id')->first();
    $co->post('clients', 'sourcing.products.store', ['supplier_id' => $supplier->id, 'name' => 'Hair Oil '.Str::random(3), 'reference_price' => $price])->assertSessionHasNoErrors();
    $product = SupplierProduct::latest('id')->first();
    $client = $co->client($clientName);
    $project = $co->project($client, 'P'.Str::random(3));
    $co->post('clients', 'sourcing.lots.store', [
        'supplier_id' => $supplier->id,
        'delivery_cost' => $deliveryCost,
        'submission_key' => (string) Str::uuid(),
        'project_id' => $project->id,
        'items' => [['product_id' => $product->id, 'quantity' => $qty, 'profit' => $profit]],
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
    return $co->post('clients', 'product.orders.'.$step, $data, $order);
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
    $collect = fn (string $amount, string $k) => $co->post('clients', 'product.orders.collect', ['amount' => $amount, 'collected_on' => '2026-02-11', 'idempotency_key' => $k], $order);
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

    placeOrder($co, $s['project'], $s['product'], '101', '10', 'BIG')->assertSessionHas('error');                    // more than available is refused at creation
    expect(ClientOrder::where('reference', 'BIG')->exists())->toBeFalse();
    placeOrder($co, $otherProject, $s['product'], '1', '10', 'WRONG')->assertSessionHas('error');                    // another client's stock cannot even be put on an order
    expect(ClientOrder::where('reference', 'WRONG')->exists())->toBeFalse();
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
    expect($b['available'])->toBe('90.00')->and($b['returned'])->toBe('10.00')->and($b['dispatched'])->toBe('0.00')->and($fail->refresh()->status)->toBe('cancelled')->and($fail->commission)->toBe(0)->and($fail->billed_invoice_id)->toBeNull();
    $co->post('clients', 'product.orders.collect', ['amount' => '1', 'collected_on' => '2026-02-11', 'idempotency_key' => (string) Str::uuid()], $fail)->assertSessionHas('error'); // no money for an undelivered order
    $co->post('clients', 'product.items.inspect', ['usable' => '10', 'damaged' => '0'], $fail->items()->first())->assertSessionHasNoErrors(); // inspected usable: sellable again
    expect(Inventory::balances($s['line'])['available'])->toBe('100.00');

    // Partial delivery: 6 of 10 delivered, 4 go back; commission on 6 × 10 = 60 → 0.60.
    placeOrder($co, $s['project'], $s['product'], '10', '10', 'PART');
    $part = ClientOrder::where('reference', 'PART')->first();
    orderStep($co, 'confirm', $part);
    orderStep($co, 'dispatch', $part);
    orderStep($co, 'deliver', $part, ['delivered' => [$part->items()->first()->id => '6']])->assertSessionHasNoErrors();
    $b = Inventory::balances($s['line']);
    expect($b['delivered'])->toBe('6.00')->and($b['available'])->toBe('90.00')->and($b['returned'])->toBe('4.00')->and($part->refresh()->net_value)->toBe(6_000)->and($part->commission)->toBe(60);
    $co->post('clients', 'product.items.inspect', ['usable' => '4', 'damaged' => '0'], $part->items()->first())->assertSessionHasNoErrors();   // the 4 not delivered are inspected back into stock
    expect(Inventory::balances($s['line'])['available'])->toBe('94.00');

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
    placeOrder($co, $s['project'], $s['product'], '1', '10', 'NOOWNER')->assertSessionHas('error');                    // unowned stock cannot even be put on an order
    $co->post('clients', 'product.lots.ownership', ['ownership' => 'company'], $lot)->assertSessionHasNoErrors();
    placeOrder($co, $s['project'], $s['product'], '1', '10', 'NOOWNER')->assertSessionHas('error');                    // nor can company-owned stock
    expect(ClientOrder::where('reference', 'NOOWNER')->exists())->toBeFalse();
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

    expect(fn () => StorageBilling::runMonth('2026-02'))->toThrow(RuntimeException::class, 'not authorised');       // no policy: nothing is charged
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
    expect(fn () => StorageBilling::runMonth('2026-04'))->toThrow(RuntimeException::class, 'finished month');
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
    $settle = fn (string $pay, string $key, string $ref = 'TRX-1') => $co->post('finance', 'product.settlements.store', ['pay' => $pay, 'paid_on' => '2026-03-08', 'reference' => $ref, 'idempotency_key' => $key], $s['client']);
    $co->post('clients', 'product.settlements.store', ['paid_on' => '2026-03-08', 'idempotency_key' => (string) Str::uuid()], $s['client'])->assertForbidden();
    $settle('9400.01', (string) Str::uuid())->assertSessionHas('error');                                                  // more than payable
    $settle('5000', (string) Str::uuid(), '')->assertSessionHas('error');                                               // a payout needs its transfer reference
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

// ───────────── project-wise inventory and order management ─────────────

it('packs without moving stock, dispatches with courier and tracking, and releases a cancelled reservation exactly once', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '100');
    placeOrder($co, $s['project'], $s['product'], '10', '10', 'PK1')->assertSessionHasNoErrors();
    $o = ClientOrder::where('reference', 'PK1')->first();
    orderStep($co, 'pack', $o)->assertSessionHas('error');                                    // cannot pack an unconfirmed order
    orderStep($co, 'confirm', $o)->assertSessionHasNoErrors();
    orderStep($co, 'pack', $o)->assertSessionHasNoErrors();
    expect($o->refresh()->delivery_status)->toBe('packed')->and($o->packed_at)->not->toBeNull()->and($o->confirmed_at)->not->toBeNull()
        ->and(Inventory::balances($s['line'])['reserved'])->toBe('10.00')->and(Inventory::balances($s['line'])['available'])->toBe('90.00');
    orderStep($co, 'pack', $o)->assertSessionHas('error');                                    // a repeated pack changes nothing

    orderStep($co, 'cancel', $o)->assertSessionHasNoErrors();                                 // a packed order can still be cancelled: reserved goes back once
    orderStep($co, 'cancel', $o)->assertSessionHas('error');
    expect(Inventory::balances($s['line'])['available'])->toBe('100.00')->and(Inventory::balances($s['line'])['reserved'])->toBe('0.00');

    placeOrder($co, $s['project'], $s['product'], '10', '10', 'PK2');
    $p = ClientOrder::where('reference', 'PK2')->first();
    orderStep($co, 'confirm', $p);
    orderStep($co, 'dispatch', $p, ['courier' => 'Pathao', 'tracking_reference' => 'TRK-77'])->assertSessionHasNoErrors();
    expect($p->refresh()->courier)->toBe('Pathao')->and($p->tracking_reference)->toBe('TRK-77')->and($p->dispatched_at)->not->toBeNull();
    orderStep($co, 'cancel', $p)->assertSessionHas('error');                                  // dispatched stock cannot be released as if it were still reserved
    expect(Inventory::balances($s['line'])['dispatched'])->toBe('10.00')->and(Inventory::balances($s['line'])['available'])->toBe('90.00');
});

it('never reserves the same units twice and never lets available go negative', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '10');
    placeOrder($co, $s['project'], $s['product'], '7', '10', 'A')->assertSessionHasNoErrors();
    placeOrder($co, $s['project'], $s['product'], '7', '10', 'B')->assertSessionHasNoErrors();
    $a = ClientOrder::where('reference', 'A')->first();
    $b = ClientOrder::where('reference', 'B')->first();
    orderStep($co, 'confirm', $a)->assertSessionHasNoErrors();
    orderStep($co, 'confirm', $b)->assertSessionHas('error');                                 // only 3 left
    orderStep($co, 'confirm', $a)->assertSessionHas('error');                                 // a repeated confirm reserves nothing more
    $bal = Inventory::balances($s['line']);
    expect($bal['available'])->toBe('3.00')->and($bal['reserved'])->toBe('7.00')->and($b->refresh()->delivery_status)->toBe('pending')->and(Inventory::reconciles($s['line']))->toBeTrue();
});

it('keeps one project\'s stock away from another project of the same client unless it is explicitly allocated', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '50');
    $second = $co->project($s['client'], 'Second project');                                   // same client, different project

    placeOrder($co, $second, $s['product'], '5', '10', 'X-PROJ')->assertSessionHas('error');
    expect(ClientOrder::where('reference', 'X-PROJ')->exists())->toBeFalse()->and(Inventory::balances($s['line'])['reserved'])->toBe('0.00');
    placeOrder($co, $s['project'], $s['product'], '5', '10', 'OWN')->assertSessionHasNoErrors();   // its own project still can

    SourcingAllocation::create([
        'sourcing_lot_id' => $s['lot']->id,
        'project_id' => $second->id,
        'kind' => 'sale',
        'description' => 'Agreed share',
        'quantity' => '10',
        'unit_price' => 1_000,
        'created_by' => $co->users['finance']->id,
    ]);
    placeOrder($co, $second, $s['product'], '5', '10', 'BILLING-ONLY')->assertSessionHas('error');   // a billing allocation gives the project no stock
    ProjectStockAllocation::allocate($s['line'], $second, '10', 'Agreed share', $co->users['finance']);
    placeOrder($co, $second, $s['product'], '5', '10', 'ALLOCATED')->assertSessionHasNoErrors();   // an explicit stock allocation does
});

it('returns a failed delivery to custody for inspection and lets each returned unit be inspected only once', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '100');
    placeOrder($co, $s['project'], $s['product'], '10', '10', 'F1');
    placeOrder($co, $s['project'], $s['product'], '10', '10', 'F2');
    $f1 = ClientOrder::where('reference', 'F1')->first();
    $f2 = ClientOrder::where('reference', 'F2')->first();
    foreach ([$f1, $f2] as $o) {
        orderStep($co, 'confirm', $o);
        orderStep($co, 'dispatch', $o);
        orderStep($co, 'fail', $o)->assertSessionHasNoErrors();
        orderStep($co, 'fail', $o)->assertSessionHas('error');                                // a failure is recorded once
    }
    $bal = Inventory::balances($s['line']);
    expect($bal['available'])->toBe('80.00')->and($bal['returned'])->toBe('20.00')->and($bal['dispatched'])->toBe('0.00');   // not sellable yet
    $i1 = $f1->items()->first();

    $co->post('clients', 'product.items.inspect', ['usable' => '11', 'damaged' => '0'], $i1)->assertSessionHas('error');     // more than this line brought back
    $co->post('clients', 'product.items.inspect', ['usable' => '7', 'damaged' => '3'], $i1)->assertSessionHasNoErrors();
    $co->post('clients', 'product.items.inspect', ['usable' => '1', 'damaged' => '0'], $i1)->assertSessionHas('error');       // already inspected: nothing left on this line
    $bal = Inventory::balances($s['line']);
    expect($bal['available'])->toBe('87.00')->and($bal['damaged'])->toBe('3.00')->and($bal['returned'])->toBe('10.00')->and(Inventory::reconciles($s['line']))->toBeTrue();   // F2's units are still waiting
    expect(OrderFulfilment::awaitingInspection($f2->items()->first()))->toBe('10.00');
});

it('labels partial delivery and partial or full return without a second status that could drift', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '100');
    placeOrder($co, $s['project'], $s['product'], '10', '10', 'L1');
    $o = ClientOrder::where('reference', 'L1')->first();
    expect($o->fulfilmentLabel())->toBe('Draft');
    orderStep($co, 'confirm', $o);
    orderStep($co, 'dispatch', $o);
    $item = $o->items()->first();
    orderStep($co, 'deliver', $o, ['delivered' => [$item->id => '8']])->assertSessionHasNoErrors();
    expect($o->refresh()->load('items')->fulfilmentLabel())->toBe('Partially delivered');
    orderStep($co, 'return', $o, ['returned' => [$item->id => '3']])->assertSessionHasNoErrors();
    expect($o->refresh()->load('items')->fulfilmentLabel())->toBe('Partially returned');
    orderStep($co, 'return', $o, ['returned' => [$item->id => '5']])->assertSessionHasNoErrors();
    expect($o->refresh()->load('items')->fulfilmentLabel())->toBe('Returned');
    orderStep($co, 'return', $o, ['returned' => [$item->id => '1']])->assertSessionHas('error');   // cannot return more than was delivered
});

it('never edits or deletes a stock movement, and shows a project only its own inventory and orders', function () {
    $co = bootProduct($this);
    $s = clientStock($co, '40', clientName: 'Mine');
    $other = clientStock($co, '25', clientName: 'Theirs');
    placeOrder($co, $s['project'], $s['product'], '4', '10', 'MINE-1');
    orderStep($co, 'confirm', ClientOrder::where('reference', 'MINE-1')->first());

    $movement = StockMovement::where('sourcing_lot_line_id', $s['line']->id)->first();
    expect(fn () => $movement->update(['reason' => 'tamper']))->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);

    $page = $co->as('clients')->get(route('product.projects.show', $s['project']))->assertOk()->assertSee('MINE-1')->assertSee($s['product']->name);
    $page->assertDontSee($other['product']->name);
    $co->as('auditor')->get(route('product.projects.show', $s['project']))->assertOk();            // read-only roles can look
    $co->post('auditor', 'product.orders.pack', [], ClientOrder::where('reference', 'MINE-1')->first())->assertForbidden();   // but not act
});

// ───────────── quantity-based project allocation and project access ─────────────

/** A lot of 100 owned by project one, with a second and a third project of the same client. */
function sharedLot(SellbeesCompany $co): array
{
    $s = clientStock($co, '100');
    $s['second'] = $co->project($s['client'], 'Second');
    $s['third'] = $co->project($s['client'], 'Third');

    return $s;
}

it('lets a project sell only the quantity allocated to it, never the whole lot', function () {
    $co = bootProduct($this);
    $s = sharedLot($co);
    $finance = $co->users['finance'];
    ProjectStockAllocation::allocate($s['line'], $s['second'], '20', 'Share for the second project', $finance);

    placeOrder($co, $s['second'], $s['product'], '21', '10', 'S21')->assertSessionHas('error');     // 20 allocated must never authorise 21 (or 100)
    placeOrder($co, $s['second'], $s['product'], '20', '10', 'S20')->assertSessionMissing('error');
    placeOrder($co, $s['project'], $s['product'], '81', '10', 'H81')->assertSessionHas('error');     // the home project keeps 80
    placeOrder($co, $s['project'], $s['product'], '80', '10', 'H80')->assertSessionMissing('error');
    placeOrder($co, $s['third'], $s['product'], '1', '10', 'T1')->assertSessionHas('error');         // an unallocated project gets nothing
    expect(ClientOrder::whereIn('reference', ['S21', 'H81', 'T1'])->exists())->toBeFalse();

    orderStep($co, 'confirm', ClientOrder::where('reference', 'S20')->first())->assertSessionMissing('error');
    orderStep($co, 'confirm', ClientOrder::where('reference', 'H80')->first())->assertSessionMissing('error');
    $b = Inventory::balances($s['line']);
    expect($b['reserved'])->toBe('100.00')->and($b['available'])->toBe('0.00')->and(ProjectStockAllocation::remaining($s['line'], $s['second']->id))->toBe('0.00')
        ->and(ProjectStockAllocation::remaining($s['line'], $s['project']->id))->toBe('0.00');
});

it('re-checks the limit when an order is confirmed, and when an allocation is taken back', function () {
    $co = bootProduct($this);
    $s = sharedLot($co);
    $finance = $co->users['finance'];
    $alloc = ProjectStockAllocation::allocate($s['line'], $s['second'], '20', 'Share', $finance);

    placeOrder($co, $s['second'], $s['product'], '15', '10', 'D1')->assertSessionMissing('error');
    placeOrder($co, $s['second'], $s['product'], '15', '10', 'D2')->assertSessionMissing('error');   // two drafts may each fit alone
    orderStep($co, 'confirm', ClientOrder::where('reference', 'D1')->first())->assertSessionMissing('error');
    orderStep($co, 'confirm', ClientOrder::where('reference', 'D2')->first())->assertSessionHas('error');   // but the limit holds at confirmation
    expect(Inventory::balances($s['line'])['reserved'])->toBe('15.00');

    expect(fn () => ProjectStockAllocation::cancel($alloc, $finance))->toThrow(RuntimeException::class, 'already used');   // 15 of the 20 are in use
    $unused = ProjectStockAllocation::allocate($s['line'], $s['third'], '10', 'Share', $finance);
    placeOrder($co, $s['third'], $s['product'], '10', '10', 'THIRD')->assertSessionMissing('error');
    ProjectStockAllocation::cancel($unused, $finance);                                                // nothing used yet: allowed
    orderStep($co, 'confirm', ClientOrder::where('reference', 'THIRD')->first())->assertSessionHas('error');   // the draft lost its entitlement
    expect(fn () => ProjectStockAllocation::cancel($unused, $finance))->toThrow(RuntimeException::class, 'already cancelled');
    expect(fn () => $unused->delete())->toThrow(LogicException::class);

    // Only free units of a client's own lot can move, and only within that client.
    expect(fn () => ProjectStockAllocation::allocate($s['line'], $s['third'], '81', 'Too many', $finance))->toThrow(RuntimeException::class, 'free to allocate');
    $stranger = $co->project($co->client('Other client'), 'Elsewhere');
    expect(fn () => ProjectStockAllocation::allocate($s['line'], $stranger, '1', 'Wrong client', $finance))->toThrow(RuntimeException::class, 'same client');
    expect(fn () => ProjectStockAllocation::allocate($s['line'], $s['second'], '0', 'Nothing', $finance))->toThrow(RuntimeException::class);
});

it('gives a cancelled, failed or returned order\'s units back to the project that owned them, and none to another', function () {
    $co = bootProduct($this);
    $s = sharedLot($co);
    ProjectStockAllocation::allocate($s['line'], $s['second'], '20', 'Share', $co->users['finance']);
    $left = fn ($p) => ProjectStockAllocation::remaining($s['line'], $p->id);

    placeOrder($co, $s['second'], $s['product'], '20', '10', 'R1');
    $o = ClientOrder::where('reference', 'R1')->first();
    orderStep($co, 'confirm', $o);
    expect($left($s['second']))->toBe('0.00');
    orderStep($co, 'cancel', $o);
    expect($left($s['second']))->toBe('20.00')->and($left($s['project']))->toBe('80.00');            // released exactly once, to its own project
    orderStep($co, 'cancel', $o)->assertSessionHas('error');
    expect($left($s['second']))->toBe('20.00');

    placeOrder($co, $s['second'], $s['product'], '20', '10', 'R2');
    $f = ClientOrder::where('reference', 'R2')->first();
    foreach (['confirm', 'dispatch', 'fail'] as $step) {
        orderStep($co, $step, $f);
    }
    expect($left($s['second']))->toBe('0.00');                                                       // awaiting inspection: not yet sellable by anyone
    $co->post('clients', 'product.items.inspect', ['usable' => '15', 'damaged' => '5'], $f->items()->first())->assertSessionHasNoErrors();
    expect($left($s['second']))->toBe('15.00')->and($left($s['project']))->toBe('80.00')->and(Inventory::balances($s['line'])['damaged'])->toBe('5.00');   // damaged units are consumed
    expect(Inventory::reconciles($s['line']))->toBeTrue();
});

it('limits users to their assigned projects and bound client, enforced on pages and actions, while management keeps everything', function () {
    $co = bootProduct($this);
    $s = sharedLot($co);
    $other = clientStock($co, '30', clientName: 'Rival client');
    placeOrder($co, $s['project'], $s['product'], '5', '10', 'MINE');
    placeOrder($co, $other['project'], $other['product'], '5', '10', 'RIVAL');
    $mine = ClientOrder::where('reference', 'MINE')->first();
    $rival = ClientOrder::where('reference', 'RIVAL')->first();

    $legacy = SellbeesCompany::user('legacy@audit.test', 'client_management');          // no assignment: unchanged access
    $staff = SellbeesCompany::user('staff@audit.test', 'client_management');
    $clientUser = SellbeesCompany::user('clientuser@audit.test', 'employee');
    $this->actingAs($co->users['super'])->post(route('users.access', $staff), ['project_ids' => [$s['project']->id]])->assertSessionHas('status');
    $this->actingAs($co->users['super'])->post(route('users.access', $clientUser), ['client_id' => $other['client']->id])->assertSessionHas('status');

    $staff->refresh();                                                                   // the administrator changed it through another request
    $clientUser->refresh();

    // A user explicitly granted all projects (as existing Client Management users are by the migration) sees both.
    $this->actingAs($legacy)->get(route('product.projects.show', $s['project']))->assertOk();
    $this->actingAs($legacy)->get(route('product.projects.show', $other['project']))->assertOk();

    // Staff with neither a grant nor an assignment get NO project access at all.
    $plain = SellbeesCompany::user('plain@audit.test', 'client_management');
    $plain->forceFill(['all_projects' => false])->save();
    $this->actingAs($plain)->get(route('product.projects.show', $s['project']))->assertForbidden();
    $this->actingAs($plain)->get(route('product.orders'))->assertOk()->assertDontSee('MINE')->assertDontSee('RIVAL');
    $this->actingAs($plain)->post(route('product.orders.confirm', $mine))->assertForbidden();

    // Sourcing, handover, loss, enrolment, ClientHub and search never reach a project-limited user's other projects.
    foreach (['sourcing.overview', 'sourcing.lots.index', 'sourcing.handovers', 'sourcing.losses', 'sourcing.enrolment', 'sourcing.supplier-returns', 'projects.index', 'clients.index'] as $page) {
        expect($this->actingAs($staff)->get(route($page))->status())->toBe(403, $page);
        expect($this->actingAs($plain)->get(route($page))->status())->toBe(403, $page);
        $this->actingAs($legacy)->get(route($page))->assertOk();                          // a user granted all projects keeps every legitimate screen
    }
    $this->actingAs($staff)->getJson(route('search', ['q' => $other['product']->name]))->assertOk()->assertDontSee($other['project']->name);

    // Assigned staff: own project only, everywhere.
    $this->actingAs($staff)->get(route('product.projects.show', $s['project']))->assertOk()->assertSee('MINE');
    $this->actingAs($staff)->get(route('product.projects.show', $other['project']))->assertForbidden();
    $this->actingAs($staff)->get(route('product.orders', ['project' => $other['project']->id]))->assertForbidden();
    $this->actingAs($staff)->get(route('product.orders'))->assertOk()->assertSee('MINE')->assertDontSee('RIVAL');
    $this->actingAs($staff)->get(route('product.inventory'))->assertOk()->assertDontSee($other['product']->name);
    $this->actingAs($staff)->get(route('product.inventory.show', $other['line']))->assertForbidden();
    $this->actingAs($staff)->get(route('stock.projects'))->assertOk()->assertDontSee($other['product']->name);
    foreach (['stock.overview', 'stock.movements', 'stock.reports', 'product.settlements', 'product.refunds'] as $page) {
        expect($this->actingAs($staff)->get(route($page))->status())->toBe(403, $page);                    // pages that mix projects
    }
    foreach (['confirm', 'pack', 'dispatch', 'cancel', 'collect', 'refunds'] as $action) {
        $route = $action === 'refunds' ? 'product.refunds.store' : 'product.orders.'.$action;
        $this->actingAs($staff)->post(route($route, $rival), ['amount' => '1'])->assertForbidden();
    }
    $this->actingAs($staff)->post(route('product.orders.store'), ['project_id' => $other['project']->id, 'reference' => 'SNEAK', 'ordered_on' => '2026-03-06', 'items' => [['product_id' => $other['product']->id, 'quantity' => '1', 'price' => '10']]])->assertForbidden();
    $this->actingAs($staff)->post(route('product.inventory.adjust', $other['line']), ['delta' => '-1', 'reason' => 'x'])->assertForbidden();
    $this->actingAs($staff)->post(route('product.orders.confirm', $mine))->assertSessionMissing('error');       // own project works
    expect(ClientOrder::where('reference', 'SNEAK')->exists())->toBeFalse()->and($rival->refresh()->delivery_status)->toBe('pending');

    // A user bound to a client sees that client's projects only (never another client's), whatever they are assigned.
    $this->actingAs($clientUser)->get(route('product.projects.show', $s['project']))->assertForbidden();
    expect($clientUser->refresh()->canAccessProject($other['project']))->toBeTrue()->and($clientUser->canAccessProject($s['project']))->toBeFalse();

    // Management roles are never narrowed, and cannot be limited by accident.
    foreach (['super', 'board', 'finance', 'auditor'] as $who) {
        $this->actingAs($co->users[$who])->get(route('product.projects.show', $other['project']))->assertOk();
    }
    $this->actingAs($co->users['super'])->post(route('users.access', $co->users['finance']), ['project_ids' => [$s['project']->id]])->assertSessionHas('error');
    $this->actingAs($co->users['super'])->post(route('users.access', $co->users['super']), ['project_ids' => []])->assertSessionHas('error');   // nobody edits their own access
    $this->actingAs($co->users['finance'])->post(route('users.access', $staff), ['project_ids' => []])->assertForbidden();                       // needs users.manage

    // Allocation workflow respects project access too.
    $this->actingAs($staff)->post(route('stock.allocations.store'), ['line_id' => $other['line']->id, 'project_id' => $other['project']->id, 'quantity' => '1', 'reason' => 'x'])->assertForbidden();
});

// ───────────── the owner's mug example: delivery charge once, commission base, client profit vs settlement ─────────────

/** Place, confirm, dispatch (recording the delivery charge once) and optionally deliver one order. */
function mugOrder(SellbeesCompany $co, array $s, string $ref, string $qty, string $charge, string $bearer = 'client', string $status = 'courier_deducted', bool $deliver = true, string $price = '10'): ClientOrder
{
    placeOrder($co, $s['project'], $s['product'], $qty, $price, $ref)->assertSessionMissing('error');
    $o = ClientOrder::where('reference', $ref)->firstOrFail();
    orderStep($co, 'confirm', $o)->assertSessionMissing('error');
    orderStep($co, 'dispatch', $o, ['delivery_charge' => $charge, 'delivery_charge_bearer' => $bearer, 'delivery_charge_status' => $status])->assertSessionMissing('error');
    if ($deliver) {
        orderStep($co, 'deliver', $o)->assertSessionMissing('error');
    }

    return $o->refresh();
}

function mugClient(SellbeesCompany $co): array
{
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1'])->assertSessionHasNoErrors();

    return clientStock($co, '10', price: '3', profit: '2', deliveryCost: '0');   // supplier BDT 3, client BDT 5, 10 mugs
}

it('works the owner\'s mug example to the paisa, with each figure counted once', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $e = ProjectEconomics::for($s['project']);
    expect($e['investment'])->toBe(5_000)->and($e['sourcing_margin'])->toBe(2_000);                  // client pays 10 x 5 = 50.00; margin 50 - 30 = 20.00

    // One mug: 10.00 sold, 1.00 delivery, 9.00 commission base, 0.09 commission, 8.91 to the client, 3.91 profit.
    $one = mugOrder($co, $s, 'MUG-1', '1', '1');
    expect($one->net_value)->toBe(900)->and($one->commission)->toBe(9)->and($one->rate_bps)->toBe(100);
    $e = ProjectEconomics::for($s['project']);
    expect([$e['gross_sales'], $e['delivery_charges'], $e['net_sales'], $e['commission'], $e['entitlement'], $e['cost_of_sold'], $e['client_profit']])->toBe([1_000, 100, 900, 9, 891, 500, 391]);

    // All ten: 100.00 gross, 10.00 delivery, 90.00 base, 0.90 commission, 89.10 to the client, 50.00 recovered, 39.10 profit.
    mugOrder($co, $s, 'MUG-9', '9', '9');
    $e = ProjectEconomics::for($s['project']);
    expect($e['gross_sales'])->toBe(10_000)->and($e['delivery_charges'])->toBe(1_000)->and($e['net_sales'])->toBe(9_000)->and($e['commission'])->toBe(90)
        ->and($e['entitlement'])->toBe(8_910)->and($e['investment_recovered'])->toBe(5_000)->and($e['cost_of_sold'])->toBe(5_000)->and($e['client_profit'])->toBe(3_910)
        ->and($e['investment_unrecovered'])->toBe(0)->and($e['sourcing_margin'])->toBe(2_000)->and($e['units_sold'])->toBe('10.00')
        ->and($e['sourcing_margin'] + $e['commission'])->toBe(2_090)                                  // 20.00 + 0.90 = 20.90, two separate figures
        ->and($e['notes'])->toBe([]);
    // Every order reconciles to the project, and the project to the client.
    expect(collect($e['rows'])->sum('net'))->toBe($e['net_sales'])->and(collect($e['rows'])->sum('commission'))->toBe($e['commission'])->and(collect($e['rows'])->sum('entitlement'))->toBe($e['entitlement'])
        ->and($e['client']['entitlement'])->toBe(8_910)->and($e['client']['settled'])->toBe(0)->and($e['client']['outstanding'])->toBe(8_910);
    $b = Inventory::balances($s['line']);
    expect($b['delivered'])->toBe('10.00')->and($b['available'])->toBe('0.00')->and(Inventory::reconciles($s['line']))->toBeTrue();

    // The project page shows it, and server-calculated profit is not typed in by staff.
    $page = $co->as('clients')->get(route('product.projects.show', $s['project']))->assertOk();
    $page->assertSee('Client product profit')->assertSee('39.10')->assertSee('89.10')->assertSee('Sellbees earnings')->assertSee('0.90');
});

it('records the delivery charge once, never deducts it again at delivery, and never collects what the courier kept', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'COD-1', '8', '8', 'client', 'courier_deducted');      // 80.00 sold, courier keeps 8.00 before remitting 72.00
    expect($o->delivery_charge)->toBe(800)->and($o->delivery_charge_recorded_at)->not->toBeNull()->and($o->net_value)->toBe(7_200)->and($o->commission)->toBe(72);
    orderStep($co, 'dispatch', $o, ['delivery_charge' => '99'])->assertSessionHas('error');                   // already dispatched: no second recording
    expect($o->refresh()->delivery_charge)->toBe(800);

    $collect = fn (string $amount) => $co->post('clients', 'product.orders.collect', ['amount' => $amount, 'collected_on' => '2026-02-11', 'idempotency_key' => (string) Str::uuid()], $o);
    $collect('72.01')->assertSessionHas('error');                                                             // the courier's 8.00 is not owed to Sellbees
    $collect('72')->assertSessionMissing('error');
    $state = OrderFulfilment::collectionState($o->refresh()->load('items'));
    expect($state['outstanding'])->toBe(0)->and($state['state'])->toBe('Collected');

    $e = ProjectEconomics::for($s['project']);
    expect($e['entitlement'])->toBe(7_128)                                                                  // 72.00 - 0.72, the charge deducted once
        ->and($e['client']['cash_available'])->toBe(7_128)->and($e['client']['pending_remittance'])->toBe(0);    // held 72.00 - commission 0.72 netted

    // Charges borne by Sellbees, or paid by the customer on top, are never deducted from the sale or the commission base.
    $sb = mugOrder($co, $s, 'SB-1', '2', '5', 'sellbees', price: '10');
    expect($sb->net_value)->toBe(2_000)->and($sb->commission)->toBe(20);
});

it('shares the delivery charge over what was delivered and keeps commission exact through partial delivery and returns', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'PD-1', '10', '10', deliver: false);
    $item = $o->items()->first();
    orderStep($co, 'deliver', $o, ['delivered' => [$item->id => '6']])->assertSessionMissing('error');
    $o->refresh()->load('items');
    expect($o->eligibleDeliveryCharge($o->deliveredValue()))->toBe(600)->and($o->net_value)->toBe(5_400)->and($o->commission)->toBe(54);   // 60.00 - 6.00 = 54.00 -> 0.54

    orderStep($co, 'return', $o, ['returned' => [$item->id => '2']])->assertSessionMissing('error');
    $o->refresh();
    expect($o->net_value)->toBe(3_600)->and($o->commission)->toBe(36);                                    // 40.00 - 4.00 = 36.00 -> 0.36, reversed once
    orderStep($co, 'return', $o, ['returned' => [$item->id => '5']])->assertSessionHas('error');         // more than was delivered
    expect($o->refresh()->commission)->toBe(36);
    $e = ProjectEconomics::for($s['project']);
    expect([$e['gross_sales'], $e['delivery_charges'], $e['net_sales'], $e['commission'], $e['units_sold']])->toBe([4_000, 400, 3_600, 36, '4.00']);
    expect($o->items()->first()->delivered_quantity)->toEqual(6);
});

it('earns no commission on a failed delivery, records its courier charge once, and charges the client for it only when client-borne', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'FD-1', '4', '4', deliver: false);
    orderStep($co, 'fail', $o)->assertSessionMissing('error');
    $o->refresh();
    expect($o->delivery_status)->toBe('failed')->and($o->commission)->toBe(0)->and($o->billed_invoice_id)->toBeNull();

    $charges = fn (array $d) => $co->post('clients', 'product.orders.courier-charges', $d, $o);
    $charges(['return_charge' => '3'])->assertSessionHas('error');                                       // no returned units on this order
    $charges(['failed_delivery_charge' => '2.50'])->assertSessionMissing('error');
    $charges(['failed_delivery_charge' => '9'])->assertSessionHas('error');                              // recorded once, never overwritten
    expect($o->refresh()->failed_delivery_charge)->toBe(250);

    $e = ProjectEconomics::for($s['project']);
    expect($e['net_sales'])->toBe(0)->and($e['commission'])->toBe(0)->and($e['unsuccessful_charges'])->toBe(250)->and($e['units_sold'])->toBe('0.00')
        ->and($e['client_profit'])->toBe(-250)->and($e['entitlement'])->toBe(0);       // a cost to the client's profit, but the courier kept nothing from any remittance
});

it('rounds commission half-up in whole paisa and keeps a zero rate valid', function () {
    expect(OrderCommission::commission(901, 100))->toBe(9)->and(OrderCommission::commission(950, 100))->toBe(10)->and(OrderCommission::commission(949, 100))->toBe(9)
        ->and(OrderCommission::commission(900, 0))->toBe(0)->and(OrderCommission::commission(9_000, 100))->toBe(90);
    $o = new ClientOrder(['delivery_charge' => 100, 'delivery_charge_bearer' => 'client', 'delivery_charge_recorded_at' => now()]);
    $o->setRelation('items', collect([new ClientOrderItem(['quantity' => '3', 'unit_price' => 333, 'unit_discount' => 0])]));   // 9.99 ordered, 1.00 charge
    expect($o->orderedValue())->toBe(999)->and($o->eligibleDeliveryCharge(333))->toBe(33)->and($o->eligibleDeliveryCharge(666))->toBe(67)->and($o->eligibleDeliveryCharge(999))->toBe(100);
    // Shares of one charge never add up to more than the charge: 33 + 67 = 100.
});

// ───────────── delivery charge routes: courier-deducted, Sellbees-paid, client-paid, Sellbees-borne ─────────────

function settleClient(SellbeesCompany $co, $client, ?string $pay, ?string $key = null, ?int $account = null)
{
    return $co->post('finance', 'product.settlements.store', ['pay' => $pay, 'paid_on' => '2026-02-12', 'reference' => 'TRX-'.Str::random(4), 'idempotency_key' => $key ?? (string) Str::uuid(), 'financial_account_id' => $account], $client);
}

function collectAll(SellbeesCompany $co, ClientOrder $o, string $amount)
{
    return $co->post('clients', 'product.orders.collect', ['amount' => $amount, 'collected_on' => '2026-02-11', 'idempotency_key' => (string) Str::uuid()], $o);
}

it('A: a charge the courier kept is deducted from the courier remittance once and never again from the client settlement', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'A-1', '10', '10', 'client', 'courier_deducted');
    collectAll($co, $o, '90')->assertSessionMissing('error');                               // the courier remits 90.00 of the 100.00
    settleClient($co, $s['client'], '89.10')->assertSessionMissing('error');                  // held 90.00 - 0.90 commission netted = 89.10
    $e = ProjectEconomics::for($s['project']);
    expect($e['entitlement'])->toBe(8_910)->and($e['client']['settled'])->toBe(8_910)->and($e['client']['outstanding'])->toBe(0)->and($e['client_profit'])->toBe(3_910)
        ->and(ClientSettlementService::fundsHeld($s['client']))->toBe(0);
});

it('B: a charge Sellbees paid is kept out of the client payout once, creates no invoice, payment or income, and is never deducted twice', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'B-1', '10', '10', 'client', 'paid_by_sellbees');
    collectAll($co, $o, '100')->assertSessionMissing('error');                              // the customer paid the full 100.00 to Sellbees
    $st = ClientSettlementService::statement($s['client']);
    expect([$st['funds_held'], $st['commission_due'], $st['courier_advance_withheld'], $st['netting'], $st['payable']])->toBe([10_000, 90, 1_000, 90, 8_910]);   // 100.00 - 0.90 - 10.00

    settleClient($co, $s['client'], '89.11')->assertSessionHas('error');                      // never more than the entitlement
    $key = (string) Str::uuid();
    $invoices = Invoice::count();
    settleClient($co, $s['client'], '89.10', $key)->assertSessionMissing('error');
    settleClient($co, $s['client'], '89.10', $key);                                           // the same submission twice
    settleClient($co, $s['client'], '0.01')->assertSessionHas('error');                       // a second settlement cannot pay the advance out

    // Exactly the collection and one settlement; commission is the only invoice netted; the 10.00 is NOT revenue.
    expect(JournalEntry::orderBy('id')->pluck('source_type')->all())->toBe(['order_collection', 'client_settlement'])->and(ClientSettlement::count())->toBe(1)
        ->and(Payment::count())->toBe(1)->and(Invoice::count())->toBe($invoices)->and(Expense::count())->toBe(0)
        ->and(RevenueRecognition::figures('2026-02')['collected'])->toBe(90);                  // only the commission reached ClientHub income
    $settlement = ClientSettlement::firstOrFail();
    expect([$settlement->commission_netted, $settlement->paid])->toBe([90, 8_910])
        ->and(ClientSettlementService::fundsHeld($s['client']))->toBe(1_000)                  // the advance stays visible as client funds until Finance books it
        ->and(ClientSettlementService::statement($s['client'])['payable'])->toBe(0);
    $e = ProjectEconomics::for($s['project']);
    expect($e['entitlement'])->toBe(8_910)->and($e['client']['outstanding'])->toBe(0)->and($e['client_profit'])->toBe(3_910)   // 90.00 - 0.90 - 50.00: the charge deducted once
        ->and($e['courier_advance_withheld'])->toBe(1_000)->and($e['notes'])->toBe([]);
});

it('B: a partial delivery withholds only its share; a return lowers it and the incurred charge stays on record', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'B-2', '10', '10', 'client', 'paid_by_sellbees', deliver: false);
    orderStep($co, 'deliver', $o, ['delivered' => [$o->items()->first()->id => '6']])->assertSessionMissing('error');
    expect($o->refresh()->recoverableCharge())->toBe(600)->and($o->delivery_charge)->toBe(1_000)->and($o->unrecoveredCourierCost())->toBe(400);   // 4.00 is Sellbees' own cost
    orderStep($co, 'return', $o, ['returned' => [$o->items()->first()->id => '2']])->assertSessionMissing('error');
    $o->refresh();
    expect($o->recoverableCharge())->toBe(400)->and($o->delivery_charge)->toBe(1_000)                       // the actual charge incurred is preserved
        ->and($o->unrecoveredCourierCost())->toBe(600)->and(ClientSettlementService::courierAdvanceWithheld($s['client']))->toBe(400);
});

it('refunds nothing automatically; a courier refund is recorded once, by an authorised person, capped, and never changes the client figures', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'R-1', '10', '10', 'client', 'paid_by_sellbees', deliver: false);
    orderStep($co, 'deliver', $o, ['delivered' => [$o->items()->first()->id => '6']])->assertSessionMissing('error');
    $before = ProjectEconomics::for($s['project']);
    $refund = fn (string $amount, string $ref = 'CR-1') => $co->post('finance', 'product.orders.courier-refund', ['amount' => $amount, 'reference' => $ref], $o);

    expect($o->refresh()->courier_refund)->toBe(0)->and($before['sellbees_courier_cost'])->toBe(400)->and($before['courier_refunded'])->toBe(0);   // nothing refunded by itself
    $co->post('clients', 'product.orders.courier-refund', ['amount' => '1', 'reference' => 'X'], $o)->assertForbidden();   // Client Management cannot approve refunds
    $refund('4.01')->assertSessionHas('error');                                              // more than the unrecovered part
    $refund('4')->assertSessionMissing('error');
    $refund('0.50', 'CR-2')->assertSessionHas('error');                                      // once only: a second courier refund is refused
    $o->refresh();
    $after = ProjectEconomics::for($s['project']);
    expect([$o->courier_refund, $o->courier_refund_reference, $o->courier_refund_by])->toBe([400, 'CR-1', $co->users['finance']->id])->and($o->delivery_charge)->toBe(1_000)   // the incurred charge is untouched
        ->and($after['sellbees_courier_cost'])->toBe(0)->and($after['courier_refunded'])->toBe(400)
        ->and([$after['entitlement'], $after['client_profit'], $after['commission'], $after['net_sales']])->toBe([$before['entitlement'], $before['client_profit'], $before['commission'], $before['net_sales']])   // client figures unchanged
        ->and(AuditLog::where('action', 'order.courier_refund')->count())->toBe(1)->and(JournalEntry::count())->toBe(0);                                                  // auditable, posts nothing
    // A later return keeps the recorded refund and still never refunds anything by itself.
    orderStep($co, 'return', $o, ['returned' => [$o->items()->first()->id => '2']])->assertSessionMissing('error');
    expect($o->refresh()->courier_refund)->toBe(400)->and($o->unrecoveredCourierCost())->toBe(200);   // 10.00 - 4.00 refunded - 4.00 eligible share of 4 units
    $co->post('finance', 'product.orders.courier-refund', ['amount' => '1', 'reference' => 'CR-3'], $o)->assertSessionHas('error');
});

it('puts no deduction on a historical or unrecorded order because of default delivery-charge statuses', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'OLD-1', '10', '10', 'client', 'paid_by_sellbees');
    // Make it look like an order from before the rule: a typed-in charge with the default terms and no recorded-at moment, commission as the old rule stored it.
    $o->forceFill(['delivery_charge_recorded_at' => null, 'delivery_charge_status' => 'payable', 'delivery_charge_bearer' => 'client', 'net_value' => 10_000, 'commission' => 100])->save();
    $o->refresh()->load('items');
    expect($o->eligibleDeliveryCharge($o->deliveredValue()))->toBe(0)->and($o->commissionBase())->toBe(10_000)->and($o->collectibleValue())->toBe(10_000)
        ->and($o->settlementDeduction(0))->toBe(0)->and($o->recoverableCharge())->toBe(0)->and($o->unrecoveredCourierCost())->toBe(0);
    collectAll($co, $o, '100')->assertSessionMissing('error');                              // the whole sale is still collectible, nothing deducted
    $e = ProjectEconomics::for($s['project']);
    expect($e['delivery_charges'])->toBe(0)->and($e['entitlement'])->toBe(9_900)->and($e['net_sales'])->toBe(10_000)->and($e['notes'])->toBe([])
        ->and(ClientSettlementService::courierAdvanceWithheld($s['client']))->toBe(0)->and(ClientSettlementService::statement($s['client'])['payable'])->toBe(10_000 - ClientSettlementService::statement($s['client'])['commission_due']);

    // An untouched default row (no charge at all) is likewise inert.
    $blank = new ClientOrder(['delivery_charge' => 0, 'delivery_charge_bearer' => 'client', 'delivery_charge_status' => 'payable']);
    $blank->setRelation('items', collect());
    expect($blank->eligibleDeliveryCharge(500))->toBe(0)->and($blank->settlementDeduction(0))->toBe(0);
});
it('C: a charge the client paid the courier directly is not deducted from Sellbees cash or the client entitlement, only from profit', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'C-1', '10', '10', 'client', 'paid_by_client');
    expect($o->net_value)->toBe(9_000)->and($o->commission)->toBe(90);                          // the commission base is still sales less the client-borne charge
    collectAll($co, $o, '100')->assertSessionMissing('error');                               // the customer paid Sellbees the whole 100.00
    $e = ProjectEconomics::for($s['project']);
    expect($e['entitlement'])->toBe(9_910)->and($e['client_profit'])->toBe(3_910)->and($o->recoverableCharge())->toBe(0);   // 100.00 - 0.90 owed; profit 90.00 - 0.90 - 50.00
    settleClient($co, $s['client'], '99.10')->assertSessionMissing('error');                 // all of it, no second deduction
    expect(ClientSettlementService::fundsHeld($s['client']))->toBe(0)->and(ProjectEconomics::for($s['project'])['client']['outstanding'])->toBe(0);
});

it('D: a charge Sellbees bears reduces neither the client profit nor the client entitlement nor the commission base', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'D-1', '10', '10', 'sellbees', 'paid_by_sellbees');
    expect($o->net_value)->toBe(10_000)->and($o->commission)->toBe(100);
    $e = ProjectEconomics::for($s['project']);
    expect($e['delivery_charges'])->toBe(0)->and($e['entitlement'])->toBe(9_900)->and($e['client_profit'])->toBe(4_900)->and($o->recoverableCharge())->toBe(0);
    collectAll($co, $o, '100')->assertSessionMissing('error');
    settleClient($co, $s['client'], '99')->assertSessionMissing('error');
});

it('uses only the approved, billed client price for cost: unapproved or mismatched pricing shows pending verification, never a guessed profit', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    mugOrder($co, $s, 'P-1', '2', '2');
    expect(ProjectEconomics::for($s['project'])['client_profit'])->toBe(1_782 - 1_000);        // net 18.00 - commission 0.18 - cost 2 x 5.00
    $e = ProjectEconomics::for($s['project']);
    expect($e['cost_of_sold'])->toBe(1_000)->and($e['cost_pending'])->toBeFalse();

    $s['line']->update(['approved_price' => null]);                                           // history without an approval snapshot, a draft sell_price still present
    expect($s['line']->refresh()->sell_price)->not->toBeNull();
    $e = ProjectEconomics::for($s['project']);
    expect($e['cost_pending'])->toBeTrue()->and($e['client_profit'])->toBeNull()->and($e['cost_of_sold'])->toBeNull()->and($e['investment_recovered'])->toBeNull()
        ->and($e['entitlement'])->toBe(1_782)->and($e['notes'][0])->toContain('approved client price');                         // what is owed does not depend on cost
    $co->as('clients')->get(route('product.projects.show', $s['project']))->assertOk()->assertSee('Cost pending verification');

    $s['line']->update(['approved_price' => 500]);                                            // approved again, but the billed allocation was at another price
    SourcingAllocation::where('sourcing_lot_line_id', $s['line']->id)->update(['unit_price' => 499]);
    expect(ProjectEconomics::for($s['project'])['cost_pending'])->toBeTrue();
});

it('keeps project entitlement separate from client-wide settlement and never allocates a settlement to a project', function () {
    $co = bootProduct($this);
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1']);
    $one = clientStock($co, '10', clientName: 'Twin client', price: '3', profit: '2', deliveryCost: '0');
    $two = clientStock($co, '10', clientName: 'Twin client', price: '3', profit: '2', deliveryCost: '0');
    expect($two['client']->id)->toBe($one['client']->id)->and($two['project']->id)->not->toBe($one['project']->id);
    $a = mugOrder($co, $one, 'T-1', '10', '10', 'client', 'courier_deducted');
    mugOrder($co, $two, 'T-2', '4', '4', 'client', 'courier_deducted');
    collectAll($co, $a, '90')->assertSessionMissing('error');
    settleClient($co, $one['client'], '50')->assertSessionMissing('error');                   // a client-wide payment, tied to no project

    $e1 = ProjectEconomics::for($one['project']);
    $e2 = ProjectEconomics::for($two['project']);
    expect($e1['entitlement'])->toBe(8_910)->and($e2['entitlement'])->toBe(3_564)             // 40.00 - 4.00 - 0.36
        ->and($e1['client']['entitlement'])->toBe(12_474)->and($e2['client']['entitlement'])->toBe(12_474)   // the same client-wide figure on both pages
        ->and($e1['client']['settled'])->toBe(5_000)->and($e2['client']['settled'])->toBe(5_000)->and($e1['client']['outstanding'])->toBe(7_474)
        ->and($e1['client_profit'])->toBe(3_910)->and($e2['client_profit'])->toBe(1_564);
    $page = $co->as('clients')->get(route('product.projects.show', $one['project']))->assertOk();
    $page->assertSee('Client-wide settlement paid (not allocated to any project)')->assertSee('Project settlement entitlement');
});

it('reading the project economics and settling never changes a historical financial record', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'H-1', '10', '10', 'client', 'paid_by_sellbees');
    collectAll($co, $o, '100');
    $before = [JournalEntry::count(), JournalLine::count(), Payment::count(), Invoice::count(), Expense::count(), (int) JournalEntry::sum('id'), (int) JournalLine::sum('debit')];
    ProjectEconomics::for($s['project']);
    $co->as('clients')->get(route('product.projects.show', $s['project']))->assertOk();
    expect([JournalEntry::count(), JournalLine::count(), Payment::count(), Invoice::count(), Expense::count(), (int) JournalEntry::sum('id'), (int) JournalLine::sum('debit')])->toBe($before);   // reading posts nothing
    $ids = JournalEntry::orderBy('id')->pluck('id')->all();
    settleClient($co, $s['client'], '89.10')->assertSessionMissing('error');
    expect(array_slice(JournalEntry::orderBy('id')->pluck('id')->all(), 0, count($ids)))->toBe($ids)->and(JournalEntry::count())->toBe(count($ids) + 1);   // the earlier entry is untouched; one settlement entry added
});

// ───────────── courier advance: clearing the withheld client funds against the receivable ─────────────

/** Sellbees pays the courier (an ordinary expense), Finance proposes it as a client advance and a different person verifies it. */
function courierAdvanceFor(SellbeesCompany $co, ClientOrder $o, string $paid = '10'): CourierAdvance
{
    $expense = $co->expense('Courier', 'Courier charge for order '.$o->reference, $paid, '2026-02-10');
    $co->post('finance', 'product.orders.courier-advance', ['expense_id' => $expense->id], $o)->assertSessionMissing('error');
    $advance = CourierAdvance::where('client_order_id', $o->id)->firstOrFail();
    $co->post('board', 'product.courier-advances.decide', ['decision' => 'approve'], $advance)->assertSessionMissing('error');

    return $advance->refresh();
}

function courierBooks(SellbeesCompany $co): void
{
    FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $co->invest('A', '100000', '2026-02-02');
}

function journalOf(string $type): array
{
    return JournalEntry::where('source_type', $type)->orderBy('id')->get()->map(fn ($e) => $e->lines()->orderBy('id')->get()->map(fn ($l) => [$l->account, (int) $l->debit, (int) $l->credit])->all())->all();
}

it('clears the withheld client money against a courier advance: zero client liability, zero receivable, commission the only income', function () {
    $co = bootProduct($this);
    courierBooks($co);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'CA-1', '10', '10', 'client', 'paid_by_sellbees');
    courierAdvanceFor($co, $o);                                                                    // the 10.00 paid to the courier is reclassified from expense to a receivable
    collectAll($co, $o, '100')->assertSessionMissing('error');                                       // the customer paid 100.00
    $st = ClientSettlementService::statement($s['client']);
    expect([$st['funds_held'], $st['commission_due'], $st['courier_advance_withheld'], $st['payable']])->toBe([10_000, 90, 1_000, 8_910]);

    settleClient($co, $s['client'], '89.10')->assertSessionMissing('error');

    // The balanced entries, exactly once each.
    expect(journalOf('expense'))->toBe([[['expense', 1_000, 0], ['cash', 0, 1_000]]])                                  // 1. the courier payment, an ordinary expense
        ->and(journalOf('courier_advance'))->toBe([[['assets.client_courier_advance', 1_000, 0], ['expense', 0, 1_000]]])  // 2. reclassified, not paid or counted twice
        ->and(journalOf('order_collection'))->toBe([[['cash', 10_000, 0], ['client.funds', 0, 10_000]]])                  // 3. the customer's money, held for the client
        ->and(journalOf('client_settlement'))->toBe([[['client.funds', 10_000, 0], ['cash', 0, 90], ['cash', 0, 8_910], ['assets.client_courier_advance', 0, 1_000]]])   // 4. payout, commission netting, advance cleared
        ->and(journalOf('courier_advance_release'))->toBe([]);
    foreach (JournalEntry::with('lines')->get() as $e) {
        expect((int) $e->lines->sum('debit'))->toBe((int) $e->lines->sum('credit'));                    // every entry balances
    }
    expect(Ledger::creditBalance('client.funds'))->toBe(0)->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(0)->and(Ledger::debitBalance('expense'))->toBe(0)
        ->and(CourierAdvanceService::openReceivable())->toBe(0)->and(ClientSettlementService::fundsHeld($s['client']))->toBe(0)
        ->and(RevenueRecognition::figures('2026-02')['collected'])->toBe(90)->and(Payment::count())->toBe(1);       // only the 0.90 commission reached income; no courier revenue
    $advance = CourierAdvance::firstOrFail();
    expect([$advance->amount, $advance->applied, $advance->released, $advance->open()])->toBe([1_000, 1_000, 0, 0]);

    // The Mug figures are unchanged.
    $e = ProjectEconomics::for($s['project']);
    expect([$e['entitlement'], $e['client_profit'], $e['sourcing_margin'], $e['commission'], $e['courier_advance_withheld']])->toBe([8_910, 3_910, 2_000, 90, 0]);

    // Repeating the recovery, the settlement or the verification changes nothing.
    settleClient($co, $s['client'], '0.01')->assertSessionHas('error');
    $co->post('board', 'product.courier-advances.decide', ['decision' => 'approve'], $advance)->assertSessionHas('error');
    $co->post('finance', 'product.orders.courier-advance', ['expense_id' => Expense::firstOrFail()->id], $o);
    expect(CourierAdvance::count())->toBe(1)->and(ClientSettlement::count())->toBe(1)->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(0);
});

it('requires a second person for a courier advance and links only a genuine paid expense, once', function () {
    $co = bootProduct($this);
    courierBooks($co);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'CA-2', '4', '4', 'client', 'paid_by_sellbees');
    $expense = $co->expense('Courier', 'Courier charge', '10', '2026-02-10');
    $co->post('finance', 'product.orders.courier-advance', ['expense_id' => $expense->id], $o)->assertSessionMissing('error');
    $advance = CourierAdvance::firstOrFail();
    $co->post('finance', 'product.courier-advances.decide', ['decision' => 'approve'], $advance)->assertForbidden();     // Finance cannot verify (loan.verify is Board's)
    expect(journalOf('courier_advance'))->toBe([]);                                                                      // nothing is posted until a different person verifies
    $co->post('finance', 'product.orders.courier-advance', ['expense_id' => $expense->id], $o);
    expect(CourierAdvance::count())->toBe(1);                                                                // proposing again returns the same proposal

    $small = $co->expense('Courier', 'Too small', '4', '2026-02-10');
    $o2 = mugOrder($co, $s, 'CA-3', '1', '1', 'client', 'paid_by_sellbees');
    expect(fn () => CourierAdvanceService::propose($o2, $small, $co->users['finance']))->not->toThrow(RuntimeException::class);   // 1.00 fits in a 4.00 expense
    $o3 = mugOrder($co, $s, 'CA-4', '1', '4', 'client', 'paid_by_sellbees');
    expect(fn () => CourierAdvanceService::propose($o3, $small, $co->users['finance']))->toThrow(RuntimeException::class, 'not large enough');   // 1.00 + 1.00 would be fine, but this one is 4.00 on its own
});

it('returns the excess to expense when later returns shrink what the client owes, leaving no receivable', function () {
    $co = bootProduct($this);
    courierBooks($co);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'CA-5', '10', '10', 'client', 'paid_by_sellbees');
    courierAdvanceFor($co, $o);                                                                    // 10.00 reclassified
    orderStep($co, 'return', $o, ['returned' => [$o->items()->first()->id => '4']])->assertSessionMissing('error');   // 4 units back before any money was collected
    expect($o->refresh()->recoverableCharge())->toBe(600);
    collectAll($co, $o, '60')->assertSessionMissing('error');
    settleClient($co, $s['client'], null)->assertSessionMissing('error');

    expect(journalOf('courier_advance_release'))->toBe([[['expense', 400, 0], ['assets.client_courier_advance', 0, 400]]])   // the 4.00 the client no longer owes is Sellbees' own cost again
        ->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(0)->and(Ledger::creditBalance('client.funds'))->toBe(0)->and(Ledger::debitBalance('expense'))->toBe(400)
        ->and(CourierAdvanceService::openReceivable())->toBe(0);
    $a = CourierAdvance::firstOrFail();
    expect([$a->applied, $a->released])->toBe([600, 400])->and($o->refresh()->unrecoveredCourierCost())->toBe(400);
});

it('never takes client funds below zero: a short collection clears part of the advance and the rest is cleared at the next settlement', function () {
    $co = bootProduct($this);
    courierBooks($co);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'CA-6', '10', '10', 'client', 'paid_by_sellbees');
    $a = courierAdvanceFor($co, $o);
    collectAll($co, $o, '5')->assertSessionMissing('error');                                         // only 5.00 of the 100.00 has arrived
    settleClient($co, $s['client'], null)->assertSessionMissing('error');                           // commission 0.90 first, then 4.10 against the advance, nothing to the client
    expect($a->refresh()->applied)->toBe(410)->and($a->open())->toBe(590)->and(Ledger::creditBalance('client.funds'))->toBe(0)->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(590);
    collectAll($co, $o, '95')->assertSessionMissing('error');
    settleClient($co, $s['client'], null)->assertSessionMissing('error');
    expect($a->refresh()->applied)->toBe(1_000)->and($a->open())->toBe(0)->and(Ledger::creditBalance('client.funds'))->toBe(0)->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(0)
        ->and(ClientSettlement::sum('paid'))->toBe(8_910);                                           // the client received exactly the entitlement
});

it('rolls a failed settlement back completely, including the courier clearing', function () {
    $co = bootProduct($this);
    courierBooks($co);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'CA-7', '10', '10', 'client', 'paid_by_sellbees');
    $a = courierAdvanceFor($co, $o);
    collectAll($co, $o, '100');
    // Make the settlement's journal collide with an existing entry so it fails AFTER the advance and payments were updated.
    JournalEntry::create(['entry_date' => '2026-02-12', 'memo' => 'collision', 'source_type' => 'client_settlement', 'source_id' => (int) (ClientSettlement::max('id') ?? 0) + 1, 'posted_by' => 1]);
    $payments = Payment::count();
    settleClient($co, $s['client'], '89.10')->assertSessionHas('error');
    expect($a->refresh()->applied)->toBe(0)->and($a->released)->toBe(0)->and(ClientSettlement::count())->toBe(0)->and(Payment::count())->toBe($payments)
        ->and(ClientSettlementService::fundsHeld($s['client']))->toBe(10_000)->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(1_000);
});

it('keeps the contractual payer of failed-delivery and return charges and never refunds a successful delivery fee by itself', function () {
    $co = bootProduct($this);
    $s = mugClient($co);
    $mine = mugOrder($co, $s, 'PAY-1', '4', '4', 'sellbees', 'paid_by_sellbees', deliver: false);
    orderStep($co, 'fail', $mine)->assertSessionMissing('error');
    $co->post('clients', 'product.orders.courier-charges', ['failed_delivery_charge' => '3'], $mine)->assertSessionMissing('error');
    expect($mine->refresh()->failed_delivery_charge)->toBe(300)->and($mine->unsuccessfulCharges())->toBe(0)->and($mine->settlementDeduction(0))->toBe(0)->and($mine->recoverableCharge())->toBe(0);   // Sellbees' contract: not the client's

    $client = mugOrder($co, $s, 'PAY-2', '4', '4', 'client', 'paid_by_sellbees', deliver: false);
    orderStep($co, 'fail', $client)->assertSessionMissing('error');
    $co->post('clients', 'product.orders.courier-charges', ['failed_delivery_charge' => '3'], $client)->assertSessionMissing('error');
    expect($client->refresh()->unsuccessfulCharges())->toBe(300)->and($client->delivery_charge)->toBe(400)->and($client->courier_refund)->toBe(0)   // the charge incurred stays as it was and nothing was refunded
        ->and($client->recoverableCharge())->toBe(300);                                                  // only what the client bears, and only the failed-delivery charge it incurred
});

// ───────────── account-level cash proof of the courier-advance scenario ─────────────

function ledgerCash(): int
{
    return (int) JournalLine::where('account', 'cash')->sum('debit') - (int) JournalLine::where('account', 'cash')->sum('credit');
}

/** The money that really crossed the company's boundary, read from the records and not from the netting lines. */
function externalCash(): int
{
    return (int) OrderCollection::sum('amount') - (int) Expense::where('category', 'Courier')->sum('amount') - (int) ClientSettlement::sum('paid');
}

it('proves the 0.90 commission is an internal reclassification, not a cash outflow: legacy month, income booked at month-end close', function () {
    $co = bootProduct($this);
    courierBooks($co);
    $s = mugClient($co);
    $base = ledgerCash();                                                                          // the capital already in the books
    $o = mugOrder($co, $s, 'CP-1', '10', '10', 'client', 'paid_by_sellbees');
    courierAdvanceFor($co, $o);
    collectAll($co, $o, '100');
    settleClient($co, $s['client'], '89.10')->assertSessionMissing('error');

    // Before the month closes the ledger holds the commission as money already moved out of client funds, income not yet booked.
    expect(externalCash())->toBe(90)->and(ClientSettlement::sum('paid'))->toBe(8_910)                  // +100.00 collected, -10.00 courier, -89.10 client = +0.90 really
        ->and(ledgerCash() - $base)->toBe(0)                                                           // 10,000 - 1,000 - 90 netting - 8,910: the 0.90 is not yet on the cash line
        ->and(Ledger::creditBalance('client.funds'))->toBe(0)->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(0);
    // Month-end close books the matching debit and the income, once.
    Carbon::setTestNow('2026-03-02 10:00:00');
    PeriodProcessor::close('2026-02', $co->users['finance']);
    expect(ledgerCash() - $base)->toBe(90)->and(ledgerCash() - $base)->toBe(externalCash())                              // company cash is +0.90, exactly the real movement
        ->and(Ledger::creditBalance('income'))->toBe(90)                                               // commission recognised once; the courier reimbursement is not income
        ->and(journalOf('period'))->toBe([[['cash', 90, 0], ['income', 0, 90]]]);
    foreach (JournalEntry::with('lines')->get() as $e) {
        expect((int) $e->lines->sum('debit'))->toBe((int) $e->lines->sum('credit'));
    }
});

it('proves the same in a real-time month: the commission payment debits cash at once, income once, nothing left for close', function () {
    config(['finance.realtime_from' => '2026-02-01']);
    $co = bootProduct($this);
    courierBooks($co);
    $s = mugClient($co);
    $base = ledgerCash();
    $o = mugOrder($co, $s, 'CP-2', '10', '10', 'client', 'paid_by_sellbees');
    courierAdvanceFor($co, $o);
    collectAll($co, $o, '100');
    settleClient($co, $s['client'], '89.10')->assertSessionMissing('error');

    expect(journalOf('payment'))->toBe([[['cash', 90, 0], ['income', 0, 90]]])                          // the netted commission payment: Dr cash 0.90 / Cr income 0.90
        ->and(ledgerCash() - $base)->toBe(90)->and(ledgerCash() - $base)->toBe(externalCash())->and(ClientSettlement::sum('paid'))->toBe(8_910)
        ->and(Ledger::creditBalance('income'))->toBe(90)->and(Ledger::creditBalance('client.funds'))->toBe(0)->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(0);
    Carbon::setTestNow('2026-03-02 10:00:00');
    PeriodProcessor::close('2026-02', $co->users['finance']);
    expect(Ledger::creditBalance('income'))->toBe(90)->and(ledgerCash() - $base)->toBe(90)->and(journalOf('period'))->toBe([]);   // closing adds nothing: no duplicate revenue
});

it('proves it with the client paid from a bank account and the courier from cash: each account true, the total +0.90', function () {
    config(['finance.realtime_from' => '2026-02-01']);
    $co = bootProduct($this);
    courierBooks($co);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $cashAccount = FinancialAccount::where('is_default', true)->firstOrFail();
    $cashBefore = AccountingService::balance($cashAccount->id);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'CP-3', '10', '10', 'client', 'paid_by_sellbees');
    courierAdvanceFor($co, $o);                                                                     // the courier was paid from the default cash account
    $co->post('clients', 'product.orders.collect', ['amount' => '100', 'collected_on' => '2026-02-11', 'idempotency_key' => (string) Str::uuid(), 'financial_account_id' => $bank->id], $o)->assertSessionMissing('error');
    settleClient($co, $s['client'], '89.10', null, $bank->id)->assertSessionMissing('error');          // and the client is paid from the bank

    $bankBalance = AccountingService::balance($bank->id);
    $cashBalance = AccountingService::balance($cashAccount->id) - $cashBefore;
    expect($bankBalance)->toBe(10_000 - 8_910)->and($cashBalance)->toBe(-1_000)->and($bankBalance + $cashBalance)->toBe(90)->and(externalCash())->toBe(90)   // the 10.90 left in the bank repays the cash the courier took
        ->and(Ledger::creditBalance('client.funds'))->toBe(0)->and(Ledger::debitBalance('assets.client_courier_advance'))->toBe(0)->and(Ledger::creditBalance('income'))->toBe(90);
});

it('never writes the courier reclassification into a closed month: it is dated in the open period and flagged', function () {
    $co = bootProduct($this);
    courierBooks($co);
    $s = mugClient($co);
    $o = mugOrder($co, $s, 'CP-4', '10', '10', 'client', 'paid_by_sellbees');
    $expense = $co->expense('Courier', 'Courier charge for order CP-4', '10', '2026-02-10');
    $co->post('finance', 'product.orders.courier-advance', ['expense_id' => $expense->id], $o)->assertSessionMissing('error');
    Carbon::setTestNow('2026-03-02 10:00:00');
    PeriodProcessor::close('2026-02', $co->users['finance']);                           // February is now final
    $feb = JournalEntry::whereDate('entry_date', '<=', '2026-02-28')->count();
    $febSum = (int) JournalLine::sum('debit');

    Carbon::setTestNow('2026-03-05 10:00:00');
    $advance = CourierAdvance::firstOrFail();
    $co->post('board', 'product.courier-advances.decide', ['decision' => 'approve'], $advance)->assertSessionMissing('error');
    $entry = JournalEntry::where('source_type', 'courier_advance')->firstOrFail();
    expect($entry->entry_date->toDateString())->toBe('2026-03-05')->and($entry->memo)->toContain('closed; recorded in the open period')
        ->and(JournalEntry::whereDate('entry_date', '<=', '2026-02-28')->count())->toBe($feb)       // nothing was added to or changed in February
        ->and((int) $entry->lines->sum('debit'))->toBe((int) $entry->lines->sum('credit'));
});
