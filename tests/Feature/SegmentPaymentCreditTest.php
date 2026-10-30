<?php

use App\Enums\InvoiceStatus;
use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Segment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $client = Client::forceCreate(['name' => 'Rahim', 'mobile' => '01711000000']);
    $this->project = Project::forceCreate(['client_id' => $client->id, 'name' => 'Project', 'currency' => 'BDT', 'started_on' => '2026-10-01']);
    $this->segment = $this->project->segments()->create(['name' => 'Onboarding', 'amount' => 1_000_000]);
});

function paySegment($test, Segment $segment, string $amount): void
{
    $test->post(route('segments.payments.store', $segment), [
        'amount' => $amount, 'paid_on' => '2026-10-29', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
}

function billSegment($test, Project $project, Segment $segment, string $number, string $price = '10000.00'): Invoice
{
    $test->post(route('projects.invoices.store', $project), [
        'number' => $number, 'issued_on' => '2026-10-29',
        'items' => [['segment_id' => $segment->id, 'description' => 'Onboarding', 'quantity' => '1', 'unit_price' => $price]],
    ])->assertSessionHasNoErrors();

    return Invoice::where('number', $number)->firstOrFail();
}

/**
 * Asserts stored figures and the figures printed on the invoice PDF view agree.
 */
function expectInvoice(Invoice $invoice, int $paid, int $balance, InvoiceStatus $status): void
{
    $invoice->refresh();
    expect($invoice->amount_paid)->toBe($paid)->and($invoice->balance())->toBe($balance)->and($invoice->status)->toBe($status);

    $invoice->load(['project.client', 'items', 'payments']);
    $html = view('invoices.pdf', ['invoice' => $invoice, 'settings' => BusinessSetting::current()])->render();
    expect($html)->toContain(Money::format($paid, 'BDT'))->toContain(Money::format($balance, 'BDT'));
}

function received(Project $project): int
{
    return $project->totals()['received'];
}

test('A/D: full segment payment before invoicing makes the invoice paid without a second payment', function () {
    paySegment($this, $this->segment, '10000');
    $invoice = billSegment($this, $this->project, $this->segment, 'INV-A');

    expectInvoice($invoice, 1_000_000, 0, InvoiceStatus::Paid);
    expect(Payment::count())->toBe(1)->and(received($this->project))->toBe(1_000_000);
});

test('B: invoice first, then segment payment credits the invoice', function () {
    $invoice = billSegment($this, $this->project, $this->segment, 'INV-B');
    expectInvoice($invoice, 0, 1_000_000, InvoiceStatus::Unpaid);

    paySegment($this, $this->segment, '4000');

    expectInvoice($invoice, 400_000, 600_000, InvoiceStatus::Partial);
    expect(Payment::count())->toBe(1)->and(received($this->project))->toBe(400_000);
});

test('C: partial segment payment then invoice payment never exceeds the total', function () {
    paySegment($this, $this->segment, '4000');
    $invoice = billSegment($this, $this->project, $this->segment, 'INV-C');
    expectInvoice($invoice, 400_000, 600_000, InvoiceStatus::Partial);

    $this->post(route('invoices.payments.store', $invoice), [
        'amount' => '6000.01', 'paid_on' => '2026-10-30', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors('amount');

    $this->post(route('invoices.payments.store', $invoice), [
        'amount' => '6000', 'paid_on' => '2026-10-30', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    expectInvoice($invoice, 1_000_000, 0, InvoiceStatus::Paid);
    expect(Payment::count())->toBe(2)->and(received($this->project))->toBe(1_000_000)->and($this->segment->receivedAmount())->toBe(1_000_000);
});

test('E: editing or deleting a segment payment after invoicing updates the invoice', function () {
    paySegment($this, $this->segment, '10000');
    $invoice = billSegment($this, $this->project, $this->segment, 'INV-E');
    $payment = Payment::sole();

    $this->put(route('payments.update', $payment), [
        'amount' => '2500', 'paid_on' => '2026-10-29', 'method' => 'cash',
    ])->assertSessionHasNoErrors();
    expectInvoice($invoice, 250_000, 750_000, InvoiceStatus::Partial);
    expect(received($this->project))->toBe(250_000);

    $this->delete(route('payments.destroy', $payment))->assertRedirect();
    expectInvoice($invoice, 0, 1_000_000, InvoiceStatus::Unpaid);
    expect(received($this->project))->toBe(0);
});

test('F: two invoices on one segment share the segment payment once, oldest first', function () {
    paySegment($this, $this->segment, '10000');
    $first = billSegment($this, $this->project, $this->segment, 'INV-F1');
    $second = billSegment($this, $this->project, $this->segment, 'INV-F2');

    expectInvoice($first, 1_000_000, 0, InvoiceStatus::Paid);
    expectInvoice($second, 0, 1_000_000, InvoiceStatus::Unpaid);
    expect(received($this->project))->toBe(1_000_000);

    // Partial payment is not credited to both invoices.
    $this->delete(route('payments.destroy', Payment::sole()))->assertRedirect();
    paySegment($this, $this->segment, '4000');
    expectInvoice($first, 400_000, 600_000, InvoiceStatus::Partial);
    expectInvoice($second, 0, 1_000_000, InvoiceStatus::Unpaid);

    // Deleting the older invoice releases the credit to the next one.
    $this->delete(route('invoices.destroy', $first))->assertRedirect();
    expectInvoice($second, 400_000, 600_000, InvoiceStatus::Partial);
});

test('G: reconcile command repairs stale invoices, is repeatable, and never touches payments', function () {
    paySegment($this, $this->segment, '10000');
    $invoice = billSegment($this, $this->project, $this->segment, 'INV-G');
    $invoice->forceFill(['amount_paid' => 0, 'status' => InvoiceStatus::Unpaid])->save();
    $paymentBefore = Payment::sole()->only(['amount', 'invoice_id', 'paid_on']);

    $this->artisan('invoices:reconcile', ['--dry-run' => true])->expectsOutputToContain('1 invoice(s) would change.')->assertSuccessful();
    expect($invoice->refresh()->amount_paid)->toBe(0);

    $this->artisan('invoices:reconcile')->expectsOutputToContain('1 invoice(s) updated.')->assertSuccessful();
    expectInvoice($invoice, 1_000_000, 0, InvoiceStatus::Paid);

    $this->artisan('invoices:reconcile')->expectsOutputToContain('0 invoice(s) updated.')->assertSuccessful();
    expect(Payment::count())->toBe(1)
        ->and(Payment::sole()->only(['amount', 'invoice_id', 'paid_on']))->toEqual($paymentBefore)
        ->and(received($this->project))->toBe(1_000_000);
});
