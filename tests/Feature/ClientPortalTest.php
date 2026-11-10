<?php

use App\Mail\ClientInformationCode;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SourcingAllocation;
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
    $this->get(route('portal.dashboard'))->assertOk()->assertSee('Admin Test Session')->assertSee('Exit Test Session')->assertSee('Test Target Co');
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

it('offers the Client Portal Preview link only to staff who hold portal.preview', function () {
    $co = cpBoot($this);
    $co->as('super')->get(route('clients.index'))->assertOk()->assertSee('Client Portal Preview')->assertSee(route('portal.preview.form'), false);
    foreach (['finance', 'clients', 'board', 'auditor'] as $who) {
        $co->as($who)->get(route('dashboard'))->assertDontSee('Client Portal Preview');
    }
});

it('shows an admin previewing a client the same stock and orders the client sees, whatever the staff project limits', function () {
    $co = cpBoot($this);
    $a = cpStock($co, 'Visible Co', 'visible@example.com');
    $b = cpStock($co, 'Other Co', 'other@example.com');
    cpOrder($co, $a, 'VIS-1', '2', ['charge' => '2']);
    $invB = $co->invoice($b['project'], [['segment_id' => $co->segment($b['project'], 'Seg B', '200')->id, 'description' => 'Goods B', 'quantity' => '1', 'unit_price' => '200']], '2026-02-10', 'INV-OTHER');
    config(['portal.test_code_hash' => Hash::make('11126')]);

    // the client's own view
    Auth::forgetGuards();
    cpLogin($this, $a['client']);
    $own = ClientPortalData::positions($a['client'])->map(fn ($r) => [$r['line']->id, $r['b']])->all();
    $ownOverview = ClientPortalData::overview($a['client']);
    expect($own)->not->toBeEmpty();
    $this->post(route('portal.logout'));

    // a project-limited staff member signed in must not shrink (or reshape) what the portal shows
    $limited = SellbeesCompany::user('limited@audit.test', 'employee');
    $limited->forceFill(['all_projects' => false])->save();
    expect($limited->accessibleProjectIds())->toBe([]);
    $this->actingAs($limited);
    expect(ClientPortalData::positions($a['client'])->map(fn ($r) => [$r['line']->id, $r['b']])->all())->toBe($own)
        ->and(ClientPortalData::overview($a['client'])['available_stock'])->toBe($ownOverview['available_stock']);
    Auth::forgetGuards();

    // the authorised Super Admin's preview of A: complete A data, nothing of B, read-only, permissions untouched
    $super = $co->users['super'];
    $permissionsBefore = $super->fresh()->roles ?? null;
    $this->actingAs($super);
    $this->post(route('portal.preview.start'), ['sb_number' => $a['client']->sb_number, 'reason' => 'checking client A view', 'confirmation' => '11126'])->assertRedirect(route('portal.dashboard'));
    $this->get(route('portal.inventory'))->assertOk()->assertSee('Mug')->assertDontSee('Other Co');
    $this->get(route('portal.orders'))->assertOk()->assertSee('VIS-1');
    $this->get(route('portal.invoices.pdf', $invB->id))->assertNotFound();
    $this->get(route('portal.projects', ['project' => $b['project']->id]))->assertOk()->assertDontSee('Other Co');
    $this->post(route('portal.invoices'))->assertStatus(405);
    expect($super->fresh()->roles ?? null)->toBe($permissionsBefore)->and($super->fresh()->can('portal.preview'))->toBeTrue();
    $this->post(route('portal.preview.exit'));
    $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
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
    $this->get(route('portal.projects', ['project' => $s['project']->id]))->assertOk()->assertSee('5.00')->assertSee('Lot ')->assertSee('Available', false);
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
    $this->get(route('portal.dashboard'))->assertSee('Settlement')->assertSee('not split by project');
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

it('renders the redesigned screens with their real sections, currency and status chips, and ships no test code in any asset', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'Design Co', 'design@example.com', '20');
    cpOrder($co, $s, 'DES-1', '10', ['charge' => '10']);
    $co->invoice($s['project'], [['segment_id' => $co->segment($s['project'], 'Seg', '300')->id, 'description' => 'Goods', 'quantity' => '1', 'unit_price' => '300']], '2026-02-10', 'INV-DES');
    Auth::forgetGuards();
    $this->get(route('portal.login'))->assertOk()->assertSee('Access Your Business Portal')->assertSee('css/portal.css', false);
    cpLogin($this, $s['client']);

    $this->get(route('portal.dashboard'))->assertOk()->assertSee('Project performance')->assertSee('Inventory status')->assertSee('Order fulfilment')->assertSee('Outstanding invoices')->assertSee('Recent orders')
        ->assertSee('BDT 100.00')->assertSee('BDT 39.10')->assertSee('BDT 89.10')->assertSee('Pending deliveries')->assertSee('INV-DES')->assertSee('aria-current="page"', false);
    $this->get(route('portal.projects'))->assertOk()->assertSee('View project')->assertSee('Design Co Project');
    $this->get(route('portal.projects', ['project' => $s['project']->id]))->assertOk()->assertSee('Sourcing lots')->assertSee('Order performance')->assertSee('Project financial summary')->assertSee('BDT 5.00')->assertSee('Available');
    $this->get(route('portal.inventory'))->assertOk()->assertSee('Available')->assertSee('Delivered')->assertSee('pt-pill', false);
    $this->get(route('portal.orders'))->assertOk()->assertSee('DES-1')->assertSee('Delivered')->assertSee('BDT 89.10');
    $this->get(route('portal.orders', ['status' => 'failed']))->assertOk()->assertSee('No orders match');
    $this->get(route('portal.invoices'))->assertOk()->assertSee('BDT 300.00')->assertSee('Unpaid');
    $this->get(route('portal.profile'))->assertOk()->assertSee('design@example.com');
    $this->get(route('portal.sales'))->assertOk()->assertSee('How your result is made up')->assertSee('not split by project');

    foreach (array_merge(glob(public_path('css/portal.css')), glob(public_path('js/portal.js')), glob(resource_path('views/portal/*.php')), glob(resource_path('views/components/portal-*.php')), glob(resource_path('views/components/portal/*.php'))) as $file) {
        expect(file_get_contents($file))->not->toContain('11126');
    }
});

/** An older-style lot: ownership never stated and the billing allocation not linked to its invoice (the records of a client who paid before billing was linked to lots). */
function cpMakeLegacy(array $s): void
{
    DB::table('sourcing_lots')->where('project_id', $s['project']->id)->update(['ownership' => null]);
    DB::table('sourcing_allocations')->where('project_id', $s['project']->id)->update(['billed_invoice_id' => null]);
}

it('A/D/I: a client who paid for received stock with an unlinked lot sees pending verification, real billing and paid amounts, and stock awaiting confirmation, never a false zero', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'Legacy Co', 'legacy@example.com', '10');
    $invoice = Invoice::where('project_id', $s['project']->id)->firstOrFail();
    $co->payInvoice($invoice, '50', '2026-02-10')->assertSessionHasNoErrors();
    // a historical payment recorded against a segment (no invoice_id on the payment) must still count
    $seg = $co->segment($s['project'], 'Old work', '100');
    $old = $co->invoice($s['project'], [['segment_id' => $seg->id, 'description' => 'Old work', 'quantity' => '1', 'unit_price' => '100']], '2026-02-10', 'INV-OLD');
    $co->paySegment($seg, '100', '2026-02-10')->assertSessionHasNoErrors();
    cpMakeLegacy($s);
    Auth::forgetGuards();

    $b = ClientPortalData::productBilling($s['client']);
    $paidOnInvoices = (int) Invoice::where('project_id', $s['project']->id)->sum('amount_paid');
    expect($b['billed'])->toBe(0)->and($b['other']['paid'])->toBe($paidOnInvoices)->and($paidOnInvoices)->toBeGreaterThanOrEqual(15_000)
        ->and($b['awaiting_count'])->toBe(1)->and($b['awaiting_value'])->toBe(5_000)
        ->and($b['awaiting'][0]['reasons'])->toContain('Ownership of this stock is still being confirmed')->toContain('Not yet billed to you against this lot');
    $o = ClientPortalData::overview($s['client']);
    expect($o['economics']['investment'])->toBe(0)->and($o['stock']['available'])->toBe('0')->and($o['stock_awaiting']['available'])->toBe('10.00')
        ->and($o['sourced_products'])->toBe(1)->and($o['successful_sales'])->toBe(0)->and($o['client_wide']['entitlement'])->toBe(0)->and($o['economics']['client_profit'])->toBe(0);

    cpLogin($this, $s['client']);
    $this->get(route('portal.dashboard'))->assertOk()->assertSee('Pending verification')->assertSee('Product billing')->assertSee('Other invoices')->assertSee('INV-OLD')
        ->assertSee('awaiting ownership confirmation')->assertSee('No customer order delivered yet')->assertSee('Realised product profit');
    $this->get(route('portal.projects', ['project' => $s['project']->id]))->assertOk()->assertSee('Ownership being confirmed')->assertSee('Not yet billed against this lot');
    $this->get(route('portal.inventory'))->assertOk()->assertSee('Ownership being confirmed')->assertSee('not counted as available client stock');
});

it('B/G: a partly paid linked product invoice shows billed, verified paid and outstanding separately', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'Partial Co', 'partial@example.com', '10');
    $invoiceId = (int) SourcingAllocation::where('project_id', $s['project']->id)->whereNotNull('billed_invoice_id')->value('billed_invoice_id');
    $co->payInvoice(Invoice::findOrFail($invoiceId), '20', '2026-02-10')->assertSessionHasNoErrors();
    Auth::forgetGuards();

    $b = ClientPortalData::productBilling($s['client']);
    expect($b['billed'])->toBe(5_000)->and($b['paid'])->toBe(2_000)->and($b['outstanding'])->toBe(3_000)->and($b['awaiting_count'])->toBe(0)->and($b['mixed']['count'])->toBe(0);
    cpLogin($this, $s['client']);
    $this->get(route('portal.sales'))->assertOk()->assertSee('Product amount billed')->assertSee('BDT 50.00')->assertSee('BDT 20.00')->assertSee('BDT 30.00');
    $this->get(route('portal.dashboard'))->assertSee('Outstanding invoices')->assertDontSee('Pending verification');
});

it('C: a mixed product and service invoice is not treated as all product and its payment is not split', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'Mixed Co', 'mixed@example.com', '10');
    $segA = $co->segment($s['project'], 'Product part', '50');
    $segB = $co->segment($s['project'], 'Ads', '20');
    $mixed = $co->invoice($s['project'], [['segment_id' => $segA->id, 'description' => 'Goods', 'quantity' => '1', 'unit_price' => '50'], ['segment_id' => $segB->id, 'description' => 'Ads budget', 'quantity' => '1', 'unit_price' => '20']], '2026-02-10', 'INV-MIX');
    DB::table('sourcing_allocations')->where('project_id', $s['project']->id)->update(['billed_invoice_id' => $mixed->id]);
    $co->payInvoice($mixed->refresh(), '30', '2026-02-10')->assertSessionHasNoErrors();
    Auth::forgetGuards();

    $b = ClientPortalData::productBilling($s['client']);
    expect($b['billed'])->toBe(0)->and($b['mixed'])->toMatchArray(['count' => 1, 'product_billed' => 5_000, 'invoice_total' => 7_000, 'paid' => 3_000]);
    cpLogin($this, $s['client']);
    $this->get(route('portal.sales'))->assertOk()->assertSee('mixed invoice')->assertSee('not split by item');
});

it('E/F: delivered sales give the exact Mug figures with verified investment, and a return lowers sales without a false negative profit', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'Sold Co', 'sold@example.com', '10');
    $o = cpOrder($co, $s, 'SOLD-1', '10', ['charge' => '10']);
    Auth::forgetGuards();
    $e = ClientPortalData::economics($s['client']);
    expect($e['total'])->toMatchArray(['investment' => 5_000, 'gross_sales' => 10_000, 'delivery_charges' => 1_000, 'commission' => 90, 'entitlement' => 8_910, 'client_profit' => 3_910])
        ->and(ClientPortalData::productBilling($s['client'])['awaiting_count'])->toBe(0);
    cpLogin($this, $s['client']);
    $this->get(route('portal.dashboard'))->assertOk()->assertDontSee('Pending verification')->assertSee('BDT 39.10')->assertSee('BDT 89.10');

    $co->post('clients', 'product.orders.return', ['returned' => [$o->items()->firstOrFail()->id => '2']], $o)->assertSessionMissing('error');
    Auth::forgetGuards();
    $after = ClientPortalData::overview($s['client']);
    expect($after['successful_sales'])->toBeLessThan(10_000)->and($after['economics']['client_profit'])->toBeGreaterThan(0)->and($after['economics']['investment'])->toBe(5_000);
});

it('H: another client\'s invoices, payments and lots never enter this client\'s billing', function () {
    $co = cpBoot($this);
    $a = cpStock($co, 'Iso A', 'isoa@example.com', '10');
    $b = cpStock($co, 'Iso B', 'isob@example.com', '20');
    cpMakeLegacy($b);
    Auth::forgetGuards();
    $ba = ClientPortalData::productBilling($a['client']);
    $bb = ClientPortalData::productBilling($b['client']);
    expect($ba['awaiting_count'])->toBe(0)->and($ba['billed'])->toBe(5_000)->and($bb['awaiting_count'])->toBe(1)->and($bb['awaiting_value'])->toBe(10_000)->and($bb['billed'])->toBe(0);
    cpLogin($this, $a['client']);
    $this->get(route('portal.dashboard'))->assertDontSee('Iso B')->assertDontSee('Ownership of this stock is still being confirmed');
});

it('J: viewing the corrected screens writes no financial, billing, payment or stock record', function () {
    $co = cpBoot($this);
    $s = cpStock($co, 'NoWrite Co', 'nowrite@example.com', '10');
    cpMakeLegacy($s);
    Auth::forgetGuards();
    cpLogin($this, $s['client']);
    $tables = ['journal_entries', 'journal_lines', 'stock_movements', 'payments', 'invoices', 'invoice_items', 'sourcing_allocations', 'sourcing_lots', 'sourcing_lot_lines', 'client_orders', 'client_settlements', 'audit_logs'];
    $snap = fn () => array_map(fn ($t) => [$t, DB::table($t)->count(), md5(json_encode(DB::table($t)->orderBy('id')->get()))], array_filter($tables, fn ($t) => $t !== 'audit_logs'));
    $before = $snap();
    foreach (['portal.dashboard', 'portal.projects', 'portal.inventory', 'portal.sales', 'portal.invoices', 'portal.orders'] as $r) {
        $this->get(route($r))->assertOk();
    }
    $this->get(route('portal.projects', ['project' => $s['project']->id]))->assertOk();
    expect($snap())->toBe($before);
});
