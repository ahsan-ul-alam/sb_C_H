<?php

use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierProduct;
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
