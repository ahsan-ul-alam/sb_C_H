<?php

use App\Mail\ClientInformationCode;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Payment;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\ClientPortalData;
use App\Services\ClientPortalOtp;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function cpBoot($test): SellbeesCompany
{
    Carbon::setTestNow('2026-02-10 10:00:00');
    $co = SellbeesCompany::boot($test);
    $co->standingPolicy('quick_lot', '100000000');
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1'])->assertSessionHasNoErrors();

    return $co;
}

/** A client's own stock: supplier BDT 3 (internal), client BDT 5, received. */
function cpStock(SellbeesCompany $co, string $clientName, string $email, string $qty = '10'): array
{
    $co->post('clients', 'sourcing.suppliers.store', ['name' => 'Hidden Supplier '.Str::random(4), 'phone' => '017'])->assertSessionHasNoErrors();
    $supplier = Supplier::latest('id')->firstOrFail();
    $co->post('clients', 'sourcing.products.store', ['supplier_id' => $supplier->id, 'name' => 'Mug '.Str::random(3), 'reference_price' => '3'])->assertSessionHasNoErrors();
    $product = SupplierProduct::latest('id')->firstOrFail();
    $client = $co->client($clientName);
    $client->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
    $project = $co->project($client, $clientName.' Project');
    $co->post('clients', 'sourcing.lots.store', ['supplier_id' => $supplier->id, 'delivery_cost' => '0', 'submission_key' => (string) Str::uuid(), 'project_id' => $project->id, 'items' => [['product_id' => $product->id, 'quantity' => $qty, 'profit' => '2']]])->assertSessionHasNoErrors();
    $lot = SourcingLot::latest('id')->firstOrFail();
    $line = $lot->lines()->firstOrFail();
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $project->id], $lot)->assertSessionHasNoErrors();
    $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => $qty, 'arrived_on' => '2026-02-10'], $line)->assertSessionHasNoErrors();

    return ['project' => $project, 'product' => $product, 'client' => $client->refresh(), 'supplier' => $supplier];
}

function cpOrder(SellbeesCompany $co, array $s, string $ref, string $qty, array $extra = []): ClientOrder
{
    $co->post('clients', 'product.orders.store', ['project_id' => $s['project']->id, 'reference' => $ref, 'ordered_on' => '2026-02-10', 'customer_name' => 'Private Customer',
        'customer_phone' => '01711111111', 'items' => [['product_id' => $s['product']->id, 'quantity' => $qty, 'price' => '10']]])->assertSessionMissing('error');
    $o = ClientOrder::where('reference', $ref)->firstOrFail();
    foreach (['confirm', 'dispatch', 'deliver'] as $step) {
        $data = $step === 'dispatch' ? ['courier' => 'Pathao', 'tracking_reference' => 'TRK-'.$ref, 'delivery_charge' => $extra['charge'] ?? '0', 'delivery_charge_bearer' => 'client', 'delivery_charge_status' => 'courier_deducted'] : [];
        $co->post('clients', 'product.orders.'.$step, $data, $o)->assertSessionMissing('error');
    }

    return $o->refresh();
}

/** Sign a client in through the real two-step flow and return the code used. */
function cpLogin($test, Client $client): string
{
    Mail::fake();
    $test->post(route('portal.code'), ['sb_number' => $client->sb_number])->assertRedirect(route('portal.verify'));
    $code = Mail::sent(ClientInformationCode::class)->last()->code;
    $test->post(route('portal.verify.submit'), ['code' => $code])->assertRedirect(route('portal.dashboard'));

    return $code;
}

it('refuses unknown, malformed, inactive and email-less clients identically and sends nothing', function () {
    $co = cpBoot($this);
    $active = cpStock($co, 'Active Co', 'active@example.com')['client'];
    $inactive = $co->client('Inactive Co');
    $inactive->forceFill(['email' => 'gone@example.com', 'email_verified_at' => now(), 'archived_at' => now()])->save();
    $noEmail = $co->client('NoMail Co');
    Auth::forgetGuards();
    Mail::fake();

    $shapes = [];
    foreach (['SB-999999', 'garbage', $inactive->sb_number, $noEmail->sb_number] as $input) {
        $r = $this->post(route('portal.code'), ['sb_number' => $input]);
        $r->assertRedirect(route('portal.verify'));
        $shapes[] = $this->get(route('portal.verify'))->assertOk()->assertSee('If the SB ID is right');
    }
    Mail::assertNothingSent();
    expect(DB::table('client_portal_challenges')->count())->toBe(0);
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));

    $this->post(route('portal.code'), ['sb_number' => $active->sb_number]);
    Mail::assertSent(ClientInformationCode::class, 1);
    expect(DB::table('client_portal_challenges')->count())->toBe(1)->and(ClientPortalOtp::maskEmail('active@example.com'))->toBe('a*****@e*****.com');
});

it('stores only a hash, expires after five minutes, is single use, and stops after five wrong attempts', function () {
    $co = cpBoot($this);
    $client = cpStock($co, 'Otp Co', 'otp@example.com')['client'];
    Auth::forgetGuards();
    Mail::fake();
    $this->post(route('portal.code'), ['sb_number' => $client->sb_number]);
    $code = Mail::sent(ClientInformationCode::class)->last()->code;
    $row = DB::table('client_portal_challenges')->first();
    expect($row->code_hash)->not->toContain($code)->and(strlen($row->code_hash))->toBe(64)
        ->and(AuditLog::where('details', 'like', '%'.$code.'%')->count())->toBe(0);

    // five wrong attempts lock the challenge, even for the right code
    foreach (range(1, 5) as $i) {
        $this->post(route('portal.verify.submit'), ['code' => '000000'])->assertSessionHas('error');
    }
    $this->post(route('portal.verify.submit'), ['code' => $code])->assertSessionHas('error');
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));

    // a fresh challenge: expiry
    DB::table('client_portal_challenges')->update(['created_at' => now()->subMinutes(10)]);
    $this->post(route('portal.code'), ['sb_number' => $client->sb_number]);
    $code2 = Mail::sent(ClientInformationCode::class)->last()->code;
    Carbon::setTestNow(now()->addMinutes(6));
    $this->post(route('portal.verify.submit'), ['code' => $code2])->assertSessionHas('error');

    // a fresh challenge: single use (the same code cannot be replayed on the same session)
    DB::table('client_portal_challenges')->update(['created_at' => now()->subMinutes(10)]);
    $this->post(route('portal.code'), ['sb_number' => $client->sb_number]);
    $code3 = Mail::sent(ClientInformationCode::class)->last()->code;
    $binding = ClientPortalOtp::binding();
    $nonce = session('portal.challenge');
    expect(ClientPortalOtp::verify($nonce, $code3, $binding))->not->toBeNull()->and(ClientPortalOtp::verify($nonce, $code3, $binding))->toBeNull();
    // and a code is bound to the browser session that asked for it
    expect(ClientPortalOtp::verify($nonce, $code3, 'another-browser'))->toBeNull();
});

it('throttles resends and supersedes the earlier code', function () {
    $co = cpBoot($this);
    $client = cpStock($co, 'Resend Co', 'resend@example.com')['client'];
    Auth::forgetGuards();
    Mail::fake();
    $this->post(route('portal.code'), ['sb_number' => $client->sb_number]);
    $this->post(route('portal.resend'));
    Mail::assertSent(ClientInformationCode::class, 1); // inside the cooldown: no second email
    $first = Mail::sent(ClientInformationCode::class)->last()->code;

    Carbon::setTestNow(now()->addSeconds(61));
    $this->post(route('portal.resend'));
    Mail::assertSent(ClientInformationCode::class, 2);
    $second = Mail::sent(ClientInformationCode::class)->last()->code;
    $this->post(route('portal.verify.submit'), ['code' => $first])->assertSessionHas('error');
    expect($first)->not->toBe($second);
    $this->post(route('portal.verify.submit'), ['code' => $second])->assertRedirect(route('portal.dashboard')); // only the newest code works
});

it('signs in, shows the dashboard, regenerates the session, times out on inactivity and logs out', function () {
    $co = cpBoot($this);
    $client = cpStock($co, 'Session Co', 'session@example.com')['client'];
    Auth::forgetGuards();
    $before = null;
    Mail::fake();
    $this->post(route('portal.code'), ['sb_number' => $client->sb_number]);
    $before = session()->getId();
    $bindingBefore = session('portal.binding');
    $code = Mail::sent(ClientInformationCode::class)->last()->code;
    $this->post(route('portal.verify.submit'), ['code' => $code])->assertRedirect(route('portal.dashboard'));
    expect(session('portal.binding'))->toBe($bindingBefore)->and(config('session.http_only'))->toBeTrue();
    $this->get(route('portal.dashboard'))->assertOk()->assertSee('Session Co')->assertSee($client->sb_number)->assertHeader('Cache-Control');
    $this->post(route('portal.dashboard'))->assertStatus(405);

    Carbon::setTestNow(now()->addMinutes(31));
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));

    Carbon::setTestNow('2026-02-10 12:00:00');
    cpLogin($this, $client);
    $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    expect(AuditLog::where('action', 'portal.login')->count())->toBeGreaterThanOrEqual(2)->and(AuditLog::where('action', 'portal.logout')->count())->toBe(1);
});

it('never lets the public code 11126 bypass email verification, and keeps the admin test workflow staff-only', function () {
    $co = cpBoot($this);
    $client = cpStock($co, 'Preview Co', 'preview@example.com')['client'];
    config(['portal.test_code_hash' => Hash::make('11126')]);
    Auth::forgetGuards();
    Mail::fake();

    $this->post(route('portal.code'), ['sb_number' => $client->sb_number]);
    $this->post(route('portal.verify.submit'), ['code' => '11126'])->assertSessionHas('error');
    $this->post(route('portal.preview.start'), ['sb_number' => $client->sb_number, 'reason' => 'public attempt here', 'confirmation' => '11126'])->assertRedirect(route('login'));
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));

    $co->as('employee')->get(route('portal.preview.form'))->assertForbidden();
    $co->as('employee')->post(route('portal.preview.start'), ['sb_number' => $client->sb_number, 'reason' => 'staff without permission', 'confirmation' => '11126'])->assertForbidden();
    $co->as('finance')->get(route('portal.preview.form'))->assertForbidden();
});

it('lets an authorised admin start, view read-only, exit and time out a test session, with an audit trail', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'Test Target Co', 'target@example.com');
    cpOrder($co, $s, 'T1', '2', ['charge' => '2']);
    config(['portal.test_code_hash' => Hash::make('11126')]);
    $super = $co->users['super'];
    $this->actingAs($super);

    $this->post(route('portal.preview.start'), ['sb_number' => $s['client']->sb_number, 'reason' => 'owner acceptance test', 'confirmation' => 'wrong'])->assertSessionHas('error');
    expect(session('portal.client_id'))->toBeNull();
    $this->post(route('portal.preview.start'), ['sb_number' => $s['client']->sb_number, 'reason' => 'owner acceptance test', 'confirmation' => '11126'])->assertRedirect(route('portal.dashboard'));
    $this->get(route('portal.dashboard'))->assertOk()->assertSee('ADMIN TEST SESSION')->assertSee('Exit Test Session')->assertSee('Test Target Co');
    $this->post(route('portal.orders'))->assertStatus(405);

    $this->post(route('portal.preview.exit'))->assertRedirect(route('portal.preview.form'));
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    expect(AuditLog::where('action', 'portal.preview.start')->where('user_id', $super->id)->where('details', 'like', '%owner acceptance test%')->count())->toBe(1)
        ->and(AuditLog::where('action', 'portal.preview.exit')->count())->toBe(1)->and(AuditLog::where('action', 'portal.preview.refused')->count())->toBe(1);

    $this->post(route('portal.preview.start'), ['sb_number' => $s['client']->sb_number, 'reason' => 'second acceptance test', 'confirmation' => '11126']);
    Carbon::setTestNow(now()->addMinutes(16));
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.preview.form'));
    expect(AuditLog::where('action', 'portal.preview.expired')->count())->toBe(1);

    // in production the static code is never honoured
    app()->detectEnvironment(fn () => 'production');
    $this->withoutMiddleware(PreventRequestForgery::class)->post(route('portal.preview.start'), ['sb_number' => $s['client']->sb_number, 'reason' => 'production attempt', 'confirmation' => '11126'])->assertSessionHas('error');
});

it('shows each client only their own projects, lots, stock, orders, invoices and documents', function () {
    $co = cpBoot($this);
    $a = cpStock($co, 'Alpha Co', 'alpha@example.com');
    $b = cpStock($co, 'Bravo Co', 'bravo@example.com');
    cpOrder($co, $a, 'ALPHA-1', '2', ['charge' => '2']);
    cpOrder($co, $b, 'BRAVO-1', '3', ['charge' => '3']);
    $invA = $co->invoice($a['project'], [['segment_id' => $co->segment($a['project'], 'Seg A', '100')->id, 'description' => 'Goods A', 'quantity' => '1', 'unit_price' => '100']], '2026-02-10', 'INV-A');
    $invB = $co->invoice($b['project'], [['segment_id' => $co->segment($b['project'], 'Seg B', '200')->id, 'description' => 'Goods B', 'quantity' => '1', 'unit_price' => '200']], '2026-02-10', 'INV-B');
    Auth::forgetGuards();
    cpLogin($this, $a['client']);

    foreach (['portal.projects', 'portal.inventory', 'portal.orders', 'portal.invoices', 'portal.sales', 'portal.dashboard'] as $route) {
        $this->get(route($route))->assertOk()->assertDontSee('Bravo')->assertDontSee('BRAVO-1')->assertDontSee('INV-B');
    }
    $this->get(route('portal.orders'))->assertSee('ALPHA-1')->assertSee('TRK-ALPHA-1');
    $this->get(route('portal.invoices'))->assertSee('INV-A');
    $this->get(route('portal.projects', ['project' => $b['project']->id]))->assertOk()->assertDontSee('Bravo');   // an id from another client selects nothing
    $this->get(route('portal.orders', ['project' => $b['project']->id]))->assertOk()->assertDontSee('BRAVO-1');
    $this->get(route('portal.invoices.pdf', $invA->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('portal.invoices.pdf', $invB->id))->assertNotFound();
    $this->get('/client-portal/orders?client_id='.$b['client']->id.'&sb_number='.$b['client']->sb_number)->assertOk()->assertDontSee('BRAVO-1');
    expect(ClientPortalData::invoice($a['client'], $invB->id))->toBeNull();
});

it('hides internal supplier cost, supplier identity and sourcing margin, and shows only the approved client price', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'Hide Co', 'hide@example.com');
    cpOrder($co, $s, 'H1', '2', ['charge' => '2']);
    Auth::forgetGuards();
    cpLogin($this, $s['client']);

    foreach (['portal.projects', 'portal.inventory', 'portal.sales', 'portal.dashboard', 'portal.orders'] as $route) {
        $this->get(route($route))->assertOk()->assertDontSee($s['supplier']->name)->assertDontSee('Sourcing margin', false)->assertDontSee('Supplier price', false)->assertDontSee('Private Customer')->assertDontSee('01711111111');
    }
    $this->get(route('portal.projects'))->assertSee('5.00');
    $economics = ClientPortalData::economics($s['client']);
    expect(json_encode(array_keys($economics['projects'][0])))->not->toContain('sourcing_margin')->not->toContain('courier_cost')
        ->and(array_key_exists('sourcing_margin', $economics['total']))->toBeFalse();
});

it('shows the exact Mug figures and labels settlement as client-wide', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'Mug Co', 'mug@example.com', '10');
    cpOrder($co, $s, 'MUG', '10', ['charge' => '10']);
    Auth::forgetGuards();
    cpLogin($this, $s['client']);

    $e = ClientPortalData::economics($s['client']);
    expect($e['total'])->toMatchArray(['gross_sales' => 10_000, 'delivery_charges' => 1_000, 'commission' => 90, 'entitlement' => 8_910, 'investment' => 5_000, 'cost_of_sold' => 5_000, 'client_profit' => 3_910]);
    $this->get(route('portal.sales'))->assertOk()->assertSee('100.00')->assertSee('10.00')->assertSee('0.90')->assertSee('89.10')->assertSee('50.00')->assertSee('39.10')
        ->assertSee('Settlement — all your projects together', false)->assertSee('not split by project');
    $this->get(route('portal.dashboard'))->assertSee('Settlement balance')->assertSee('not split by project');
});

it('never writes a financial or stock record when pages are viewed', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'ReadOnly Co', 'ro@example.com');
    cpOrder($co, $s, 'RO1', '2', ['charge' => '2']);
    $co->invoice($s['project'], [['segment_id' => $co->segment($s['project'], 'Seg', '100')->id, 'description' => 'Goods', 'quantity' => '1', 'unit_price' => '100']], '2026-02-10', 'INV-RO');
    Auth::forgetGuards();
    cpLogin($this, $s['client']);
    $count = fn () => [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(), DB::table('stock_movements')->count(), Payment::count(), DB::table('client_orders')->count(),
        DB::table('client_settlements')->count(), DB::table('invoices')->count(), DB::table('clients')->count()];
    $before = $count();
    foreach (['portal.dashboard', 'portal.profile', 'portal.projects', 'portal.inventory', 'portal.orders', 'portal.invoices', 'portal.sales'] as $route) {
        $this->get(route($route))->assertOk();
    }
    $this->get(route('portal.profile'))->assertDontSee('id_number')->assertDontSee('KYC');
    expect($count())->toBe($before);
});
