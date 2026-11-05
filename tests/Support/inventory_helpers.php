<?php

use App\Models\ClientOrder;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Receiving;
use App\Services\StockLocations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

/** A client project lot of the ordered quantity with NO arrival recorded (the lot exists; nothing is stock yet). */
function unreceivedLot(SellbeesCompany $co, string $qty = '500', string $clientName = 'Example Client A'): array
{
    Carbon::setTestNow('2026-02-10 10:00:00');
    $co->standingPolicy('quick_lot', '1000000000');
    $co->post('clients', 'sourcing.suppliers.store', ['name' => 'Supplier '.Str::random(4), 'phone' => '017'])->assertSessionHasNoErrors();
    $supplier = Supplier::latest('id')->first();
    $co->post('clients', 'sourcing.products.store', ['supplier_id' => $supplier->id, 'name' => 'Premium Hoodie '.Str::random(3), 'reference_price' => '400'])->assertSessionHasNoErrors();
    $product = SupplierProduct::latest('id')->first();
    $client = $co->client($clientName);
    $project = $co->project($client, 'Example Project A');
    $co->post('clients', 'sourcing.lots.store', ['supplier_id' => $supplier->id, 'delivery_cost' => '0', 'submission_key' => (string) Str::uuid(), 'project_id' => $project->id,
        'items' => [['product_id' => $product->id, 'quantity' => $qty, 'profit' => '20']]])->assertSessionHasNoErrors();
    $lot = SourcingLot::latest('id')->first();

    return ['co' => $co, 'client' => $client, 'project' => $project, 'product' => $product, 'lot' => $lot, 'line' => $lot->lines()->first(), 'user' => $co->users['clients']];
}

function integrationStock($test): array
{
    $s = unreceivedLot(SellbeesCompany::boot($test), '200');
    $co = $s['co'];
    $s['bank'] = FinancialAccount::factory()->asDefault()->create(['name' => 'City Bank']);
    $co->invest('A', '1000000', '2026-02-02');
    $s['main'] = StockLocations::default();
    $s['dhk'] = StockLocations::create('DHK', 'Dhaka shelf');
    Receiving::receive($s['line'], '120', '110', '3', '2026-02-10', $s['main']->id, 'GRN-1', null, null, $s['user']);   // 10 rejected
    Receiving::receive($s['line'], '80', '80', '0', '2026-02-10', $s['dhk']->id, 'GRN-2', null, null, $s['user']);
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $s['project']->id], $s['lot'])->assertSessionHasNoErrors();
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '10'])->assertSessionHasNoErrors();

    return $s;
}

function loc(array $s): array
{
    return StockLocations::balances($s['line']->refresh());
}

function refundDelivered(array $s): ClientOrder
{
    $co = $s['co'];
    $co->post('finance', 'product.orders.store', ['project_id' => $s['project']->id, 'reference' => 'O1', 'ordered_on' => '2026-02-10', 'sales_channel' => 'website', 'payment_method' => 'cod', 'delivery_method' => 'courier',
        'discount_reason' => 'launch', 'items' => [['product_id' => $s['product']->id, 'quantity' => '150', 'price' => '600', 'discount' => '50']]])->assertSessionHasNoErrors();
    $o = ClientOrder::where('reference', 'O1')->firstOrFail();
    $co->post('clients', 'product.orders.confirm', [], $o)->assertSessionHasNoErrors();
    $co->post('clients', 'product.orders.dispatch', [], $o)->assertSessionHasNoErrors();
    $item = $o->items()->first();
    $co->post('clients', 'product.orders.deliver', ['delivered' => [$item->id => '100']], $o)->assertSessionHasNoErrors();
    $co->post('clients', 'product.orders.collect', ['amount' => '40000', 'collected_on' => '2026-02-10', 'idempotency_key' => (string) Str::uuid()], $o)->assertSessionHasNoErrors();

    return $o->refresh();
}

function journalBalanced(): bool
{
    return (int) JournalLine::sum('debit') === (int) JournalLine::sum('credit');
}
