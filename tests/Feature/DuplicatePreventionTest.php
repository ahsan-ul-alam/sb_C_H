<?php

use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\DuplicateSubmission;
use App\Services\Ledger;
use App\Services\SubmissionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('creates a capital entry once when the same form is submitted twice, even if a field changed between clicks', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-01-12');
    $key = (string) Str::uuid();
    $base = ['partner_id' => $co->partner('A')->id, 'amount' => '40000', 'paid_on' => '2026-01-05', 'submission_key' => $key];

    $co->post('finance', 'finance.capital.store', [...$base, 'reference' => 'BANK-1'])->assertSessionHasNoErrors();
    $again = $co->post('finance', 'finance.capital.store', [...$base, 'reference' => 'BANK-2']); // a retry the content check cannot recognise
    expect(CapitalEntry::count())->toBe(1);
    $again->assertSessionHas('status', fn ($m) => str_contains($m, 'already recorded') && str_contains($m, 'CapitalEntry #'.CapitalEntry::first()->id));
    expect(DB::table('audit_logs')->where('action', 'submission.duplicate_blocked')->count())->toBe(1);

    // A genuinely separate entry from a fresh form (new key) is accepted, even with the same amount and date.
    $co->post('finance', 'finance.capital.store', [...$base, 'submission_key' => (string) Str::uuid(), 'reference' => 'BANK-3'])->assertSessionHasNoErrors();
    expect(CapitalEntry::count())->toBe(2);
});

it('records an expense and its journal once per submission key and releases the key when the save fails', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-01-12');
    $key = (string) Str::uuid();
    $form = ['category' => 'Office rent', 'description' => 'January rent', 'amount' => '10000', 'spent_on' => '2026-01-15', 'submission_key' => $key];

    // A failed save (a direct cost without its project) rolls back, so the same form can be corrected and sent again.
    $co->post('finance', 'finance.expenses.store', [...$form, 'classification' => 'direct'])->assertSessionHas('error');
    expect(DB::table('submission_keys')->where('key', $key)->exists())->toBeFalse();

    $co->post('finance', 'finance.expenses.store', $form)->assertSessionHasNoErrors();
    $entries = JournalEntry::count();
    $co->post('finance', 'finance.expenses.store', [...$form, 'description' => 'January rent (edited)']);
    expect(Expense::count())->toBe(1)->and(JournalEntry::count())->toBe($entries);
});

it('creates an invoice once per submission key', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $project = $co->project($co->client('Client K'));
    $key = (string) Str::uuid();
    $form = ['issued_on' => '2026-02-01', 'items' => [['description' => 'Design', 'quantity' => '1', 'unit_price' => '5000']], 'submission_key' => $key];

    $co->post('finance', 'projects.invoices.store', [...$form, 'number' => 'K-1'], $project)->assertSessionHasNoErrors();
    $co->post('finance', 'projects.invoices.store', [...$form, 'number' => 'K-2'], $project); // double click that reached the server with another number
    expect(Invoice::count())->toBe(1);
});

it('claims a key only with the work, never leaves a claimed key behind, and names the existing record', function () {
    $co = SellbeesCompany::boot($this);
    $key = (string) Str::uuid();

    expect(fn () => SubmissionGuard::once($key, 'test', null, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom');
    expect(DB::table('submission_keys')->count())->toBe(0);

    $entry = SubmissionGuard::once($key, 'test', null, fn () => CapitalEntry::create(['partner_id' => $co->partner('A')->id, 'kind' => 'contribution', 'amount' => 100, 'paid_on' => '2026-01-01', 'created_by' => 1]));
    expect(fn () => SubmissionGuard::once($key, 'test', null, fn () => throw new LogicException('must not run')))->toThrow(DuplicateSubmission::class, 'CapitalEntry #'.$entry->id);

    // Without a key nothing is claimed (the content checks of the form still apply).
    expect(SubmissionGuard::once(null, 'test', null, fn () => $entry))->toBe($entry)->and(DB::table('submission_keys')->count())->toBe(1);
});

it('refuses a second journal posting from the same source and an unbalanced entry', function () {
    SellbeesCompany::boot($this);
    Ledger::post('2026-01-01', 'Once', 'test_source', 1, [['cash', null, 100, 0], ['equity.opening', null, 0, 100]], null);
    expect(fn () => Ledger::post('2026-01-01', 'Twice', 'test_source', 1, [['cash', null, 100, 0], ['equity.opening', null, 0, 100]], null))->toThrow(RuntimeException::class, 'already been posted');
    expect(fn () => Ledger::post('2026-01-01', 'Skewed', 'test_source', 2, [['cash', null, 100, 0], ['equity.opening', null, 0, 90]], null))->toThrow(RuntimeException::class, 'Unbalanced');
    expect(JournalEntry::where('source_type', 'test_source')->count())->toBe(1);
});

it('rejects an allocation to another client\'s segment, an over-allocation and an overpayment, and excludes a voided payment', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $mine = $co->project($co->client('Client M'));
    $theirs = $co->project($co->client('Client N'));
    $mySegment = $co->segment($mine, 'Brand', '10000');
    $theirSegment = $co->segment($theirs, 'Other', '10000');
    $invoice = $co->invoice($mine, [['segment_id' => $mySegment->id, 'description' => 'Brand', 'quantity' => '1', 'unit_price' => '10000']], '2026-02-01', 'M-1');

    // Allocating to a segment of a different client's project is refused; over-allocating beyond the payment, and paying more than is outstanding, too.
    $form = ['amount' => '4000', 'paid_on' => '2026-02-05', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    $co->post('finance', 'invoices.payments.store', [...$form, 'allocations' => [$theirSegment->id => '4000']], $invoice)->assertSessionHasErrors('allocations');
    $co->post('finance', 'invoices.payments.store', [...$form, 'idempotency_key' => (string) Str::uuid(), 'allocations' => [$mySegment->id => '5000']], $invoice)->assertSessionHasErrors('allocations');
    $co->post('finance', 'invoices.payments.store', [...$form, 'idempotency_key' => (string) Str::uuid(), 'amount' => '10000.01'], $invoice)->assertSessionHasErrors('amount');
    expect(Payment::count())->toBe(0);

    // The same payment sent twice is stored once; once voided it no longer counts toward the invoice.
    $co->post('finance', 'invoices.payments.store', $form, $invoice)->assertSessionHasNoErrors();
    $co->post('finance', 'invoices.payments.store', $form, $invoice);
    expect(Payment::count())->toBe(1)->and($invoice->refresh()->amount_paid)->toBe(400_000);
    $co->voidPayment(Payment::first());
    expect(Payment::count())->toBe(0)->and($invoice->refresh()->amount_paid)->toBe(0)->and(Payment::withVoided()->count())->toBe(1);
});
