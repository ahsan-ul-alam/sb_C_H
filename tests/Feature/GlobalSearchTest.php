<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BusinessDashboard;
use App\Support\Money;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

function searchWorld($test): array
{
    $co = SellbeesCompany::boot($test);
    $client = Client::create(['name' => 'Zenith Traders', 'mobile' => '01711223344', 'email' => 'private@zenith.example', 'id_number' => 'NID-998877']);
    $project = $co->project($client, 'Zenith Mart');
    $invoice = new Invoice(['number' => 'INV-ZEN-001', 'issued_on' => '2026-02-01', 'currency' => 'BDT', 'purpose' => 'Onboarding']);
    $invoice->forceFill(['project_id' => $project->id, 'total' => 100_000, 'amount_paid' => 0, 'status' => 'unpaid'])->save();
    $payment = new Payment(['amount' => 50_000, 'paid_on' => '2026-02-02', 'method' => 'cash', 'reference' => 'ZEN-PAY-77']);
    $payment->forceFill(['project_id' => $project->id, 'idempotency_key' => (string) Str::uuid()])->save();
    $lotId = DB::table('sourcing_lots')->insertGetId(['number' => 'SL-777777', 'title' => 'Zenith hoodie batch', 'status' => 'draft', 'allocation_basis' => 'value', 'pricing_method' => 'fixed', 'pricing_value' => 0,
        'created_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);

    return compact('co', 'client', 'project', 'invoice', 'payment', 'lotId');
}

function searchAs($w, string $who, string $q)
{
    return $w['co']->as($who)->getJson(route('search', ['q' => $q]));
}

function resultUrls($response): array
{
    return collect($response->json('groups'))->flatMap(fn ($g) => collect($g['items'])->pluck('url'))->all();
}

it('puts the search trigger and palette on every authenticated page, not just the dashboard', function () {
    $w = searchWorld($this);
    foreach ([route('dashboard'), route('clienthub.dashboard'), route('clients.index'), route('invoices.index'), route('finance.expenses')] as $url) {
        $w['co']->as('finance')->get($url)->assertOk()->assertSee('id="cmdk"', false)->assertSee('data-cmdk-open', false)->assertSee('js/command-palette.js', false)->assertSee('Ctrl K');
    }
});

it('finds clients, projects, invoices, payments and lots by the identifiers staff use, each linking to a real page', function () {
    $w = searchWorld($this);
    foreach (['Zenith Tr', $w['client']->sb_number, '01711223344'] as $term) {
        expect(resultUrls(searchAs($w, 'finance', $term)))->toContain(route('clients.show', $w['client']));
    }
    expect(resultUrls(searchAs($w, 'finance', 'Zenith Mart')))->toContain(route('projects.show', $w['project']))
        ->and(resultUrls(searchAs($w, 'finance', 'INV-ZEN')))->toContain(route('invoices.show', $w['invoice']))
        ->and(resultUrls(searchAs($w, 'finance', 'ZEN-PAY')))->toContain(route('payments.edit', $w['payment']))
        ->and(resultUrls(searchAs($w, 'finance', 'SL-7777')))->toContain(route('sourcing.lots.show', $w['lotId']))
        ->and(resultUrls(searchAs($w, 'finance', 'Historical')))->toContain(route('finance.historical'));
    foreach (['traders zenith', '0171 1223-344'] as $term) {                      // word order and phone formatting do not matter
        expect(resultUrls(searchAs($w, 'finance', $term)))->toContain(route('clients.show', $w['client']));
    }
    foreach (resultUrls(searchAs($w, 'finance', 'Zenith')) as $url) {
        $w['co']->as('finance')->get($url)->assertOk();                                           // every result opens
    }
});

it('only offers what the user may open and never returns private fields', function () {
    $w = searchWorld($this);
    $finance = searchAs($w, 'finance', 'Zenith');
    expect($finance->getContent())->not->toContain('private@zenith.example')->and($finance->getContent())->not->toContain('NID-998877');   // no email or ID number

    $auditor = searchAs($w, 'auditor', 'Zenith');                                                // an auditor has no ClientHub access
    expect(resultUrls($auditor))->not->toContain(route('clients.show', $w['client']))->and(collect($auditor->json('groups'))->pluck('label')->all())->not->toContain('Clients', 'Invoices', 'Payments');
    expect(resultUrls(searchAs($w, 'auditor', 'Ledger')))->toContain(route('finance.ledger-reports'));

    expect(searchAs($w, 'employee', 'Zenith')->json('groups'))->toBe([]);                       // a user with no permissions gets nothing
    $clients = searchAs($w, 'clients', 'Zenith');                                                // client management: clients and lots, but not finance pages
    expect(resultUrls($clients))->toContain(route('clients.show', $w['client']))->and(resultUrls(searchAs($w, 'clients', 'Ledger')))->not->toContain(route('finance.ledger-reports'));
    $this->app['auth']->forgetGuards();
    $this->getJson(route('search', ['q' => 'Zenith']))->assertUnauthorized();                  // guests cannot search
});

it('caps results, ignores one-character queries, offers shortcuts when empty and is rate limited', function () {
    $w = searchWorld($this);
    foreach (range(1, 8) as $i) {
        Client::create(['name' => 'Bulkcorp '.$i, 'mobile' => '0170000000'.$i]);
    }
    $groups = collect(searchAs($w, 'finance', 'Bulkcorp')->json('groups'))->keyBy('label');
    expect($groups['Clients']['items'])->toHaveCount(5)->and(searchAs($w, 'finance', 'B')->json('groups'))->toBe([]);

    $shortcuts = searchAs($w, 'finance', '')->json('groups');
    expect($shortcuts[0]['label'])->toBe('Go to')->and(collect($shortcuts[0]['items'])->pluck('title')->all())->toContain('Business Dashboard', 'Clients');

    $statuses = collect(range(1, 70))->map(fn () => $w['co']->as('finance')->getJson(route('search', ['q' => 'zz']))->status());
    expect($statuses->first())->toBe(200)->and($statuses->last())->toBe(429);
});

it('still answers "Client" with navigation and clients when the database has not got the SB number column yet (the production HTTP 500)', function () {
    $w = searchWorld($this);
    Schema::table('clients', function ($table) {
        $table->dropUnique('clients_sb_number_unique');
        $table->dropColumn('sb_number');
    });
    $response = searchAs($w, 'finance', 'Client')->assertOk();
    expect(resultUrls($response))->toContain(route('clients.index'));
    expect(resultUrls(searchAs($w, 'finance', 'Zenith')))->toContain(route('clients.show', $w['client']));
});

it('skips a broken group, logs it and never shows the user an error or a stack trace', function () {
    $w = searchWorld($this);
    Schema::drop('sourcing_lots');                                                                // a whole table missing: the Sourcing lots group fails
    Log::spy();
    $response = searchAs($w, 'finance', 'Zenith')->assertOk();
    expect(resultUrls($response))->toContain(route('clients.show', $w['client']))
        ->and($response->getContent())->not->toContain('SQLSTATE')->and($response->getContent())->not->toContain('sourcing_lots');
    Log::shouldHaveReceived('error')->atLeast()->once();
});

it('has no duplicate navigation labels and keeps role-based menus', function () {
    $w = searchWorld($this);
    $labels = Navigation::visible($w['co']->users['super'])->flatten(1)->pluck(0);
    expect($labels->duplicates()->all())->toBe([]);
    $w['co']->as('finance')->get(route('dashboard'))->assertSee('Business Dashboard')->assertSee('Historical Reconciliation');
    $w['co']->as('clients')->get(route('clienthub.dashboard'))->assertOk()->assertSee('Clients')->assertDontSee('Historical Reconciliation');
});

it('shows exactly the values the dashboard service computes', function () {
    $w = searchWorld($this);
    $w['co']->invest('A', '1000', '2026-02-01');
    $w['co']->expense('Office', 'Desk', '250', '2026-02-05', asset: true);
    $data = BusinessDashboard::build(null, CarbonImmutable::today());

    $page = $w['co']->as('finance')->get(route('dashboard'))->assertOk();
    foreach ([$data['net_profit']['amount'], $data['investment']['net'], $data['funding']['collections'], $data['revenue']['verified'], $data['expenses']['total'], $data['funding']['remaining'], $data['position']['invoices_outstanding']] as $value) {
        $page->assertSee(Money::format($value));
    }
    $page->assertSee('Overall Business Position')->assertSee('Business Overview');
});
