<?php

use App\Models\Client;
use App\Services\SbNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

function sbClient(string $name = 'Alpha Traders'): Client
{
    return Client::create(['name' => $name, 'mobile' => '01700000000']);
}

it('numbers new clients in sequence with the exact SB format', function () {
    $first = sbClient('One');
    $second = sbClient('Two');

    expect($first->sb_number)->toBe('SB-000001')->and($second->sb_number)->toBe('SB-000002')
        ->and(SbNumber::isValid($first->sb_number))->toBeTrue()->and(SbNumber::isValid('SB-12'))->toBeFalse()->and(SbNumber::isValid('sb-000001'))->toBeFalse();
});

it('cannot be set by mass assignment or changed afterwards', function () {
    $client = Client::create(['name' => 'Alpha', 'mobile' => '017', 'sb_number' => 'SB-999999']);
    expect($client->sb_number)->toBe('SB-000001');

    $client->sb_number = 'SB-000777';
    expect(fn () => $client->save())->toThrow(LogicException::class);
    expect($client->fresh()->sb_number)->toBe('SB-000001');
    expect(fn () => DB::table('clients')->insert(['name' => 'Dup', 'mobile' => '1', 'sb_number' => 'SB-000001']))->toThrow(Exception::class); // the database enforces uniqueness
});

it('never reuses the number of a deleted client', function () {
    $a = sbClient('A');
    $b = sbClient('B');
    $b->delete();
    $c = sbClient('C');

    expect($a->sb_number)->toBe('SB-000001')->and($c->sb_number)->toBe('SB-000003')->and(Client::where('sb_number', 'SB-000002')->exists())->toBeFalse();
});

it('allocates unique numbers for many creations', function () {
    $numbers = collect(range(1, 60))->map(fn ($i) => sbClient('C'.$i)->sb_number);

    expect($numbers->unique()->count())->toBe(60)->and(DB::table('sb_number_sequence')->value('last_value'))->toBe(60);
});

it('finds a client by SB number and shows it in the list and profile', function () {
    $co = SellbeesCompany::boot($this);
    $client = sbClient('Findable Ltd');
    $client->update(['email' => 'find@example.test']);
    sbClient('Other Ltd');

    $co->as('clients')->get(route('clients.index', ['q' => $client->sb_number]))->assertSee('Findable Ltd')->assertSee($client->sb_number)->assertDontSee('Other Ltd');
    $co->as('clients')->get(route('clients.show', $client))->assertSee($client->sb_number)->assertSee('Not verified');
});

it('withdraws email verification when the address changes and lets staff verify it', function () {
    $co = SellbeesCompany::boot($this);
    $client = Client::create(['name' => 'Mail Co', 'mobile' => '017', 'email' => 'old@example.test']);
    expect($client->hasVerifiedEmail())->toBeFalse();

    $co->post('clients', 'clients.verify-email', [], $client)->assertSessionHas('status');
    expect($client->fresh()->hasVerifiedEmail())->toBeTrue();

    $client->fresh()->update(['email' => 'new@example.test']);
    expect($client->fresh()->hasVerifiedEmail())->toBeFalse();
});
