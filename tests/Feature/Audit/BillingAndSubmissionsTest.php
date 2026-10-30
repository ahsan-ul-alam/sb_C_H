<?php

use App\Models\AccountingPeriod;
use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Services\FinancialStatements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('does not let a double-clicked capital or expense form create two postable records', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-01-12');
    $payload = ['partner_id' => $co->partner('A')->id, 'amount' => '40000', 'paid_on' => '2026-01-05', 'reference' => 'BANK-77'];
    $co->post('finance', 'finance.capital.store', $payload)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.capital.store', $payload)->assertSessionHas('error'); // identical second submit
    expect(CapitalEntry::count())->toBe(1);

    $expense = ['category' => 'Office rent', 'description' => 'January rent', 'amount' => '10000', 'spent_on' => '2026-01-15'];
    $co->post('finance', 'finance.expenses.store', $expense)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.expenses.store', $expense)->assertSessionHas('error');
    expect(Expense::count())->toBe(1);

    // Rejected records do not block a genuine re-entry.
    $co->post('board', 'finance.expenses.decide', ['decision' => 'reject'], Expense::first());
    $co->post('finance', 'finance.expenses.store', $expense)->assertSessionHasNoErrors();
    expect(Expense::count())->toBe(2);
});

it('credits a segment payment once across invoices, in oldest-first order, and follows edits and deletions', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $project = $co->project($co->client('Client D'));
    $segment = $co->segment($project, 'Branding', '10000');

    // Scenario B/C: two invoices bill the same segment (6,000 + 4,000); the segment is paid afterwards.
    $one = $co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Branding I', 'quantity' => '1', 'unit_price' => '6000']], '2026-02-01', 'D-1');
    $two = $co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Branding II', 'quantity' => '1', 'unit_price' => '4000']], '2026-02-02', 'D-2');
    $key = (string) Str::uuid();
    $co->paySegment($segment, '7000', '2026-02-05', $key)->assertSessionHasNoErrors();
    $co->paySegment($segment, '7000', '2026-02-05', $key); // same request twice (same idempotency key)
    expect(Payment::count())->toBe(1);
    expect($one->refresh()->amount_paid)->toBe(600_000)->and($one->status->value)->toBe('paid')
        ->and($two->refresh()->amount_paid)->toBe(100_000)->and($two->status->value)->toBe('partial'); // 7,000 credited exactly once
    expect($one->amount_paid + $two->amount_paid)->toBe(700_000);

    // Scenario D: edit the payment down to 5,000, then remove it.
    $payment = Payment::first();
    $co->as('finance')->put(route('payments.update', $payment), ['amount' => '5000', 'paid_on' => '2026-02-05', 'method' => 'cash'])->assertSessionHasNoErrors();
    expect($one->refresh()->amount_paid)->toBe(500_000)->and($two->refresh()->amount_paid)->toBe(0);
    $co->as('finance')->delete(route('payments.destroy', $payment))->assertSessionHasNoErrors();
    expect($one->refresh()->amount_paid)->toBe(0)->and($two->refresh()->status->value)->toBe('unpaid')->and(Payment::count())->toBe(0);

    // Scenario E and invalid amounts: nothing is stored.
    foreach (['0', '-50', '10000.01', 'abc'] as $bad) {
        $co->paySegment($segment, $bad, '2026-02-06')->assertSessionHasErrors();
    }
    expect(Payment::count())->toBe(0);
    $co->paySegment($segment, '10000', '2026-02-06')->assertSessionHasNoErrors();
    $co->paySegment($segment, '0.01', '2026-02-07')->assertSessionHasErrors(); // the segment is now fully paid

    // Invoice PDF renders and the figures on the invoice page equal the records.
    $this->actingAs($co->users['finance'])->get(route('invoices.pdf', $one))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($co->users['finance'])->get(route('invoices.show', $one))->assertOk()->assertSee('6,000.00');
});

it('refuses payments dated inside a closed period and reports income that equals the stored payments exactly once', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-03-05');
    $project = $co->project($co->client('Client E'));
    $segment = $co->segment($project, 'Onboarding', '5000');
    $co->paySegment($segment, '2000', '2026-02-03')->assertSessionHasNoErrors();
    $co->paySegment($segment, '1000', '2026-02-20')->assertSessionHasNoErrors();

    expect(FinancialStatements::monthFigures('2026-02')['income'])->toBe(300_000)->and(FinancialStatements::monthFigures('2026-02')['collected'])->toBe(300_000);

    $co->post('finance', 'finance.periods.close', ['month' => '2026-02'])->assertSessionHasNoErrors();
    $co->paySegment($segment, '500', '2026-02-25')->assertSessionHasErrors(); // closed month is frozen
    $co->paySegment($segment, '500', '2026-03-01')->assertSessionHasNoErrors();
    $co->post('finance', 'finance.periods.close', ['month' => '2026-02'])->assertSessionHas('error'); // closed once
    $co->post('finance', 'finance.periods.close', ['month' => '2026-03'])->assertSessionHas('error'); // current month cannot close
    expect(AccountingPeriod::count())->toBe(1)->and(AccountingPeriod::first()->income)->toBe(300_000)
        ->and(JournalLine::where('account', 'income')->sum('credit'))->toBe(300_000)->and(JournalLine::where('account', 'cash')->sum('debit'))->toBe(300_000);
});

it('keeps unauthorised users out of ClientHub, finance posting and period actions without partial changes', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $project = $co->project($co->client('Client F'));
    $segment = $co->segment($project, 'Branding', '1000');

    foreach (['employee', 'auditor', 'partnerA', 'hr'] as $who) {
        $co->post($who, 'segments.payments.store', ['amount' => '100', 'paid_on' => '2026-02-01', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()], $segment)->assertForbidden();
        $co->post($who, 'finance.capital.store', ['partner_id' => $co->partner('A')->id, 'amount' => '100', 'paid_on' => '2026-02-01'])->assertForbidden();
        $co->post($who, 'finance.expenses.store', ['category' => 'x', 'description' => 'y', 'amount' => '1', 'spent_on' => '2026-02-01'])->assertForbidden();
    }
    expect(Payment::count())->toBe(0)->and(CapitalEntry::count())->toBe(0)->and(Expense::count())->toBe(0);

    // Guests are sent to the login page; JSON callers get 401.
    auth()->logout();
    $this->post(route('finance.capital.store'), [])->assertRedirect(route('login'));
    $this->postJson(route('finance.capital.store'), [])->assertUnauthorized();

    // Validation failures arrive as structured JSON (what the AJAX layer reads).
    $this->actingAs($co->users['finance'])->postJson(route('finance.capital.store'), ['amount' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['partner_id', 'amount', 'paid_on']);
    $this->actingAs($co->users['finance'])->postJson(route('finance.expenses.store'), ['category' => 'x'])->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['description', 'amount', 'spent_on']]);

    // Multi-role user holds the UNION of finance and client management and nothing more.
    $multi = $co->users['multi'];
    expect($multi->can('clienthub'))->toBeTrue()->and($multi->can('expense.record'))->toBeTrue()->and($multi->can('sourcing.manage'))->toBeTrue()
        ->and($multi->can('expense.verify'))->toBeFalse()->and($multi->can('users.manage'))->toBeFalse()
        ->and($co->users['super']->can('sourcing.fund.approve'))->toBeFalse()->and($co->users['board']->can('sourcing.fund.approve'))->toBeTrue();
});

it('protects received money when a segment or invoice is edited or deleted afterwards', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $project = $co->project($co->client('Client H'));
    $segment = $co->segment($project, 'Branding', '5000');
    $co->paySegment($segment, '2000', '2026-02-05')->assertSessionHasNoErrors();
    $invoice = $co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Branding', 'quantity' => '1', 'unit_price' => '5000']], '2026-02-06', 'H-1');

    $co->as('finance')->put(route('segments.update', $segment), ['name' => 'Branding', 'amount' => '1999.99'])->assertSessionHasErrors();        // below what was received
    $co->as('finance')->delete(route('segments.destroy', $segment))->assertSessionHas('error');                                                    // has payments and an invoice
    $co->as('finance')->put(route('invoices.update', $invoice), ['number' => 'H-1', 'issued_on' => '2026-02-06', 'items' => [['segment_id' => $segment->id, 'description' => 'Branding', 'quantity' => '1', 'unit_price' => '1000']]])->assertSessionHasErrors('items');
    expect($segment->refresh()->amount)->toBe(500_000)->and($invoice->refresh()->total)->toBe(500_000)->and($invoice->amount_paid)->toBe(200_000);

    $co->payInvoice($invoice, '3000', '2026-02-07')->assertSessionHasNoErrors();
    $co->as('finance')->delete(route('invoices.destroy', $invoice))->assertSessionHas('error'); // an invoice with payments is kept
    expect(Invoice::count())->toBe(1)->and(Payment::sum('amount'))->toBe(500_000);
});
