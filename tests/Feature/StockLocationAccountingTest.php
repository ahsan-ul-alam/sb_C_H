<?php

use App\Models\ClientOrder;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\Inventory;
use App\Services\InventoryReports;
use App\Services\Receiving;
use App\Services\SourcingArrival;
use App\Services\StockLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** 60 units received at MAIN, 20 of them moved to DHK, the lot billed to the client so orders can use it. */
function placedStock($test, string $qty = '60'): array
{
    $s = unreceivedLot(SellbeesCompany::boot($test), $qty);
    $main = StockLocations::default();
    Receiving::receive($s['line'], $qty, $qty, '0', '2026-02-10', $main->id, 'GRN-1', null, null, $s['user']);
    $dhk = StockLocations::create('DHK', 'Dhaka shelf');
    StockLocations::complete(StockLocations::request($s['line']->refresh(), null, $main->id, $dhk->id, '20', 'spread', $s['user']), $s['user']);
    $s['co']->post('clients', 'sourcing.lots.bill-client', ['project_id' => $s['project']->id], $s['lot'])->assertSessionHasNoErrors();
    $s += ['main' => $main, 'dhk' => $dhk];

    return $s;
}

function locOrder(array $s, string $ref, string $qty): ClientOrder
{
    $s['co']->post('clients', 'product.orders.store', ['project_id' => $s['project']->id, 'reference' => $ref, 'ordered_on' => '2026-02-10', 'items' => [['product_id' => $s['product']->id, 'quantity' => $qty, 'price' => '600']]])->assertSessionHasNoErrors();

    return ClientOrder::where('reference', $ref)->firstOrFail();
}

it('dispatches from the locations that really hold the units and never drives one below zero', function () {
    $s = placedStock($this);                                                          // MAIN 40, DHK 20
    $o = locOrder($s, 'L1', '50');
    $s['co']->post('clients', 'product.orders.confirm', [], $o)->assertSessionHasNoErrors();
    expect(StockLocations::balances($s['line']))->toBe([(string) $s['main']->id => '40.00', (string) $s['dhk']->id => '20.00']); // a reservation moves nothing physically
    $s['co']->post('clients', 'product.orders.dispatch', [], $o)->assertSessionHasNoErrors();
    expect(StockLocations::onHandAt($s['line'], $s['main']->id))->toBe('0.00')->and(StockLocations::onHandAt($s['line'], $s['dhk']->id))->toBe('10.00')
        ->and(StockMovement::where('type', 'dispatch')->count())->toBe(2);        // 40 from MAIN + 10 from DHK
    expect(collect(StockLocations::balances($s['line']))->every(fn ($q) => bccomp($q, '0', 2) >= 0))->toBeTrue();
    $s['co']->post('clients', 'product.orders.dispatch', [], $o);               // a duplicate dispatch changes nothing
    expect(StockMovement::where('type', 'dispatch')->count())->toBe(2)->and(Inventory::balances($s['line'])['dispatched'])->toBe('50.00');

    // A failed delivery puts the units back where they were taken from.
    $s['co']->post('clients', 'product.orders.fail', [], $o)->assertSessionHasNoErrors();
    expect(StockLocations::balances($s['line']))->toBe([(string) $s['main']->id => '40.00', (string) $s['dhk']->id => '20.00'])->and(Inventory::reconciles($s['line']))->toBeTrue();
});

it('refuses a transfer or write-off larger than what stands in the location', function () {
    $s = placedStock($this);
    $big = StockLocations::request($s['line']->refresh(), null, $s['dhk']->id, $s['main']->id, '21', 'too many', $s['user']);
    expect(fn () => StockLocations::complete($big, $s['user']))->toThrow(RuntimeException::class, 'stand at that location');
    expect(StockLocations::balances($s['line']))->toBe([(string) $s['main']->id => '40.00', (string) $s['dhk']->id => '20.00']);
    expect(fn () => Inventory::adjust($s['line'], '-61', 'count', $s['user']))->toThrow(RuntimeException::class);
    Inventory::adjust($s['line'], '-45', 'stock-take', $s['user']);              // taken from MAIN first (40), then DHK (5)
    expect(StockLocations::balances($s['line']))->toBe([(string) $s['dhk']->id => '15.00']);
});

it('keeps older stock explicitly unplaced, does not invent a location, and requires one once locations exist', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this), '100');
    SourcingArrival::record($s['line'], '50', '50', '2026-02-10', $s['user']);   // the older path, before any location existed
    expect(StockLocations::balances($s['line']))->toBe(['' => '50.00'])->and(StockLocation::count())->toBe(0);
    $row = InventoryReports::build('location-reconciliation')['rows'][0];
    expect($row[2])->toBe('50.00')->and($row[3])->toBe('0.00')->and($row[4])->toBe('50.00')->and($row[6])->toBe('0.00')->and($row[8])->toBe('Reconciled');

    $main = StockLocations::default();                                          // an operator verifies and places it with a transfer from "not placed"
    StockLocations::complete(StockLocations::request($s['line']->refresh(), null, null, $main->id, '30', 'verified on shelf', $s['user']), $s['user']);
    expect(StockLocations::balances($s['line']))->toBe(['' => '20.00', (string) $main->id => '30.00']);

    expect(fn () => SourcingArrival::record($s['line'], '60', '60', '2026-02-10', $s['user']))->toThrow(RuntimeException::class, 'name the location');
    expect(fn () => Receiving::receive($s['line'], '10', '10', '0', '2026-02-10', null, 'GRN-2', null, null, $s['user']))->toThrow(RuntimeException::class, 'Choose the warehouse location');
    $row = InventoryReports::build('location-reconciliation')['rows'][0];
    expect($row[3])->toBe('30.00')->and($row[4])->toBe('20.00')->and($row[8])->toBe('Reconciled');
    expect(InventoryReports::build('location-stock')['rows'])->toHaveCount(2);
});
