<?php

use App\Models\FinancialAccount;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

uses(RefreshDatabase::class);

/*
| The staff forms that move customer money must make the financial account explicit: required, with clear errors, with the eligible (active)
| accounts listed and the choice kept after a failed submit, and never silently falling back to the default account.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->default = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $this->retired = FinancialAccount::factory()->create(['name' => 'Retired wallet']);
    $this->retired->update(['is_active' => false]);
    $project = $this->co->project($this->co->client('Client'), 'Project');
    $this->segment = $this->co->segment($project, 'Build', '100000');
});

afterEach(fn () => Carbon::setTestNow());

it('requires the account on a new payment form and lists only active accounts, with no default option', function () {
    $html = $this->co->as('finance')->get(route('segments.payments.create', $this->segment))->assertOk()->getContent();

    expect($html)->toContain('name="financial_account_id"')->and($html)->toContain('required')->and($html)->toContain('Choose the account')
        ->and($html)->toContain('Office Cash')->and($html)->toContain('City Bank')
        ->and($html)->not->toContain('Retired wallet')->and($html)->not->toContain('Default account');
});

it('shows the account error and keeps the other entries when a payment is submitted without or with a wrong account', function () {
    $input = ['amount' => '1000', 'paid_on' => '2026-11-06', 'method' => 'cash', 'reference' => 'TRX-77', 'idempotency_key' => (string) Str::uuid()];
    $form = route('segments.payments.create', $this->segment);

    // No account chosen on a real-time payment: refused with a message beside the field, and the reference typed is still there.
    $this->co->as('finance')->from($form)->post(route('segments.payments.store', $this->segment), $input)->assertRedirect($form)->assertSessionHasErrors('financial_account_id');
    $html = $this->co->as('finance')->from($form)->followingRedirects()->post(route('segments.payments.store', $this->segment), $input)->getContent();
    expect($html)->toContain('Choose the account the money was received in.')->and($html)->toContain('TRX-77')->and($html)->toContain('aria-invalid="true"');

    // An inactive account is refused too, and a good one is accepted and kept as chosen.
    $this->co->as('finance')->from($form)->post(route('segments.payments.store', $this->segment), [...$input, 'financial_account_id' => $this->retired->id])->assertSessionHasErrors('financial_account_id');
    expect(Payment::count())->toBe(0);
    $this->co->as('finance')->post(route('segments.payments.store', $this->segment), [...$input, 'financial_account_id' => $this->bank->id])->assertSessionHasNoErrors();
    expect(Payment::firstOrFail()->financial_account_id)->toBe($this->bank->id);
});

it('lets an old payment recorded without an account be edited without inventing one', function () {
    $payment = Payment::forceCreate(['project_id' => $this->segment->project_id, 'amount' => 100_000, 'paid_on' => '2026-10-15', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);

    $html = $this->co->as('finance')->get(route('payments.edit', $payment))->assertOk()->getContent();

    expect($html)->toContain('Not recorded (as before)')->and($html)->not->toContain('Default account');
});

it('requires the refund account only for a refund on the advances and refunds page', function () {
    $this->co->paySegment($this->segment, '1000', '2026-11-06')->assertSessionHasNoErrors();

    $html = $this->co->as('finance')->get(route('finance.revenue'))->assertOk()->getContent();

    expect($html)->toContain('data-refund-account')->and($html)->toContain('Refund paid from')->and($html)->toContain('Office Cash')
        ->and($html)->not->toContain('Retired wallet')->and($html)->not->toContain('default account');

    // An invalid account comes back as a visible message on that page.
    $payment = Payment::firstOrFail();
    $page = route('finance.revenue');
    $refund = ['kind' => 'refund', 'against' => 'revenue', 'amount' => '100', 'effective_on' => '2026-11-08', 'reason' => 'Returned', 'financial_account_id' => $this->retired->id];
    $this->co->as('finance')->from($page)->post(route('finance.revenue.store', $payment), $refund)->assertRedirect($page)->assertSessionHasErrors('financial_account_id');
    expect($this->co->as('finance')->from($page)->followingRedirects()->post(route('finance.revenue.store', $payment), $refund)->getContent())->toContain('role="alert"');
});
