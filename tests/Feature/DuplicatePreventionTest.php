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
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SellbeesCompany;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('creates a capital entry once when the same form is submitted twice, even if a field changed between clicks', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-01-12');
    $key = (string) Str::uuid();
    $base = ['partner_id' => $co->partner('A')->id, 'amount' => '40000', 'paid_on' => '2026-01-05', 'submission_key' => $key];

    $co->post('finance', 'finance.capital.store', [...$base, 'reference' => 'BANK-1'])->assertSessionHasNoErrors();
    // The identical form sent again is refused too (here by the field-match check, which runs first).
    $co->as('finance')->post(route('finance.capital.store'), [...$base, 'reference' => 'BANK-1'])->assertSessionHas('error');
    expect(CapitalEntry::count())->toBe(1);

    // The same key with different details is never accepted and never treated as "the same form".
    $co->post('finance', 'finance.capital.store', [...$base, 'reference' => 'BANK-2'])->assertSessionHasErrors('submission_key');
    expect(CapitalEntry::count())->toBe(1)->and(DB::table('audit_logs')->where('action', 'submission.key_reuse_refused')->count())->toBe(1);

    // Without a valid key nothing is saved at all.
    foreach ([null, 'not-a-uuid'] as $bad) {
        $co->as('finance')->post(route('finance.capital.store'), [...Arr::except($base, 'submission_key'), 'reference' => 'BANK-9', ...($bad === null ? [] : ['submission_key' => $bad])])->assertSessionHasErrors('submission_key');
    }
    expect(CapitalEntry::count())->toBe(1);

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
    $co->post('finance', 'finance.expenses.store', [...$form, 'description' => 'January rent (edited)'])->assertSessionHasErrors('submission_key');
    expect(Expense::count())->toBe(1)->and(JournalEntry::count())->toBe($entries);
});

it('creates an invoice once per submission key', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $project = $co->project($co->client('Client K'));
    $key = (string) Str::uuid();
    $form = ['issued_on' => '2026-02-01', 'items' => [['description' => 'Design', 'quantity' => '1', 'unit_price' => '5000']], 'submission_key' => $key];

    $co->post('finance', 'projects.invoices.store', [...$form, 'number' => 'K-1'], $project)->assertSessionHasNoErrors();
    $co->post('finance', 'projects.invoices.store', [...$form, 'number' => 'K-2'], $project)->assertSessionHasErrors('submission_key'); // same key, different invoice
    $co->post('finance', 'projects.invoices.store', [...$form, 'number' => 'K-3', 'submission_key' => 'nope'], $project)->assertSessionHasErrors('submission_key');
    expect(Invoice::count())->toBe(1);
});

it('claims a key only with the work, never leaves a claimed key behind, and names the existing record', function () {
    $co = SellbeesCompany::boot($this);
    $key = (string) Str::uuid();

    $payload = ['amount' => 100, 'reference' => 'CONFIDENTIAL-REF-77'];

    expect(fn () => SubmissionGuard::once($key, 'test', 1, $payload, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom');
    expect(DB::table('submission_keys')->count())->toBe(0);

    $entry = SubmissionGuard::once($key, 'test', 1, $payload, fn () => CapitalEntry::create(['partner_id' => $co->partner('A')->id, 'kind' => 'contribution', 'amount' => 100, 'paid_on' => '2026-01-01', 'created_by' => 1]));
    $again = fn () => SubmissionGuard::once($key, 'test', 1, ['reference' => 'CONFIDENTIAL-REF-77', 'amount' => 100], fn () => throw new LogicException('must not run')); // field order is irrelevant
    expect($again)->toThrow(DuplicateSubmission::class, 'CapitalEntry #'.$entry->id);

    // The audit entry names the record and the action only: no amount, reference, party or payload.
    $log = DB::table('audit_logs')->where('action', 'submission.duplicate_blocked')->first();
    expect(json_encode($log))->not->toContain('CONFIDENTIAL')->not->toContain('100')->and($log->subject)->toBe('CapitalEntry #'.$entry->id);

    // A different payload, user or action cannot use the key; a missing or malformed key is refused and nothing is claimed.
    foreach ([[1, 'test', ['amount' => 101]], [2, 'test', $payload], [1, 'other', $payload]] as [$user, $action, $data]) {
        expect(fn () => SubmissionGuard::once($key, $action, $user, $data, fn () => throw new LogicException('must not run')))->toThrow(ValidationException::class);
    }
    foreach ([null, '', 'abc'] as $bad) {
        expect(fn () => SubmissionGuard::once($bad, 'test', 1, $payload, fn () => throw new LogicException('must not run')))->toThrow(ValidationException::class);
    }
    expect(DB::table('submission_keys')->count())->toBe(1);
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
