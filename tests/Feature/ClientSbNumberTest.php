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

it('verifies the email in the same save only when staff tick that it belongs to the client', function () {
    $co = SellbeesCompany::boot($this);
    $client = Client::create(['name' => 'Tick Co', 'mobile' => '017']);
    $form = ['name' => 'Tick Co', 'mobile' => '017', 'email' => 'owner@example.test'];

    $co->as('clients')->put(route('clients.update', $client), $form)->assertSessionHas('status', 'Client updated.');
    expect($client->fresh()->hasVerifiedEmail())->toBeFalse();

    $co->as('clients')->put(route('clients.update', $client), $form + ['email_confirmed' => '1'])->assertSessionHas('status', 'Client updated. Email address marked as verified.');
    $fresh = $client->fresh();
    expect($fresh->hasVerifiedEmail())->toBeTrue()->and($fresh->email_verified_by)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'client.email_verified')->where('subject', 'client '.$client->id)->exists())->toBeTrue();

    // Changing the address without the tick withdraws the verification; the box cannot verify an empty address.
    $co->as('clients')->put(route('clients.update', $client), ['email' => 'someone-else@example.test'] + $form);
    expect($client->fresh()->hasVerifiedEmail())->toBeFalse();
    $co->as('clients')->put(route('clients.update', $client), ['name' => 'Tick Co', 'mobile' => '017', 'email' => '', 'email_confirmed' => '1']);
    expect($client->fresh()->hasVerifiedEmail())->toBeFalse();

    // A new client can be created already verified.
    $co->as('clients')->post(route('clients.store'), ['name' => 'New Co', 'mobile' => '018', 'email' => 'new@example.test', 'email_confirmed' => '1']);
    expect(Client::where('name', 'New Co')->first()->hasVerifiedEmail())->toBeTrue();
});
