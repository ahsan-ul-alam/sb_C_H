<?php

use App\Models\SourcingArrivalEvent;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\Inventory;
use App\Services\ProjectInventory;
use App\Services\Receiving;
use App\Services\StockLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('does not create stock when a lot is created, only when an arrival is accepted', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this));
    expect(StockMovement::where('sourcing_lot_line_id', $s['line']->id)->count())->toBe(0)
        ->and(ProjectInventory::positions(['project' => $s['project']->id]))->toHaveCount(0);
});

it('receives the hoodie lot in two arrivals: 300 (280 accepted, 20 rejected) then 200', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this));
    $line = $s['line'];

    Receiving::receive($line, '300', '280', '5', '2026-02-10', null, 'GRN-1', 'photo-1', null, $s['user']);
    $line->refresh();
    $b = Inventory::balances($line);
    expect($b['received'])->toBe('280.00')->and($b['available'])->toBe('280.00')
        ->and((string) $line->received_quantity)->toBe('300.00')->and(bcsub((string) $line->received_quantity, (string) $line->usable_quantity, 2))->toBe('20.00')
        ->and(Receiving::expected($line))->toBe('200.00');

    Carbon::setTestNow('2026-02-11 10:00:00');
    Receiving::receive($line, '200', '200', '0', '2026-02-11', null, 'GRN-2', null, null, $s['user']);
    $line->refresh();
    $b = Inventory::balances($line);
    expect($b['received'])->toBe('480.00')->and($b['available'])->toBe('480.00')->and((string) $line->received_quantity)->toBe('500.00')
        ->and(Receiving::expected($line))->toBe('0.00')->and(SourcingArrivalEvent::where('sourcing_lot_line_id', $line->id)->count())->toBe(2)
        ->and(Inventory::reconciles($line))->toBeTrue();

    $row = ProjectInventory::positions(['project' => $s['project']->id])->first();
    expect($row['project']->id)->toBe($s['project']->id)->and($row['b']['available'])->toBe('480.00')->and($row['rejected'])->toBe('20.00')->and($row['attribution'])->toBe('lot_project')
        ->and($row['locations'])->toBe(['Main warehouse' => '480.00']);
});

it('refuses a repeated delivery reference and never adds the stock twice', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this));
    Receiving::receive($s['line'], '100', '100', '0', '2026-02-10', null, 'GRN-1', null, null, $s['user']);
    expect(fn () => Receiving::receive($s['line'], '100', '100', '0', '2026-02-10', null, 'GRN-1', null, null, $s['user']))->toThrow(RuntimeException::class, 'already recorded');
    expect(Inventory::balances($s['line'])['available'])->toBe('100.00')->and(SourcingArrivalEvent::count())->toBe(1);
});

it('refuses receiving more than ordered, accepted above received, and damaged above rejected', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this), '100');
    expect(fn () => Receiving::receive($s['line'], '101', '101', '0', '2026-02-10', null, 'A', null, null, $s['user']))->toThrow(RuntimeException::class, 'still expected');
    expect(fn () => Receiving::receive($s['line'], '50', '60', '0', '2026-02-10', null, 'B', null, null, $s['user']))->toThrow(RuntimeException::class, 'cannot exceed');
    expect(fn () => Receiving::receive($s['line'], '50', '45', '6', '2026-02-10', null, 'C', null, null, $s['user']))->toThrow(RuntimeException::class, 'Damaged');
    expect(StockMovement::count())->toBe(0);
});

it('transfers stock between locations without changing the line total, and refuses more than stands at the origin', function () {
    $s = unreceivedLot(SellbeesCompany::boot($this), '100');
    Receiving::receive($s['line'], '100', '100', '0', '2026-02-10', null, 'GRN-1', null, null, $s['user']);
    $line = $s['line']->refresh();
    $main = StockLocations::default();
    $dhaka = StockLocations::create('DHK', 'Dhaka shelf');
    $t = StockLocations::request($line, null, $main->id, $dhaka->id, '30', 'Fast movers to the front', $s['user']);
    expect(Inventory::balances($line)['available'])->toBe('100.00');            // nothing moves until completed
    StockLocations::complete($t, $s['user']);
    $b = Inventory::balances($line);
    expect($b['available'])->toBe('100.00')->and(StockLocations::onHandAt($line, null, $main->id))->toBe('70.00')->and(StockLocations::onHandAt($line, null, $dhaka->id))->toBe('30.00')
        ->and(Inventory::reconciles($line))->toBeTrue()->and($t->refresh()->status)->toBe('completed');
    expect(fn () => StockLocations::complete($t, $s['user']))->toThrow(RuntimeException::class, 'already');
    $big = StockLocations::request($line, null, $dhaka->id, $main->id, '31', 'too many', $s['user']);
    expect(fn () => StockLocations::complete($big, $s['user']))->toThrow(RuntimeException::class, 'stand at the origin');
    expect(StockLocation::count())->toBe(2);
});
