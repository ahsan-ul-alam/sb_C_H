<?php

use App\Models\ClientOrder;
use App\Models\OrderCollection;
use App\Services\InventoryReports;
use App\Services\Receiving;
use App\Services\StockLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function discountStock($test): array
{
    $s = unreceivedLot(SellbeesCompany::boot($test), '100');
    Receiving::receive($s['line'], '100', '100', '0', '2026-02-10', StockLocations::default()->id, 'GRN-1', null, null, $s['user']);
    $s['co']->post('clients', 'sourcing.lots.bill-client', ['project_id' => $s['project']->id], $s['lot'])->assertSessionHasNoErrors();
    $s['co']->post('finance', 'finance.orders.rates', ['default_percent' => '10'])->assertSessionHasNoErrors();

    return $s;
}

function discountOrder(array $s, string $who, array $extra = [], string $ref = 'D1')
{
    return $s['co']->post($who, 'product.orders.store', ['project_id' => $s['project']->id, 'reference' => $ref, 'ordered_on' => '2026-02-10', 'sales_channel' => 'facebook', 'payment_method' => 'cod',
        'delivery_method' => 'courier', 'items' => [['product_id' => $s['product']->id, 'quantity' => '10', 'price' => '600', 'discount' => '50']]] + $extra);
}

it('records channel, payment and delivery method, and refuses a discount from a user who may not give one', function () {
    $s = discountStock($this);
    discountOrder($s, 'clients', ['discount_reason' => 'launch offer'])->assertSessionHasErrors('discount_reason'); // client management has no discount authority
    expect(ClientOrder::count())->toBe(0);
    discountOrder($s, 'finance')->assertSessionHas('error');                                                    // an authorised user must still give the reason
    expect(ClientOrder::count())->toBe(0);
    discountOrder($s, 'finance', ['discount_reason' => 'launch offer'])->assertSessionHasNoErrors();
    $o = ClientOrder::firstOrFail();
    expect($o->sales_channel)->toBe('facebook')->and($o->payment_method)->toBe('cod')->and($o->delivery_method)->toBe('courier')->and($o->discount_reason)->toBe('launch offer')
        ->and($o->items->first()->unit_discount)->toBe(5_000)->and($o->items->first()->amount())->toBe(10 * 55_000);
});

it('carries the discount through delivery, collection and commission so no figure ignores it', function () {
    $s = discountStock($this);
    discountOrder($s, 'finance', ['discount_reason' => 'launch offer'])->assertSessionHasNoErrors();
    $o = ClientOrder::firstOrFail();
    foreach (['confirm', 'dispatch', 'deliver'] as $step) {
        $s['co']->post('clients', 'product.orders.'.$step, [], $o)->assertSessionHasNoErrors();
    }
    $o->refresh();
    expect($o->net_value)->toBe(550_000)->and($o->commission)->toBe(55_000);                                      // 10% of the net 5,500, not of the list 6,000
    expect(fn () => $s['co']->post('clients', 'product.orders.collect', ['amount' => '5501', 'collected_on' => '2026-02-10', 'idempotency_key' => (string) Str::uuid()], $o))->not->toThrow(Exception::class);
    expect(OrderCollection::count())->toBe(0);                                                        // more than the net delivered value is refused

    $channel = InventoryReports::build('sales-by-channel')['rows'][0];
    expect($channel[0])->toBe('facebook')->and($channel[2])->toBe('5500.00')->and($channel[5])->toBe('550.00');
    $discounts = InventoryReports::build('discounts')['rows'][0];
    expect($discounts[3])->toBe('6000.00')->and($discounts[4])->toBe('500.00')->and($discounts[5])->toBe('5500.00');
});
