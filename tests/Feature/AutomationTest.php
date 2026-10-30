<?php

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PolicyVersion;
use App\Models\Project;
use App\Models\Resolution;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\User;
use App\Services\EmergencyFund;
use App\Services\ExpenseVerifier;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\LotFinancials;
use App\Services\OrderCommission;
use App\Services\PeriodProcessor;
use App\Services\Sourcing;
use App\Services\SourcingObligations;
use App\Services\SourcingPayments;
use App\Services\StandingPolicies;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['admin.email' => 'admin@au.test', 'admin.password' => 'password-123']);
    $this->seed(DatabaseSeeder::class);
    PolicyVersion::first()->update(['effective_from' => '2020-01-01', 'approved_at' => now(), 'signed_reference' => 'R1', 'approved_by' => auUser('board_member')->id,
        'profit_confirmed_at' => now(), 'profit_signed_reference' => 'R2']);
});

afterEach(fn () => Carbon::setTestNow());

function auUser(string $role): User
{
    $user = User::firstOrCreate(['email' => $role.'@au.test'], ['name' => $role, 'password' => 'password-123']);
    if ($user->wasRecentlyCreated) {
        $user->setRoles([$role]);
    }

    return $user;
}

/** An approved standing Board policy, exactly as the Board's resolution register would hold it. */
function auPolicy(string $key, int $amount = 0, ?string $notes = null): Resolution
{
    return Resolution::create(['type' => 'other', 'title' => StandingPolicies::title($key), 'status' => 'approved', 'amount' => $amount, 'notes' => $notes,
        'requested_by' => auUser('finance')->id, 'decided_by' => auUser('board_member')->id, 'decided_at' => now()]);
}

function auLot(bool $approved = true, int $units = 10, int $price = 500): SourcingLot
{
    $finance = auUser('finance');
    $lot = Sourcing::createLot(['title' => 'Auto lot'], $finance);
    Sourcing::addLine($lot, ['supplier_id' => Supplier::firstOrCreate(['name' => 'Acme'])->id, 'description' => 'Widget', 'quantity' => (string) $units, 'unit_price' => $price], $finance);
    if ($approved) {
        Sourcing::advance($lot, $finance);
        Sourcing::savePricing($lot, 'fixed', 100, [], $finance);
        Sourcing::advance($lot, $finance);
        Sourcing::approvePricing($lot, auUser('board_member'));
    }

    return $lot->refresh();
}

function auIncome(string $date, int $amount): void
{
    $client = Client::forceCreate(['name' => 'C'.Str::random(4), 'mobile' => '1']);
    $project = Project::forceCreate(['client_id' => $client->id, 'name' => 'P', 'currency' => 'BDT', 'started_on' => '2026-01-01']);
    Payment::forceCreate(['project_id' => $project->id, 'amount' => $amount, 'paid_on' => $date, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
}

it('verifies routine expenses automatically only inside the Board standing policy', function () {
    $finance = auUser('finance');
    $make = fn (string $category, int $amount, string $desc = 'monthly', bool $asset = false) => ExpenseVerifier::record(
        ['category' => $category, 'description' => $desc, 'amount' => $amount, 'spent_on' => today()->toDateString(), 'is_asset' => $asset], $finance);

    expect($make('Hosting', 1000, 'before policy')->status)->toBe('pending'); // no policy: nothing is automatic

    $policy = auPolicy(StandingPolicies::EXPENSES, 5000, 'Hosting, Internet');
    $auto = $make('Hosting', 1000);
    expect($auto->status)->toBe('verified')->and($auto->verified_by)->toBeNull()->and($auto->auto_policy_id)->toBe($policy->id)
        ->and(Ledger::debitBalance('expense'))->toBe(1000);
    expect($make('Hosting', 9000, 'over the limit')->status)->toBe('pending')
        ->and($make('Travel', 100)->status)->toBe('pending') // category not in the policy
        ->and($make('Hosting', 1000)->status)->toBe('pending'); // duplicate of the one above
    $asset = $make('Office equipment', 2000, 'laptop', true);
    expect($asset->status)->toBe('pending'); // capital purchases always go to the Board

    $this->actingAs(auUser('board_member'))->post(route('finance.expenses.decide', $asset), ['decision' => 'verify']);
    expect(Ledger::debitBalance('assets.equipment'))->toBe(2000)->and(Ledger::debitBalance('expense'))->toBe(1000)
        ->and(FinancialStatements::monthFigures(today()->format('Y-m'))['expenses'])->toBe(1000); // equipment is not an operating expense
    expect(FinancialStatements::position()['balanced'])->toBeTrue();
});

it('pays approved-lot suppliers automatically within the limit, and sends unexpected or unapproved items to the Board', function () {
    $finance = auUser('finance');
    $lot = auLot();
    $supplierId = $lot->lines->first()->supplier_id;
    $pay = fn ($lot, int $amount) => SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $supplierId, 'amount' => $amount, 'paid_on' => today()->toDateString()], $finance);

    expect($pay($lot, 1000)->expense->status)->toBe('pending'); // no policy yet
    auPolicy(StandingPolicies::SUPPLIER_PAYMENTS, 3000);
    expect($pay($lot, 2000)->expense->status)->toBe('verified')
        ->and($pay($lot, 2000)->expense->status)->toBe('pending'); // 2000 within the 3000 limit... but 1000 + 2000 + 2000 > payable
    $draft = auLot(approved: false);
    expect($pay($draft, 500)->expense->status)->toBe('pending'); // pricing not approved: outside the procurement scope

    $lot2 = auLot();
    Sourcing::addCost($lot2, ['category' => 'Customs', 'amount' => 700, 'kind' => 'actual', 'incurred_on' => today()->toDateString()], $finance); // unexpected
    SourcingPayments::payCost($lot2->costs()->first(), today()->toDateString(), $finance);
    expect($lot2->costs()->first()->refresh()->expense->status)->toBe('pending');
});

it('corrects a verified payment by its real economic event without inventing cash', function () {
    $finance = auUser('finance');
    $board = auUser('board_member');
    $lot = auLot(); // purchase 5,000
    $supplierId = $lot->lines->first()->supplier_id;
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $supplierId, 'amount' => 5000, 'paid_on' => today()->toDateString()], $finance);
    $this->actingAs($board)->post(route('finance.expenses.decide', $payment->expense_id), ['decision' => 'verify']);
    $decide = fn () => $this->actingAs($board)->post(route('finance.expenses.decide', Expense::whereNotNull('reverses_id')->where('status', 'pending')->first()), ['decision' => 'verify']);
    $cash = fn () => Ledger::debitBalance('cash');

    // Credit note: the supplier owes us 2,000 of credit; no cash moves and the payment stays paid.
    expect(fn () => SourcingPayments::voidSupplierPayment($payment, 'credit note', $finance, 'credit_note', 2000, ''))->toThrow(RuntimeException::class, 'reference');
    SourcingPayments::voidSupplierPayment($payment, 'credit note', $finance, 'credit_note', 2000, 'CN-1');
    expect(fn () => SourcingPayments::voidSupplierPayment($payment, 'again', $finance, 'credit_note', 100, 'CN-2'))->toThrow(RuntimeException::class, 'already awaiting');
    $decide();
    expect($cash())->toBe(-5000)->and(Ledger::debitBalance('supplier.credit'))->toBe(2000)->and(Ledger::debitBalance('expense'))->toBe(3000)
        ->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(0);

    // Real refund of 1,000: cash comes back and the payable returns for that amount.
    SourcingPayments::voidSupplierPayment($payment->refresh(), 'supplier refunded', $finance, 'refund_received', 1000, 'BANK-9');
    $decide();
    expect($cash())->toBe(-4000)->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(1000);

    expect(fn () => SourcingPayments::voidSupplierPayment($payment->refresh(), 'too much', $finance, 'recording_error', 5000))->toThrow(RuntimeException::class, 'between');
    $payment->refresh();
    expect((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'))
        ->and(Expense::where('id', $payment->expense_id)->value('status'))->toBe('verified'); // original entry untouched
    expect(FinancialStatements::position()['balanced'])->toBeTrue();
});

it('closes, verifies, allocates and posts a finished month by itself under the standing policy, once', function () {
    Carbon::setTestNow('2026-06-15');
    auIncome('2026-05-10', 100000);

    $none = PeriodProcessor::run();
    expect($none['exceptions'][0]['reason'])->toContain('No standing Board policy')->and(AccountingPeriod::count())->toBe(0);

    $policy = auPolicy(StandingPolicies::PERIOD);
    $run = PeriodProcessor::run();
    $period = AccountingPeriod::firstWhere('month', '2026-05');
    $allocation = Allocation::first();
    expect($run['exceptions'])->toBe([])->and($period->status)->toBe('verified')->and($period->closed_by)->toBeNull()->and($period->auto_policy_id)->toBe($policy->id)
        ->and($allocation->status)->toBe('posted')->and($allocation->prepared_by)->toBeNull()->and($allocation->approved_by)->toBeNull()->and($allocation->auto_policy_id)->toBe($policy->id);

    $ids = Partner::orderBy('sort')->pluck('id', 'code');
    expect(Ledger::creditBalance('fund.salary'))->toBe(20000)->and(Ledger::creditBalance('fund.office'))->toBe(5000)->and(Ledger::creditBalance('fund.emergency'))->toBe(25000)
        ->and(Ledger::creditBalance('partner.payable', $ids['A']))->toBe(20000)->and(Ledger::creditBalance('partner.payable', $ids['B']))->toBe(15000)
        ->and(Ledger::creditBalance('partner.payable', $ids['C']))->toBe(15000)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));

    $entries = JournalEntry::count();
    expect(PeriodProcessor::run()['processed'])->toBe([])->and(JournalEntry::count())->toBe($entries)->and(Allocation::count())->toBe(1); // idempotent
    expect(AuditLog::where('action', 'automation.done')->count())->toBeGreaterThan(0);
});

it('does not close a month with unverified items and does not allocate profit backed by unpaid sourcing obligations', function () {
    Carbon::setTestNow('2026-05-15');
    $lot = auLot(); // created in May, owes the supplier 5,000
    Carbon::setTestNow('2026-06-15');
    auIncome('2026-05-10', 100000);
    auPolicy(StandingPolicies::PERIOD);
    $pending = Expense::create(['category' => 'Misc', 'description' => 'receipt', 'amount' => 100, 'spent_on' => '2026-05-12', 'created_by' => auUser('finance')->id]);

    $first = PeriodProcessor::run();
    expect($first['exceptions'][0]['reason'])->toContain('unverified')->and(AccountingPeriod::count())->toBe(0);

    $pending->update(['status' => 'rejected']);
    $second = PeriodProcessor::run();
    expect(AccountingPeriod::firstWhere('month', '2026-05')->status)->toBe('verified')->and(Allocation::count())->toBe(0)
        ->and($second['exceptions'][0]['reason'])->toContain('sourcing obligations');
    $detail = SourcingObligations::details('2026-05-31');
    expect($detail)->toHaveCount(1)->and($detail[0]['who'])->toBe('Acme')->and($detail[0]['amount'])->toBe(5000)->and($detail[0]['lot'])->toBe($lot->number);

    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $lot->lines->first()->supplier_id, 'amount' => 5000, 'paid_on' => '2026-06-15'], auUser('finance'));
    expect(PeriodProcessor::run()['exceptions'][0]['reason'])->toContain('sourcing obligations'); // paid but still unverified
    $this->actingAs(auUser('board_member'))->post(route('finance.expenses.decide', $payment->expense_id), ['decision' => 'verify']);

    expect(PeriodProcessor::run()['exceptions'])->toBe([])->and(Allocation::first()->status)->toBe('posted'); // the block lifted itself
});

it('approves Emergency coverage automatically within the standing limit and releases stranded coverage only under its own policy', function () {
    $finance = auUser('finance');
    $lot = auLot(units: 1, price: 950000); // price 9,500 + 100 profit -> loss after an unexpected cost
    Sourcing::addCost($lot, ['category' => 'Customs', 'amount' => 80000, 'kind' => 'actual', 'incurred_on' => today()->toDateString()], $finance);
    Ledger::post('2026-01-01', 'seed', 'test', 1, [['cash', null, 500000, 0], ['fund.emergency', null, 0, 500000]], null);
    $loss = LotFinancials::for($lot->refresh())['uncovered_loss'];
    expect($loss)->toBeGreaterThan(0);

    $manual = EmergencyFund::request($lot, 1, 'before any policy', $finance);
    expect($manual->status)->toBe('pending'); // no policy: needs the Board
    EmergencyFund::cancel($manual, $finance);

    auPolicy(StandingPolicies::EMERGENCY, $loss);
    $req = EmergencyFund::request($lot, $loss, 'unexpected customs', $finance);
    expect($req->status)->toBe('utilised')->and($req->decided_by)->toBeNull()->and($req->utilised_by)->toBeNull()->and($req->auto_policy_id)->not->toBeNull()
        ->and(Ledger::creditBalance('fund.emergency'))->toBe(500000 - $loss);
    expect(fn () => EmergencyFund::utilise($req->refresh(), null))->toThrow(RuntimeException::class); // never twice
});

it('lists stranded coverage as an exception and releases it automatically only with the release policy', function () {
    $finance = auUser('finance');
    $lot = auLot(units: 1, price: 950000);
    Sourcing::addCost($lot, ['category' => 'Customs', 'amount' => 80000, 'kind' => 'actual', 'incurred_on' => today()->toDateString()], $finance);
    Ledger::post('2026-01-01', 'seed', 'test', 1, [['cash', null, 500000, 0], ['fund.emergency', null, 0, 500000]], null);
    $loss = LotFinancials::for($lot->refresh())['uncovered_loss'];
    auPolicy(StandingPolicies::EMERGENCY, $loss);
    $req = EmergencyFund::request($lot, $loss, 'x', $finance);
    expect($req->status)->toBe('utilised');

    AccountingPeriod::create(['month' => now()->format('Y-m'), 'income' => 5000, 'expenses' => 0, 'net_profit' => 5000, 'status' => 'verified', 'closed_by' => $finance->id]);
    expect(EmergencyFund::strandedCandidates())->toHaveCount(1)->and(EmergencyFund::releaseStranded())->toBe([]); // no release policy: stays held and visible

    auPolicy(StandingPolicies::RELEASE);
    expect(EmergencyFund::releaseStranded())->toBe([$lot->number])
        ->and($req->refresh()->status)->toBe('reversed')->and($req->reversed_by)->toBeNull()->and(Ledger::creditBalance('fund.emergency'))->toBe(500000)
        ->and(Ledger::creditBalance('fund.coverage'))->toBe(0)->and(EmergencyFund::releaseStranded())->toBe([]);
});

it('invoices commission automatically under policy and corrects an invoiced commission only while nothing is received', function () {
    $finance = auUser('finance');
    $client = Client::forceCreate(['name' => 'Shop', 'mobile' => '1']);
    $project = $client->projects()->create(['name' => 'Store', 'status' => 'active', 'currency' => 'BDT', 'started_on' => today()]);
    OrderCommission::setDefaultRate(100);
    $order = fn (string $ref) => OrderCommission::record($project, ['reference' => $ref, 'ordered_on' => today()->toDateString(), 'net_value' => 1000000], $finance);

    $manual = $order('A');
    OrderCommission::markSuccessful($manual);
    expect($manual->refresh()->billed_invoice_id)->toBeNull(); // no policy: billed by a person

    auPolicy(StandingPolicies::COMMISSION);
    $auto = $order('B');
    OrderCommission::markSuccessful($auto);
    $invoice = $auto->refresh()->invoice;
    expect($invoice->total)->toBe(10000)->and(JournalLine::count())->toBe(0);

    OrderCommission::reduceInvoiced($auto, 400000, 'partial refund');
    expect($invoice->refresh()->total)->toBe(4000)->and($auto->refresh()->commission)->toBe(4000)->and($project->segments()->sum('amount'))->toBe(4000)
        ->and(AuditLog::where('action', 'order.invoiced_commission_reduced')->exists())->toBeTrue();

    Payment::forceCreate(['project_id' => $project->id, 'invoice_id' => $invoice->id, 'amount' => 1000, 'paid_on' => today(), 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
    $invoice->refresh()->refreshPaymentStatus();
    expect(fn () => OrderCommission::reduceInvoiced($auto->refresh(), null, 'cancel'))->toThrow(RuntimeException::class, 'received');

    $third = $order('C');
    OrderCommission::markSuccessful($third);
    $invoiceId = $third->refresh()->billed_invoice_id;
    OrderCommission::reduceInvoiced($third, null, 'order cancelled');
    expect($third->refresh()->status)->toBe('cancelled')->and($third->commission)->toBe(0)->and(Invoice::find($invoiceId))->toBeNull();
});

it('lets only the Board revoke a standing policy and stops automation immediately', function () {
    auPolicy(StandingPolicies::EXPENSES, 5000, 'Hosting');
    expect(StandingPolicies::active(StandingPolicies::EXPENSES))->not->toBeNull();

    $this->actingAs(auUser('finance'))->post(route('finance.automation.revoke', StandingPolicies::EXPENSES))->assertForbidden();
    $this->actingAs(auUser('board_member'))->post(route('finance.automation.revoke', StandingPolicies::EXPENSES))->assertRedirect();
    expect(StandingPolicies::active(StandingPolicies::EXPENSES))->toBeNull();

    foreach (['finance', 'board_member', 'auditor'] as $role) {
        $this->actingAs(auUser($role))->get(route('finance.automation'))->assertOk();
    }
    $this->actingAs(auUser('employee'))->get(route('finance.automation'))->assertForbidden();
});
