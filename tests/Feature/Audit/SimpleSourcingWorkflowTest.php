<?php

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Services\FinancialStatements;
use App\Services\LotFinancials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function simpleSupplier(SellbeesCompany $co, string $name = 'Supplier One'): Supplier
{
    $co->post('clients', 'sourcing.suppliers.store', ['name' => $name, 'phone' => '01711111111', 'address' => 'Dhaka'])->assertSessionHasNoErrors();

    return Supplier::where('name', $name)->firstOrFail();
}

function simpleProduct(SellbeesCompany $co, Supplier $s, string $name, string $price, string $sku = ''): SupplierProduct
{
    $co->post('clients', 'sourcing.products.store', array_filter(['supplier_id' => $s->id, 'name' => $name, 'sku' => $sku, 'reference_price' => $price]))->assertSessionHasNoErrors();

    return SupplierProduct::where('supplier_id', $s->id)->where('name', $name)->firstOrFail();
}

function quickLot(SellbeesCompany $co, Supplier $s, array $items, string $delivery, array $extra = [])
{
    return $co->post('clients', 'sourcing.lots.store', ['supplier_id' => $s->id, 'delivery_cost' => $delivery, 'submission_key' => (string) Str::uuid(), 'items' => $items] + $extra);
}

it('lets an employee go from supplier to profit without doing any arithmetic', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $co->invest('A', '100000', '2026-02-01');
    $co->standingPolicy('quick_lot', '1000000'); // the Board's standing authority for ordinary lots (up to 1,000,000.00 landed)

    // 1–2. A supplier with only the essentials, and a product with just a name and a base price.
    $supplier = simpleSupplier($co);
    $product = simpleProduct($co, $supplier, 'Hair Oil', '120', 'HO-1');
    $other = simpleSupplier($co, 'Supplier Two');
    simpleProduct($co, $other, 'Soap', '9');
    $inactive = simpleProduct($co, $supplier, 'Old stock', '5');
    $co->as('clients')->put(route('sourcing.products.update', $inactive), ['inline' => 1, 'supplier_id' => $supplier->id, 'name' => 'Old stock', 'unit' => 'pcs', 'reference_price' => '5'])->assertSessionHasNoErrors(); // is_active unticked

    // 3. Choosing a supplier loads only ITS active products, with the current base price.
    $json = $co->as('clients')->getJson(route('sourcing.suppliers.products', $supplier))->assertOk()->json();
    expect(collect($json)->pluck('name')->all())->toBe(['Hair Oil'])->and($json[0]['price'])->toBe('120.00')->and($json[0]['price_minor'])->toBe(12_000);

    // 4. The brief's worked example, live: 100 × 120 + 1,000 delivery → 130/unit landed → +20 → 150/unit.
    $payload = ['supplier_id' => $supplier->id, 'delivery_cost' => '1000', 'items' => [['product_id' => $product->id, 'quantity' => '100', 'profit' => '20']]];
    $preview = $co->as('clients')->postJson(route('sourcing.lots.preview'), $payload)->assertOk()->json();
    $row = $preview['rows'][0];
    expect([$row['purchase'], $row['delivery'], $row['unit_cost'], $row['price'], $row['selling'], $row['profit']])->toBe(['12,000.00', '1,000.00', '130.00', '150.00', '15,000.00', '2,000.00'])
        ->and($preview['totals']['landed'])->toBe('13,000.00')->and($preview['totals']['selling'])->toBe('15,000.00');

    // 5. Saving recalculates on the server and stores exactly what was shown.
    quickLot($co, $supplier, [['product_id' => $product->id, 'quantity' => '100', 'profit' => '20']], '1000')->assertSessionHasNoErrors();
    $lot = SourcingLot::firstOrFail();
    $line = $lot->lines()->first();
    $f = LotFinancials::for($lot);
    expect($lot->status)->toBe('approved')->and($line->unit_price)->toBe(12_000)->and($line->sell_price)->toBe(15_000)->and($line->approved_price)->toBe(15_000)
        ->and($lot->costs()->first()->amount)->toBe(100_000)->and($lot->costs()->first()->category)->toBe('Delivery')
        ->and($f['purchase'])->toBe($preview['totals']['minor']['purchase'])->and($f['landed'])->toBe($preview['totals']['minor']['landed'])->and($f['landed'])->toBe(1_300_000)
        ->and($f['revenue'])->toBe($preview['totals']['minor']['selling'])->and($f['profit'])->toBe($preview['totals']['minor']['profit'])->and($f['profit'])->toBe(200_000)
        ->and($lot->approved_cost)->toBe(1_300_000)->and($lot->approved_revenue)->toBe(1_500_000);

    // A later price change never touches the saved lot.
    $co->as('clients')->put(route('sourcing.products.update', $product), ['inline' => 1, 'supplier_id' => $supplier->id, 'name' => 'Hair Oil', 'unit' => 'pcs', 'reference_price' => '140', 'is_active' => 1])->assertSessionHasNoErrors();
    expect($line->refresh()->unit_price)->toBe(12_000)->and($product->refresh()->reference_price)->toBe(14_000);

    // 6. The single summary shows everything, with no tab to open.
    $co->as('clients')->get(route('sourcing.lots.show', $lot))->assertOk()->assertSee('15,000.00')->assertSee('13,000.00')->assertSee('2,000.00')->assertSee('Bill client')->assertSee('Supplier One');

    // 7. Bill the client in one click; once only.
    $project = $co->project($co->client('Client Zed'));
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $project->id], $lot)->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    expect($invoice->total)->toBe(1_500_000)->and($invoice->items()->first()->unit_price)->toBe(15_000)->and($invoice->items()->first()->description)->toBe('Hair Oil');
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $project->id], $lot)->assertSessionHas('error');
    expect(Invoice::count())->toBe(1);

    // 8. Client pays in two parts through the normal payment recorder; a double click records one payment; no overpayment.
    $key = (string) Str::uuid();
    $pay = fn (array $d) => $co->post('clients', 'sourcing.lots.client-payment', $d + ['paid_on' => '2026-02-10', 'method' => 'cash'], $lot);
    $pay(['amount' => '6000', 'idempotency_key' => $key])->assertSessionHasNoErrors();
    $pay(['amount' => '6000', 'idempotency_key' => $key]);
    expect(Payment::count())->toBe(1)->and($invoice->refresh()->amount_paid)->toBe(600_000)->and($invoice->status->value)->toBe('partial');
    // The summary labels each figure separately: planned profit is not cash, billed is not received.
    $page = $co->as('clients')->get(route('sourcing.lots.show', $lot))->assertOk();
    $page->assertSeeInOrder(['Client billed (invoiced)', '15,000.00', 'Client received (real receipts)', '6,000.00', 'Client outstanding', '9,000.00'])->assertSee('Partly paid');
    $page->assertSeeInOrder(['Purchase obligation', 'Supplier One', '12,000.00'])->assertSee('Expected profit (at approval)')->assertSee('2,000.00');
    $pay(['amount' => '9000.01', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('amount');
    $pay(['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();                    // blank amount = the whole remaining balance
    expect(Payment::sum('amount'))->toBe(1_500_000)->and($invoice->refresh()->status->value)->toBe('paid')
        ->and(FinancialStatements::monthFigures('2026-02')['income'])->toBe(1_500_000);              // company income counted once
    $pay(['idempotency_key' => (string) Str::uuid()])->assertSessionHas('error');                    // nothing left to collect

    // 9. Supplier: part payment, then the rest; delivery paid separately. Nothing is "paid" until recorded.
    $supplierPay = fn (string $amount) => $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $supplier->id, 'amount' => $amount, 'paid_on' => '2026-02-11', 'idempotency_key' => (string) Str::uuid()], $lot);
    $cashBefore = dr('cash');
    $supplierPay('5000')->assertSessionHasNoErrors();
    $co->as('finance')->get(route('sourcing.lots.show', $lot))->assertSeeInOrder(['Purchase obligation', '12,000.00', '5,000.00', '7,000.00'])->assertSee('awaiting Board verification')->assertSee('Unpaid')->assertSee('Mark paid')->assertSee('1,000.00');
    expect(dr('cash'))->toBe($cashBefore)->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(700_000)->and(LotFinancials::for($lot)['landed'])->toBe(1_300_000); // cost unchanged
    $supplierPay('7000.01')->assertSessionHas('error');
    $supplierPay('7000')->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.costs.pay', ['paid_on' => '2026-02-11'], $lot->costs()->first())->assertSessionHasNoErrors();
    expect($co->verifyAllExpenses())->toBe(3);
    $f = LotFinancials::for($lot->refresh());
    expect($f['supplier_outstanding'])->toBe(0)->and($f['costs_unpaid'])->toBe(0)->and($f['supplier_paid'])->toBe(1_200_000)->and($f['costs_paid'])->toBe(100_000)
        ->and(dr('expense'))->toBe(1_300_000)->and(dr('cash'))->toBe($cashBefore - 1_300_000)->and($f['profit'])->toBe(200_000)->and(SupplierPayment::count())->toBe(2);

    // 10. Goods arrive (a finance control: a lot cannot close without its arrival on record), then close in one step; the books agree.
    $co->post('finance', 'sourcing.lots.close', [], $lot)->assertSessionHas('error');                   // refused, with the reason, until arrival is recorded
    expect($lot->refresh()->status)->toBe('approved');
    $co->as('clients')->get(route('sourcing.lots.show', $lot))->assertSee('Before this lot can be closed')->assertSee('Mark received');
    $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => '100', 'arrived_on' => '2026-02-10'], $line)->assertSessionHasNoErrors();
    $co->post('clients', 'sourcing.lots.close', [], $lot)->assertForbidden();                           // settlement stays with Finance
    $co->post('finance', 'sourcing.lots.close', [], $lot)->assertSessionHasNoErrors();
    expect($lot->refresh()->status)->toBe('settled')->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'))->and(ledgerAudit())->toMatchArray(['unbalanced' => 0, 'duplicates' => 0]);
});

it('splits a fractional delivery cost exactly and reconciles every line, total and profit to the paisa', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $s = simpleSupplier($co);
    $co->standingPolicy('quick_lot', '1000000');
    $p1 = simpleProduct($co, $s, 'P1', '13.33');
    $p2 = simpleProduct($co, $s, 'P2', '41.17');
    $p3 = simpleProduct($co, $s, 'P3', '2.71');
    $items = [['product_id' => $p1->id, 'quantity' => '7', 'profit' => '3.33'], ['product_id' => $p2->id, 'quantity' => '3', 'profit' => '10'], ['product_id' => $p3->id, 'quantity' => '11', 'profit' => '0.50']];
    quickLot($co, $s, $items, '100.01')->assertSessionHasNoErrors();
    $lot = SourcingLot::firstOrFail();

    // Independent arithmetic in whole paisa.
    $qty = [7, 3, 11];
    $base = [1333, 4117, 271];
    $profit = [333, 1000, 50];
    $purchase = array_map(fn ($i) => $qty[$i] * $base[$i], [0, 1, 2]);
    $share = largestRemainder(10_001, $purchase);
    $sellTotal = 0;
    $landedTotal = 0;
    foreach ([0, 1, 2] as $i) {
        $landed = $purchase[$i] + $share[$i];
        $price = max(intdiv(2 * ($landed + $profit[$i] * $qty[$i]) + $qty[$i], 2 * $qty[$i]), intdiv($landed + $qty[$i] - 1, $qty[$i])); // round half up, never below break-even
        $line = $lot->lines()->get()[$i];
        expect($line->sell_price)->toBe($price);
        $sellTotal += $qty[$i] * $price;
        $landedTotal += $landed;
    }
    $f = LotFinancials::for($lot);
    expect(array_sum($share))->toBe(10_001)->and(collect($f['lines'])->pluck('shared')->values()->all())->toBe(array_values($share))
        ->and($f['landed'])->toBe($landedTotal)->and($f['revenue'])->toBe($sellTotal)->and($f['profit'])->toBe($sellTotal - $landedTotal)
        ->and($lot->approved_revenue - $lot->approved_cost)->toBe($sellTotal - $landedTotal)
        ->and($lot->costs()->count())->toBe(1); // one delivery cost, never duplicated per product
});

it('refuses bad lots without creating anything, and does not create a second lot on a repeated submit', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $s = simpleSupplier($co);
    $good = simpleProduct($co, $s, 'Good', '10');
    $stranger = simpleProduct($co, simpleSupplier($co, 'Other Supplier'), 'Alien', '10');
    $off = simpleProduct($co, $s, 'Retired', '10');
    $off->update(['is_active' => false]);

    foreach ([
        [[], '0'],                                                                                       // no products
        [[['product_id' => $good->id, 'quantity' => '0', 'profit' => '1']], '0'],                       // zero quantity
        [[['product_id' => $good->id, 'quantity' => '-1', 'profit' => '1']], '0'],
        [[['product_id' => $good->id, 'quantity' => '1', 'profit' => '-1']], '0'],                      // negative profit
        [[['product_id' => $good->id, 'quantity' => '1', 'profit' => '1']], '-5'],                      // negative delivery
        [[['product_id' => $stranger->id, 'quantity' => '1', 'profit' => '1']], '0'],                   // another supplier's product
        [[['product_id' => $off->id, 'quantity' => '1', 'profit' => '1']], '0'],                        // inactive product
        [[['product_id' => $good->id, 'quantity' => '1'], ['product_id' => $good->id, 'quantity' => '2']], '0'], // same product twice
    ] as [$items, $delivery]) {
        quickLot($co, $s, $items, $delivery);
    }
    expect(SourcingLot::count())->toBe(0)->and(Expense::count())->toBe(0);
    $co->as('clients')->postJson(route('sourcing.lots.preview'), ['supplier_id' => $s->id, 'delivery_cost' => '0', 'items' => [['product_id' => $stranger->id, 'quantity' => '1']]])->assertStatus(422)->assertJsonStructure(['message']);

    $key = (string) Str::uuid();
    $submit = fn () => $co->post('clients', 'sourcing.lots.store', ['supplier_id' => $s->id, 'delivery_cost' => '0', 'submission_key' => $key, 'items' => [['product_id' => $good->id, 'quantity' => '5', 'profit' => '2']]]);
    $submit()->assertSessionHasNoErrors();
    $submit()->assertSessionHas('warning');
    expect(SourcingLot::count())->toBe(1);

    // Empty-lot creation (the previous way) still works for the advanced path.
    $co->post('clients', 'sourcing.lots.store', ['title' => 'Manual lot', 'allocation_basis' => 'value'])->assertSessionHasNoErrors();
    expect(SourcingLot::count())->toBe(2);
});

it('keeps the simple workflow inside the existing permissions', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $s = simpleSupplier($co);
    $co->standingPolicy('quick_lot', '1000000');
    $p = simpleProduct($co, $s, 'Item', '10');
    $items = [['product_id' => $p->id, 'quantity' => '5', 'profit' => '2']];

    foreach (['employee', 'auditor', 'hr', 'partnerA'] as $who) {
        $co->post($who, 'sourcing.lots.store', ['supplier_id' => $s->id, 'items' => $items])->assertForbidden();
        $co->as($who)->postJson(route('sourcing.lots.preview'), ['supplier_id' => $s->id, 'items' => $items])->assertForbidden();
    }
    $co->as('auditor')->getJson(route('sourcing.suppliers.products', $s))->assertOk();      // may read, not change
    $co->as('employee')->getJson(route('sourcing.suppliers.products', $s))->assertForbidden();
    expect(SourcingLot::count())->toBe(0);

    quickLot($co, $s, $items, '0')->assertSessionHasNoErrors();
    $lot = SourcingLot::firstOrFail();
    $co->as('auditor')->get(route('sourcing.lots.show', $lot))->assertOk()->assertDontSee('Mark supplier paid')->assertDontSee('Bill client');
    $co->post('auditor', 'sourcing.lots.client-payment', ['paid_on' => '2026-02-10', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()], $lot)->assertForbidden();
    $co->post('clients', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => '1', 'paid_on' => '2026-02-10'], $lot)->assertForbidden(); // supplier money stays with Finance
    $co->post('auditor', 'sourcing.lots.bill-client', ['project_id' => 1], $lot)->assertForbidden();
    expect(Payment::count())->toBe(0)->and(SupplierPayment::count())->toBe(0);
});

it('approves an ordinary quick lot only under the Board policy, and sends everything else to the normal approval', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $s = simpleSupplier($co);
    $p = simpleProduct($co, $s, 'Item', '100');
    $items = [['product_id' => $p->id, 'quantity' => '10', 'profit' => '20']]; // landed 1,000.00; client 1,200.00
    $project = $co->project($co->client('Client Q'));

    // No standing policy: the lot is saved, priced, and cannot be billed until it is approved by someone else.
    quickLot($co, $s, $items, '0')->assertSessionHasNoErrors();
    $lot = SourcingLot::firstOrFail();
    expect($lot->status)->toBe('priced')->and($lot->approved_at)->toBeNull()->and($lot->lines()->first()->approved_price)->toBeNull()->and($lot->lines()->first()->sell_price)->toBe(12_000);
    $co->as('clients')->get(route('sourcing.lots.show', $lot))->assertSee('Waiting for pricing approval')->assertDontSee('Bill client');
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $project->id], $lot)->assertSessionHas('error');
    expect(Invoice::count())->toBe(0);
    expect(DB::table('notifications')->where('data', 'like', '%awaiting approval%')->count())->toBeGreaterThan(0); // the approvers are told
    $co->post('board', 'sourcing.lots.approve', [], $lot)->assertSessionHasNoErrors();                          // the normal approval still works
    expect($lot->refresh()->status)->toBe('approved')->and($lot->approved_by)->not->toBeNull()->and($lot->lines()->first()->approved_price)->toBe(12_000)->and($lot->approved_revenue)->toBe(120_000);

    // The Board authorises ordinary lots up to 5,000.00 landed: smaller lots approve themselves, larger ones do not.
    $co->standingPolicy('quick_lot', '5000');
    quickLot($co, $s, $items, '0')->assertSessionHasNoErrors();
    $auto = SourcingLot::latest('id')->first();
    expect($auto->status)->toBe('approved')->and($auto->approved_by)->toBeNull()->and($auto->approved_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'sourcing.quick.saved')->where('details', 'like', '%approved automatically under standing policy%')->count())->toBe(1);
    quickLot($co, $s, [['product_id' => $p->id, 'quantity' => '60', 'profit' => '20']], '0')->assertSessionHasNoErrors(); // landed 6,000.00 > limit
    $big = SourcingLot::latest('id')->first();
    expect($big->status)->toBe('priced');
    $co->post('clients', 'sourcing.lots.approve', [], $big)->assertForbidden();                                      // the submitter's role cannot approve
    $co->post('finance', 'sourcing.lots.approve', [], $big)->assertSessionHasNoErrors();                             // Finance may approve pricing it did not submit
    expect($big->refresh()->status)->toBe('approved');

    // Revoked policy: back to normal approval.
    $co->post('board', 'finance.automation.revoke', [], 'quick_lot')->assertSessionHasNoErrors();
    quickLot($co, $s, $items, '0');
    expect(SourcingLot::latest('id')->first()->status)->toBe('priced');
});
