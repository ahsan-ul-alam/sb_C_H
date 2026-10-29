<?php

use App\Enums\InvoiceStatus;
use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Segment;
use App\Models\User;
use App\Services\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('segment invoice can be partially paid then fully paid without double counting', function () {
    BusinessSetting::current()->update(['name' => 'Acme Agency', 'phone' => '01700000000']);

    // 1. Client and project (leading zeros in phone/ID numbers are preserved).
    $this->post(route('clients.store'), [
        'name' => 'Rahim Uddin',
        'mobile' => '01711000000',
        'id_type' => 'national_id',
        'id_number' => '0012345678',
    ])->assertRedirect();
    $client = Client::sole();
    expect($client->mobile)->toBe('01711000000')->and($client->id_number)->toBe('0012345678');

    $this->post(route('clients.projects.store', $client), [
        'name' => 'Business Development',
        'status' => 'active',
        'currency' => 'bdt',
        'started_on' => '2026-10-29',
    ])->assertRedirect();
    $project = Project::sole();
    expect($project->currency)->toBe('BDT');

    // 2. Segment of BDT 10,000.
    $this->post(route('projects.segments.store', $project), ['name' => 'Onboarding charge', 'amount' => '10000'])->assertRedirect();
    $segment = Segment::sole();
    expect($segment->amount)->toBe(1_000_000);

    // 3. Invoice for that segment starts unpaid with a DUE stamp.
    $this->post(route('projects.invoices.store', $project), [
        'number' => 'INV-2026-0001',
        'issued_on' => '2026-10-29',
        'purpose' => 'Onboarding',
        'items' => [['segment_id' => $segment->id, 'description' => 'Onboarding charge', 'quantity' => '1', 'unit' => 'service', 'unit_price' => '10000.00']],
    ])->assertRedirect();
    $invoice = Invoice::sole();
    expect($invoice->total)->toBe(1_000_000)
        ->and($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and($invoice->status->stamp())->toBe('DUE')
        ->and($project->totals()['charges'])->toBe(1_000_000);

    // 4. BDT 4,000 partial payment leaves BDT 6,000 and a PARTIALLY PAID stamp.
    $key = (string) Str::uuid();
    $payload = ['amount' => '4000', 'paid_on' => '2026-10-29', 'method' => 'cash', 'idempotency_key' => $key];
    $this->post(route('invoices.payments.store', $invoice), $payload)->assertRedirect();
    $this->post(route('invoices.payments.store', $invoice), $payload)->assertSessionHas('status', 'This payment was already recorded.');

    $invoice->refresh();
    expect(Payment::count())->toBe(1)
        ->and($invoice->balance())->toBe(600_000)
        ->and($invoice->status->stamp())->toBe('PARTIALLY PAID')
        ->and($segment->receivedAmount())->toBe(400_000);
    $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('PARTIALLY PAID')->assertSee('BDT 6,000.00');

    // Overpayment is rejected.
    $this->post(route('invoices.payments.store', $invoice), [
        'amount' => '6000.01', 'paid_on' => '2026-10-29', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors('amount');

    // 5. Mark as Paid records the remaining BDT 6,000; a repeated click does not add another payment.
    $markPaid = ['paid_on' => '2026-10-30', 'method' => 'bank_transfer', 'idempotency_key' => (string) Str::uuid()];
    $this->post(route('invoices.mark-paid', $invoice), $markPaid)->assertRedirect();
    $this->post(route('invoices.mark-paid', $invoice), $markPaid)->assertRedirect();

    $invoice->refresh();
    expect(Payment::count())->toBe(2)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->balance())->toBe(0)
        ->and($invoice->status->stamp())->toBe('PAID');

    // 6. Project and segment count BDT 10,000 received, once.
    $totals = $project->totals();
    expect($totals['received'])->toBe(1_000_000)
        ->and($totals['outstanding'])->toBe(0)
        ->and($totals['charges'])->toBe(1_000_000)
        ->and($segment->receivedAmount())->toBe(1_000_000);
    $this->get(route('projects.show', $project))->assertOk()->assertSee('BDT 10,000.00');
    $this->get(route('dashboard'))->assertOk()->assertSee('10,000.00');

    // 7. The PDF is generated from current data with the PAID stamp.
    $response = $this->get(route('invoices.pdf', [$invoice, 'download' => 1]));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename="INV-2026-0001.pdf"');

    $html = view('invoices.pdf', ['invoice' => $invoice->load('project.client', 'items', 'payments'), 'settings' => BusinessSetting::current()])->render();
    expect($html)->toContain('PAID')->toContain('INV-2026-0001')->toContain('Rahim Uddin')
        ->toContain('Acme Agency')->toContain('Onboarding charge')->toContain('BDT 0.00')
        ->toContain('1 service')->toContain('Onboarding')->toContain('Multiple methods');

    // Removing payments steps back to PARTIALLY PAID, then DUE once nothing is paid.
    $this->delete(route('payments.destroy', $invoice->payments()->latest('id')->first()))->assertRedirect();
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Partial)
        ->and($invoice->status->stamp())->toBe('PARTIALLY PAID')
        ->and($invoice->balance())->toBe(600_000)
        ->and($segment->receivedAmount())->toBe(400_000);

    $this->delete(route('payments.destroy', $invoice->payments()->first()))->assertRedirect();
    expect($invoice->refresh()->status->stamp())->toBe('DUE')
        ->and($invoice->balance())->toBe(1_000_000);
});

test('business settings save branding and the logo doubles as the watermark', function () {
    Storage::fake('local');
    BusinessSetting::current()->update(['name' => 'Acme Agency']);

    $this->get(route('favicon'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($this->get(route('favicon'))->getContent())->toContain('>A</text>');

    $this->put(route('settings.update'), [
        'name' => 'Acme Agency', 'website' => 'www.acme.test', 'default_currency' => 'BDT', 'show_watermark' => '1',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 100),
    ])->assertRedirect(route('settings.edit'));

    $settings = BusinessSetting::current();
    expect($settings->website)->toBe('www.acme.test')
        ->and($settings->logoImage(200, 60))->toMatchArray(['width' => 200, 'height' => 50])
        ->and($settings->watermarkImage(400, 400)['src'])->toBe($settings->logoImage(1, 1)['src']);

    $favicon = $this->get(route('favicon'))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(getimagesizefromstring($favicon->getContent()))->toMatchArray([0 => 64, 1 => 64]);

    $this->put(route('settings.update'), ['name' => 'Acme Agency', 'default_currency' => 'BDT', 'show_watermark' => '0', 'remove_logo' => '1']);
    $settings->refresh();
    expect($settings->logo_path)->toBeNull()->and($settings->watermarkImage(400, 400))->toBeNull();
});

test('clients and projects with financial history cannot be deleted', function () {
    $client = Client::create(['name' => 'Karim', 'mobile' => '01800000000']);
    $project = $client->projects()->create(['name' => 'Web', 'status' => 'active', 'currency' => 'USD', 'started_on' => '2026-10-01']);
    $invoice = $project->invoices()->create(['number' => 'INV-X', 'issued_on' => '2026-10-01', 'currency' => 'USD']);

    $this->delete(route('projects.destroy', $project))->assertSessionHas('error');
    $this->delete(route('clients.destroy', $client))->assertSessionHas('error');
    expect(Project::count())->toBe(1)->and(Client::count())->toBe(1)->and($invoice->exists)->toBeTrue();
});

test('every admin screen renders', function () {
    $client = Client::create(['name' => 'Karim', 'mobile' => '01800000000']);
    $project = $client->projects()->create(['name' => 'Web', 'status' => 'active', 'currency' => 'USD', 'started_on' => '2026-10-01']);
    $segment = $project->segments()->create(['name' => 'Design', 'amount' => 50_000]);
    $invoice = $project->invoices()->create(['number' => 'INV-X', 'issued_on' => '2026-10-01', 'currency' => 'USD']);
    $invoice->items()->create(['segment_id' => $segment->id, 'description' => 'Design', 'quantity' => 1, 'unit_price' => 50_000, 'amount' => 50_000]);
    $invoice->forceFill(['total' => 50_000])->save();
    $payment = app(PaymentRecorder::class)->save(new Payment, $project, $invoice, ['amount' => 10_000, 'paid_on' => '2026-10-02', 'method' => 'cash'], null);

    $urls = [
        route('dashboard'), route('clients.index'), route('clients.index', ['q' => 'Kar']), route('clients.create'),
        route('clients.show', $client), route('clients.edit', $client), route('clients.projects.create', $client),
        route('projects.show', $project), route('projects.edit', $project), route('segments.edit', $segment),
        route('projects.invoices.create', [$project, 'segment' => $segment->id]), route('invoices.show', $invoice),
        route('invoices.edit', $invoice), route('invoices.payments.create', $invoice), route('segments.payments.create', $segment),
        route('payments.edit', $payment), route('settings.edit'), route('invoices.pdf', $invoice),
        route('dashboard', ['currency' => 'USD', 'range' => 'last_30_days']), route('dashboard', ['range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-12-31']),
        route('projects.index'), route('projects.index', ['status' => 'active', 'q' => 'Web']), route('projects.start'),
        route('invoices.index'), route('invoices.index', ['status' => 'open', 'overdue' => 1, 'client' => $client->id]),
        route('invoices.start', ['client' => $client->id]), route('payments.index', ['range' => 'this_year', 'method' => 'cash']),
        route('projects.payments.choose', $project), route('clients.show', [$client, 'tab' => 'invoices']),
        route('clients.show', [$client, 'tab' => 'payments']),
    ];

    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }

    expect($payment->allocations()->sum('amount'))->toBe(10_000);
});

test('dashboard separates period metrics, current balances and currencies', function () {
    $this->travelTo('2026-10-15');
    $client = Client::create(['name' => 'Karim', 'mobile' => '01800000000']);
    $bdt = $client->projects()->create(['name' => 'BD', 'status' => 'active', 'currency' => 'BDT', 'started_on' => '2026-09-01']);
    $usd = $client->projects()->create(['name' => 'US', 'status' => 'active', 'currency' => 'USD', 'started_on' => '2026-09-01']);
    $makeInvoice = function (Project $project, string $number, string $issued, ?string $due, int $total) {
        $invoice = $project->invoices()->create(['number' => $number, 'issued_on' => $issued, 'due_on' => $due, 'currency' => $project->currency]);
        $invoice->forceFill(['total' => $total])->save();

        return $invoice;
    };

    $september = $makeInvoice($bdt, 'B-1', '2026-09-10', '2026-09-30', 100_000); // overdue, outside period
    $makeInvoice($bdt, 'B-2', '2026-10-05', '2026-11-30', 50_000);               // in period, not overdue
    $makeInvoice($usd, 'U-1', '2026-10-05', '2026-10-01', 70_000);                // other currency
    app(PaymentRecorder::class)->save(new Payment, $bdt, $september, ['amount' => 30_000, 'paid_on' => '2026-10-02', 'method' => 'cash'], null);

    $view = $this->get(route('dashboard', ['currency' => 'BDT', 'range' => 'this_month']))->assertOk()->viewData('metrics');

    expect($view['invoiced'])->toBe(50_000)        // only B-2 was issued in October
        ->and($view['received'])->toBe(30_000)     // payment dated in October
        ->and($view['outstanding'])->toBe(120_000) // 70,000 + 50,000 current, BDT only
        ->and($view['overdue'])->toBe(70_000)      // B-1 remaining, past due
        ->and($view['overdue_count'])->toBe(1);

    $this->get(route('invoices.index', ['overdue' => 1, 'currency' => 'BDT']))->assertSee('B-1')->assertDontSee('B-2');
});

test('guests are redirected to login', function () {
    auth()->logout();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
