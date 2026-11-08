<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentVoidRequest;
use App\Models\Project;
use App\Models\Segment;
use App\Services\PaymentVoids;
use App\Services\ProjectFinancialService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** One client, one project, one 10,000 segment, an invoice for it, and two payments of 4,000 and 1,234.50 against the segment. */
function voidFixture($test): array
{
    Carbon::setTestNow('2026-11-10');
    $co = SellbeesCompany::boot($test);
    $project = $co->project($co->client('Suchona Mart'));
    $segment = $co->segment($project, 'Product Sourcing', '10000');
    $invoice = $co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Goods', 'quantity' => '1', 'unit_price' => '10000']], '2026-11-01', 'V-1');
    $co->paySegment($segment, '4000', '2026-11-02')->assertSessionHasNoErrors();
    $co->paySegment($segment, '1234.50', '2026-11-04')->assertSessionHasNoErrors();
    $keep = Payment::orderBy('id')->first();
    $target = Payment::orderBy('id')->skip(1)->first();

    return [$co, $project, $segment, $invoice, $keep, $target];
}

it('voids a payment without deleting it and excludes it exactly once from every active total', function () {
    [$co, $project, $segment, $invoice, $keep, $target] = voidFixture($this);
    expect($invoice->refresh()->amount_paid)->toBe(523_450)
        ->and($project->totals()['received'])->toBe(523_450)
        ->and($segment->receivedAmount())->toBe(523_450);
    $before = Payment::withVoided()->whereKey($target->id)->first()->getAttributes();
    $allocationsBefore = PaymentAllocation::where('payment_id', $target->id)->get()->toArray();

    $request = $co->voidPayment($target);

    // Preserved: the very same row (only voided_at is stamped), its allocations, and its audit trail.
    $after = Payment::withVoided()->whereKey($target->id)->first()->getAttributes();
    expect(array_diff_assoc($after, $before))->toHaveKey('voided_at')->and(count(array_diff_assoc($after, $before)))->toBe(1)
        ->and(PaymentAllocation::where('payment_id', $target->id)->get()->toArray())->toBe($allocationsBefore)
        ->and($request->status)->toBe('approved')
        ->and(DB::table('audit_logs')->whereIn('action', ['payment.void_proposed', 'payment.void_approved'])->count())->toBe(2);

    // Excluded exactly once: invoice, project, segment, project financials, the payments list totals and the dashboard.
    expect(Payment::count())->toBe(1)->and(Payment::withVoided()->count())->toBe(2)
        ->and($invoice->refresh()->amount_paid)->toBe(400_000)->and($invoice->status->value)->toBe('partial')
        ->and($project->totals()['received'])->toBe(400_000)
        ->and($segment->receivedAmount())->toBe(400_000)
        ->and(ProjectFinancialService::for($project)['payments_received'])->toBe(400_000);
    $list = $co->as('finance')->get(route('payments.index'))->assertOk()->getContent();
    expect($list)->toContain('VOIDED')->and($list)->toContain('4,000.00');
    $co->as('finance')->get(route('clienthub.dashboard'))->assertOk()->assertDontSee('5,234.50');

    // A voided payment cannot be edited, re-voided or decided again, and the register keeps the whole history.
    $co->as('finance')->put(route('payments.update', $target->id), ['amount' => '1', 'paid_on' => '2026-11-04', 'method' => 'cash'])->assertNotFound();
    $co->post('finance', 'payments.void-requests.store', ['reason' => 'again', 'evidence' => 'x'], $target->id)->assertNotFound();
    $co->post('board', 'finance.payment-voids.decide', ['decision' => 'approve'], $request)->assertSessionHas('error');
    expect($invoice->refresh()->amount_paid)->toBe(400_000);
    $co->as('board')->get(route('finance.payment-voids'))->assertOk()->assertSee('Suchona Mart')->assertSee('STMT-1');
});

it('never deletes a payment: the destructive route is gone and the model refuses', function () {
    [$co, , , $invoice, , $target] = voidFixture($this);

    expect(Route::has('payments.destroy'))->toBeFalse();
    expect(fn () => $target->delete())->toThrow(ValidationException::class);
    expect(Payment::withVoided()->count())->toBe(2);
    $this->actingAs(SellbeesCompany::user('x@test.local', 'finance'))->delete('/payments/'.$target->id)->assertStatus(405);

    // Even after a void, the invoice the payment was made against stays undeletable: the history must stay attached to something.
    $co->payInvoice($invoice, '100', '2026-11-05')->assertSessionHasNoErrors();
    $onInvoice = Payment::where('invoice_id', $invoice->id)->sole();
    approvedPaymentVoid($onInvoice);
    expect($invoice->payments()->count())->toBe(0);
    $this->actingAs(SellbeesCompany::user('y@test.local', 'finance'))->delete(route('invoices.destroy', $invoice))->assertSessionHas('error');
    expect(Invoice::whereKey($invoice->id)->exists())->toBeTrue()->and(Payment::withVoided()->count())->toBe(3);
});

it('enforces separation of duties: Finance proposes, a different Board member decides', function () {
    [$co, , , , , $target] = voidFixture($this);
    $data = ['reason' => 'Duplicate entry', 'evidence' => 'bKash TRX-77'];

    foreach (['employee', 'clients', 'auditor', 'board', 'hr'] as $who) {
        $co->post($who, 'payments.void-requests.store', $data, $target)->assertForbidden();
    }
    $co->post('finance', 'payments.void-requests.store', ['reason' => 'Duplicate entry'], $target)->assertSessionHasErrors('evidence');   // evidence is mandatory
    $co->post('finance', 'payments.void-requests.store', ['evidence' => 'bKash TRX-77'], $target)->assertSessionHasErrors('reason');      // so is the reason
    expect(PaymentVoidRequest::count())->toBe(0);

    $co->post('finance', 'payments.void-requests.store', $data, $target)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $request = PaymentVoidRequest::sole();
    expect($request->status)->toBe('pending')->and(Payment::count())->toBe(2);   // proposing changes nothing

    foreach (['finance', 'super', 'clients', 'employee', 'auditor', 'multi'] as $who) {
        $co->post($who, 'finance.payment-voids.decide', ['decision' => 'approve'], $request)->assertForbidden();   // Super Admin does not hold Board approvals
    }
    expect(Payment::count())->toBe(2);

    // A person holding both roles still cannot approve their own proposal.
    $both = SellbeesCompany::user('both@test.local', 'finance', 'board_member');
    $other = Payment::orderBy('id')->first();
    $this->actingAs($both)->post(route('payments.void-requests.store', $other), $data)->assertSessionHasNoErrors();
    $own = PaymentVoidRequest::where('payment_id', $other->id)->sole();
    $this->actingAs($both)->post(route('finance.payment-voids.decide', $own), ['decision' => 'approve'])->assertSessionHas('error');
    expect(Payment::count())->toBe(2)->and($own->refresh()->status)->toBe('pending');
});

it('rejecting a void keeps the payment active and allows a fresh proposal, but never two live requests', function () {
    [$co, , , $invoice, , $target] = voidFixture($this);
    $data = ['reason' => 'Duplicate entry', 'evidence' => 'TRX-1'];

    $co->post('finance', 'payments.void-requests.store', $data, $target)->assertSessionHasNoErrors();
    $co->post('finance', 'payments.void-requests.store', $data, $target)->assertSessionHas('error');   // already pending
    expect(PaymentVoidRequest::count())->toBe(1);
    expect(fn () => PaymentVoidRequest::create(['payment_id' => $target->id, 'amount' => $target->amount, 'reason' => 'x', 'evidence' => 'y',
        'requested_by' => $co->users['finance']->id, 'active_payment_id' => $target->id]))->toThrow(UniqueConstraintViolationException::class);

    $request = PaymentVoidRequest::sole();
    $co->post('board', 'finance.payment-voids.decide', ['decision' => 'reject'], $request)->assertSessionHas('error');                 // a reason is required to reject
    $co->post('board', 'finance.payment-voids.decide', ['decision' => 'reject', 'note' => 'Statement shows it was received'], $request)->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe('rejected')->and(Payment::count())->toBe(2)->and($invoice->refresh()->amount_paid)->toBe(523_450);
    $co->post('board2', 'finance.payment-voids.decide', ['decision' => 'approve'], $request)->assertSessionHas('error');                // decided once only

    $co->post('finance', 'payments.void-requests.store', $data, $target)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(PaymentVoidRequest::count())->toBe(2);
});

it('freezes a payment while its void is pending, so the Board approves what was proposed', function () {
    [$co, , , , , $target] = voidFixture($this);
    $co->post('finance', 'payments.void-requests.store', ['reason' => 'Duplicate', 'evidence' => 'TRX-2'], $target)->assertSessionHasNoErrors();

    $co->as('finance')->put(route('payments.update', $target), ['amount' => '1000', 'paid_on' => '2026-11-04', 'method' => 'cash'])->assertSessionHasErrors('amount');
    expect($target->refresh()->amount)->toBe(123_450);

    // Even if the amount somehow changed, the approval refuses.
    DB::table('payments')->where('id', $target->id)->update(['amount' => 100_000]);
    $co->post('board', 'finance.payment-voids.decide', ['decision' => 'approve'], PaymentVoidRequest::sole())->assertSessionHas('error');
    expect(Payment::count())->toBe(2);
});

it('refuses to void protected payments: journaled, adjusted, classified or in a closed period', function () {
    [$co, , , , $a, $b] = voidFixture($this);

    DB::table('journal_entries')->insert(['entry_date' => '2026-11-02', 'memo' => 'Customer payment', 'source_type' => 'payment_v1', 'source_id' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(PaymentVoids::blockers($a->refresh()))->toHaveCount(1)->and(PaymentVoids::blockers($a)[0])->toContain('ledger postings');

    DB::table('revenue_adjustments')->insert(['payment_id' => $b->id, 'kind' => 'advance', 'amount' => 1, 'effective_on' => '2026-11-04', 'created_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(implode(' ', PaymentVoids::blockers($b->refresh())))->toContain('advance, recognition or refund');

    DB::table('payments')->where('id', $b->id)->update(['paid_on' => '2026-09-15']);
    DB::table('revenue_adjustments')->delete();
    DB::table('accounting_periods')->insert(['month' => '2026-09', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(implode(' ', PaymentVoids::blockers($b->refresh())))->toContain('closed in the finance ledger');

    DB::table('receipt_classifications')->insert(['payment_id' => $b->id, 'nature' => 'sourcing', 'classified_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(implode(' ', PaymentVoids::blockers($b->refresh())))->toContain('receipt classification');

    // Through the screens: the proposal is refused and nothing changes.
    $co->post('finance', 'payments.void-requests.store', ['reason' => 'Duplicate', 'evidence' => 'TRX-3'], $a)->assertSessionHas('error');
    $co->post('finance', 'payments.void-requests.store', ['reason' => 'Duplicate', 'evidence' => 'TRX-3'], $b)->assertSessionHas('error');
    expect(PaymentVoidRequest::count())->toBe(0)->and(Payment::count())->toBe(2);
});

it('re-checks the guards at approval time: a posting that appeared after the proposal blocks the void', function () {
    [$co, , , , , $target] = voidFixture($this);
    $co->post('finance', 'payments.void-requests.store', ['reason' => 'Duplicate', 'evidence' => 'TRX-4'], $target)->assertSessionHasNoErrors();
    DB::table('journal_entries')->insert(['entry_date' => '2026-11-04', 'memo' => 'Customer payment', 'source_type' => 'payment_v1', 'source_id' => $target->id, 'created_at' => now(), 'updated_at' => now()]);

    $co->post('board', 'finance.payment-voids.decide', ['decision' => 'approve'], PaymentVoidRequest::sole())->assertSessionHas('error');
    expect(Payment::count())->toBe(2)->and(PaymentVoidRequest::sole()->status)->toBe('pending');
});

it('shows the segment balances on the project and payment-choice pages without the voided allocation', function () {
    [$co, $project, , , , $target] = voidFixture($this);
    $choose = fn () => $co->as('finance')->get(route('projects.payments.choose', $project))->assertOk()->getContent();
    $show = fn () => $co->as('finance')->get(route('projects.show', $project))->assertOk()->getContent();
    expect($choose())->toContain('4,765.50')->and($show())->toContain('5,234.50');

    $co->voidPayment($target);

    expect($choose())->toContain('6,000.00')->not->toContain('4,765.50')
        ->and($show())->toContain('6,000.00')->not->toContain('5,234.50');
});

it('leaves cash, invoice, segment and project totals unchanged when a void is rejected', function () {
    [$co, $project, $segment, $invoice, , $target] = voidFixture($this);
    $snapshot = fn () => [Payment::sum('amount'), $invoice->refresh()->amount_paid, $invoice->status->value, $project->totals(), $segment->receivedAmount(),
        ProjectFinancialService::for($project)['payments_received'], DB::table('journal_entries')->count()];
    $before = $snapshot();

    $co->post('finance', 'payments.void-requests.store', ['reason' => 'Duplicate', 'evidence' => 'TRX-5'], $target)->assertSessionHasNoErrors();
    expect($snapshot())->toBe($before);   // a pending proposal changes nothing either
    $co->post('board', 'finance.payment-voids.decide', ['decision' => 'reject', 'note' => 'Received'], PaymentVoidRequest::sole())->assertSessionHasNoErrors();

    expect($snapshot())->toBe($before)->and(Payment::withVoided()->whereKey($target->id)->value('voided_at'))->toBeNull();
});

it('does not record a second payment when the key of a voided payment is resubmitted', function () {
    [$co, , $segment] = voidFixture($this);
    $key = (string) Str::uuid();
    $co->paySegment($segment, '100', '2026-11-05', $key)->assertSessionHasNoErrors();
    $paid = Payment::where('idempotency_key', $key)->sole();
    $co->voidPayment($paid);

    $co->paySegment($segment, '100', '2026-11-05', $key);   // the same form submitted again
    expect(Payment::withVoided()->where('idempotency_key', $key)->count())->toBe(1)->and(Payment::count())->toBe(2);
});

it('leaves every other payment and record untouched when one payment is voided', function () {
    [$co, $project, $segment, $invoice, $keep, $target] = voidFixture($this);
    $keepRow = Payment::withVoided()->whereKey($keep->id)->first()->getAttributes();
    $projects = Project::count();
    $segments = Segment::count();

    $co->voidPayment($target);

    expect(Payment::withVoided()->whereKey($keep->id)->first()->getAttributes())->toBe($keepRow)
        ->and(Project::count())->toBe($projects)->and(Segment::count())->toBe($segments)
        ->and(DB::table('journal_entries')->count())->toBe(0);   // a void posts nothing: no journal is created or rewritten
});
