<?php

use App\Mail\ClientInformationCode;
use App\Models\Client;
use App\Models\ClientApiIntegration;
use App\Models\ClientInfoChallenge;
use App\Models\ClientOrder;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Services\ClientApiIntegrations;
use App\Services\ClientInformationOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

const REQUEST_URL = '/api/v1/client-information/request';
const VERIFY_URL = '/api/v1/client-information/verify';

/** A client with verified email, granted to a fresh integration. @return array{client: Client, integration: ClientApiIntegration, credential: string} */
function apiClient(string $name = 'Api Client', bool $verified = true, bool $granted = true): array
{
    $client = Client::create(['name' => $name, 'mobile' => '017', 'email' => strtolower(str_replace(' ', '', $name)).'@example.test']);
    if ($verified) {
        $client->forceFill(['email_verified_at' => now()])->save();
    }
    $made = ClientApiIntegrations::create('Website '.$name);
    if ($granted) {
        ClientApiIntegrations::grant($made['integration'], $client);
    }

    return ['client' => $client, 'integration' => $made['integration'], 'credential' => $made['credential']];
}

function requestCode(array $a, ?string $sb = null)
{
    return test()->withToken($a['credential'])->postJson(REQUEST_URL, ['sb_number' => $sb ?? $a['client']->sb_number]);
}

function sentCode(): ?string
{
    return Mail::sent(ClientInformationCode::class)->last()?->code;
}

function verifyCode(array $a, string $requestId, string $code, ?string $sb = null)
{
    return test()->withToken($a['credential'])->postJson(VERIFY_URL, ['sb_number' => $sb ?? $a['client']->sb_number, 'request_id' => $requestId, 'verification_code' => $code]);
}

it('refuses callers without a valid, active credential', function () {
    $a = apiClient();
    $this->postJson(REQUEST_URL, ['sb_number' => $a['client']->sb_number])->assertUnauthorized();
    $this->withToken($a['integration']->key_id.'.wrong')->postJson(REQUEST_URL, ['sb_number' => $a['client']->sb_number])->assertUnauthorized();
    $this->withToken('garbage')->postJson(VERIFY_URL, [])->assertUnauthorized();

    ClientApiIntegrations::revoke($a['integration']);
    requestCode($a)->assertUnauthorized();

    $rotated = apiClient('Second Client');
    $old = $rotated['credential'];
    $new = ClientApiIntegrations::rotate($rotated['integration']);
    $this->withToken($old)->postJson(REQUEST_URL, ['sb_number' => $rotated['client']->sb_number])->assertUnauthorized();
    $this->withToken($new)->postJson(REQUEST_URL, ['sb_number' => $rotated['client']->sb_number])->assertOk();
});

it('answers every request the same way so nothing reveals whether a client exists or has an email', function () {
    Mail::fake();
    $eligible = apiClient('Eligible Co');
    $unverified = apiClient('Unverified Co', verified: false);
    $ungranted = apiClient('Ungranted Co', granted: false);

    $shapes = collect([
        requestCode($eligible), requestCode($eligible, 'SB-424242'), requestCode($eligible, 'not-an-sb'), requestCode($unverified), requestCode($ungranted),
    ])->map(function ($r) {
        $r->assertOk()->assertJsonStructure(['success', 'message', 'request_id', 'expires_in']);

        return [array_keys($r->json()), $r->json('message'), $r->json('expires_in')];
    });

    expect($shapes->unique()->count())->toBe(1);
    Mail::assertSent(ClientInformationCode::class, 1);
    expect(ClientInfoChallenge::count())->toBe(1);
    $other = requestCode($eligible)->json('request_id');          // the eligible one asked again inside the cooldown
    expect($other)->toBe(ClientInfoChallenge::first()->request_id);
});

it('emails a six-digit code to the registered address and stores only a hash', function () {
    Mail::fake();
    $a = apiClient('Mail Target');
    $response = requestCode($a)->assertOk();

    Mail::assertSent(ClientInformationCode::class, fn ($m) => $m->hasTo('mailtarget@example.test'));
    $code = sentCode();
    expect($code)->toMatch('/^\d{6}$/')->and($response->getContent())->not->toContain($code);
    $row = ClientInfoChallenge::firstOrFail();
    expect($row->code_hash)->not->toContain($code)->and($row->expires_at->diffInSeconds($row->created_at, true))->toBe(300.0)->and($row->sent_at)->not->toBeNull();
    expect(DB::table('client_api_audits')->get()->toJson())->not->toContain($code);
});

it('returns the client information once for the right code and refuses a replay', function () {
    Mail::fake();
    $a = apiClient('Replay Co');
    $id = requestCode($a)->json('request_id');
    $code = sentCode();

    verifyCode($a, $id, $code)->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.sb_number', $a['client']->sb_number)
        ->assertJsonPath('data.client.name', 'Replay Co')->assertJsonPath('data.profitability.status', 'incomplete')->assertJsonPath('data.profitability.net_profit_minor', null);
    verifyCode($a, $id, $code)->assertStatus(422)->assertExactJson(['success' => false, 'message' => 'Verification failed.']);
});

it('locks the challenge after five wrong codes even for the right code', function () {
    Mail::fake();
    $a = apiClient('Brute Co');
    $id = requestCode($a)->json('request_id');
    $code = sentCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        verifyCode($a, $id, $wrong)->assertStatus(422);
    }
    verifyCode($a, $id, $code)->assertStatus(422);
    expect(ClientInfoChallenge::first()->attempts)->toBe(5)->and(ClientInfoChallenge::first()->consumed_at)->toBeNull();
});

it('expires the code after five minutes', function () {
    Mail::fake();
    Carbon::setTestNow('2026-03-01 10:00:00');
    $a = apiClient('Slow Co');
    $id = requestCode($a)->json('request_id');
    $code = sentCode();

    Carbon::setTestNow('2026-03-01 10:05:01');
    verifyCode($a, $id, $code)->assertStatus(422);
});

it('never verifies a code that was not delivered', function () {
    Mail::fake();
    $a = apiClient('Undelivered Co');
    $id = requestCode($a)->json('request_id');
    $code = sentCode();
    ClientInfoChallenge::query()->update(['sent_at' => null]);       // the email never left

    verifyCode($a, $id, $code)->assertStatus(422);
});

it('enforces the resend cooldown and an hourly limit, and a new code replaces the old one', function () {
    Mail::fake();
    Carbon::setTestNow('2026-03-01 10:00:00');
    $a = apiClient('Cool Co');
    $first = requestCode($a)->json('request_id');
    $firstCode = sentCode();
    expect(requestCode($a)->json('request_id'))->toBe($first);
    Mail::assertSent(ClientInformationCode::class, 1);

    Carbon::setTestNow('2026-03-01 10:01:30');
    $second = requestCode($a)->json('request_id');
    Mail::assertSent(ClientInformationCode::class, 2);
    expect($second)->not->toBe($first);
    verifyCode($a, $first, $firstCode)->assertStatus(422);            // the earlier code was replaced

    foreach (range(1, 6) as $i) {
        Carbon::setTestNow(now()->addSeconds(70));
        requestCode($a);
    }
    expect(ClientInfoChallenge::count())->toBe(5);                    // never more than five in an hour
});

it('keeps integrations and clients apart', function () {
    Mail::fake();
    $a = apiClient('Client A');
    $b = apiClient('Client B');
    $id = requestCode($a)->json('request_id');
    $code = sentCode();

    verifyCode($b, $id, $code, $b['client']->sb_number)->assertStatus(422);                 // another integration cannot use A's challenge
    verifyCode($b, $id, $code, $a['client']->sb_number)->assertStatus(422);                 // nor name A's client
    verifyCode($a, $id, $code, $b['client']->sb_number)->assertStatus(422);                 // A's integration cannot bind it to client B
    ClientApiIntegrations::withdraw($a['integration'], $a['client']);
    verifyCode($a, $id, $code)->assertStatus(422);                                          // access withdrawn after the code was sent
    expect(ClientInfoChallenge::first()->consumed_at)->toBeNull();
});

it('lets only one of two simultaneous correct submissions succeed', function () {
    Mail::fake();
    $a = apiClient('Race Co');
    $id = requestCode($a)->json('request_id');
    $code = sentCode();
    $challenge = ClientInfoChallenge::firstOrFail();

    // The second submission arrives after the first has consumed the code but before its own attempt was counted.
    $consume = fn () => ClientInfoChallenge::whereKey($challenge->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
    expect($consume())->toBe(1)->and($consume())->toBe(0);
    verifyCode($a, $id, $code)->assertStatus(422);
});

it('rate limits an integration', function () {
    Mail::fake();
    $a = apiClient('Limited Co');
    foreach (range(1, 20) as $i) {
        requestCode($a)->assertOk();
    }
    requestCode($a)->assertStatus(429);
});

it('never sends a code when the mail transport would only log it', function () {
    expect(ClientInformationOtp::mailIsDeliverable())->toBeTrue();   // testing environment
    $env = app()->environment();
    app()->detectEnvironment(fn () => 'production');
    config(['mail.default' => 'log']);
    expect(ClientInformationOtp::mailIsDeliverable())->toBeFalse();
    config(['mail.default' => 'smtp']);
    expect(ClientInformationOtp::mailIsDeliverable())->toBeTrue();
    app()->detectEnvironment(fn () => $env);
});

it('returns the invoices, payments, stock, sales and an honest incomplete profit for one client without changing any record', function () {
    Mail::fake();
    $s = integrationStock($this);
    $order = refundDelivered($s);
    $client = $s['client'];
    $client->update(['email' => 'owner@example.test']);
    $client->forceFill(['email_verified_at' => now()])->save();
    $made = ClientApiIntegrations::create('Shop site');
    ClientApiIntegrations::grant($made['integration'], $client);
    $a = ['client' => $client, 'integration' => $made['integration'], 'credential' => $made['credential']];

    $before = [JournalLine::count(), StockMovement::count(), ClientOrder::count(), DB::table('journal_entries')->count(), (int) JournalLine::sum('debit')];
    $id = requestCode($a)->json('request_id');
    $json = verifyCode($a, $id, sentCode())->assertOk()->json('data');
    $after = [JournalLine::count(), StockMovement::count(), ClientOrder::count(), DB::table('journal_entries')->count(), (int) JournalLine::sum('debit')];
    expect($after)->toBe($before);

    // Sales: 100 delivered at the discounted price 550.00 = 55,000.00; collected 40,000.00 is not sales.
    expect($json['sales']['delivered_value_minor'])->toBe(5_500_000)->and($json['sales']['net_sales_minor'])->toBe(5_500_000)->and($json['sales']['orders_delivered'])->toBe(1)
        ->and($json['sales']['gross_order_value_minor'])->toBe(150 * 55_000)->and($json['sales']['total_refunded_minor'])->toBe(0);
    expect($json['financial']['total_received_minor'])->toBeInt();

    // Stock: the same units are not counted twice and units are not mixed.
    $product = $json['inventory']['products']['items'][0];
    expect($product['received'])->toBe('200.00')->and($product['delivered'])->toBe('100.00')->and($product['name'])->toContain('Premium Hoodie')
        ->and($product['ownership'])->toBe('client')->and($json['inventory']['totals_by_unit'])->toHaveKey('pcs');
    expect($json['inventory']['products']['total'])->toBe($json['inventory']['products']['included']);

    // Profit is never invented: client-borne delivery and operating costs are not recorded in the ERP.
    expect($json['profitability']['status'])->toBe('incomplete')->and($json['profitability']['net_profit_minor'])->toBeNull()
        ->and($json['profitability']['missing_data'])->toContain('Client-borne delivery costs are not recorded in the ERP.')
        ->and($json['profitability']['recorded_components']['net_verified_sales_minor'])->toBe(5_500_000)
        ->and($json['profitability']['recorded_components']['cost_of_units_sold_minor'])->toBe(100 * (int) $s['line']->fresh()->approved_price)
        ->and($json['profitability']['recorded_components']['cost_of_units_sold_minor'])->toBeGreaterThan(0);

    // Nothing private leaves: no supplier, staff, customer contact or company profit data.
    $text = json_encode($json);
    foreach (['customer_phone', 'customer_name', 'supplier', 'password', 'distribution', 'partner', 'audit@', 'secret'] as $forbidden) {
        expect(strtolower($text))->not->toContain($forbidden);
    }
    expect($order->reference)->toBe('O1');
});

it('bounds the lists but totals the whole dataset', function () {
    Mail::fake();
    $a = apiClient('Big Co');
    $project = $a['client']->projects()->create(['name' => 'P', 'status' => 'active', 'currency' => config('finance.currency'), 'started_on' => '2026-01-01']);
    foreach (range(1, 30) as $i) {
        $payment = new Payment(['amount' => 1_000, 'paid_on' => '2026-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'method' => 'cash', 'reference' => 'R'.$i]);
        $payment->forceFill(['project_id' => $project->id, 'idempotency_key' => (string) Str::uuid()])->save();
    }
    $id = requestCode($a)->json('request_id');
    $data = verifyCode($a, $id, sentCode())->assertOk()->json('data');

    expect($data['financial']['payments']['included'])->toBe(25)->and($data['financial']['payments']['total'])->toBe(30)->and($data['financial']['payments']['truncated'])->toBeTrue()
        ->and($data['financial']['total_received_minor'])->toBe(30_000);
});
