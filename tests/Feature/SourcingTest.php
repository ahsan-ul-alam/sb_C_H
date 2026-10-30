<?php

use App\Models\AccountingPeriod;
use App\Models\Client;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\PolicyVersion;
use App\Models\Resolution;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\EmergencyFund;
use App\Services\Ledger;
use App\Services\LotFinancials;
use App\Services\ProfitAllocator;
use App\Services\Sourcing;
use App\Services\SourcingObligations;
use App\Services\SourcingPayments;
use App\Support\Costing;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sUser(string $role): User
{
    $user = User::firstOrCreate(['email' => $role.'@src.test'], ['name' => $role, 'password' => 'password-123']);
    if ($user->wasRecentlyCreated) {
        $user->setRoles([$role]);
    }

    return $user;
}

/** A lot with one line: $units x $price (minor), sold at purchase + $profit per unit, pricing approved by the Board. */
function approvedLot(int $units = 1, int $price = 950000, int $profit = 50000): SourcingLot
{
    $finance = sUser('finance');
    $supplier = Supplier::firstOrCreate(['name' => 'Acme Wholesale']);
    $lot = Sourcing::createLot(['title' => 'Test lot'], $finance);
    Sourcing::addLine($lot, ['supplier_id' => $supplier->id, 'description' => 'Widget', 'quantity' => (string) $units, 'unit_price' => $price], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', $profit, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, sUser('board_member'));

    return $lot->refresh();
}

function seedReserve(int $amount): void
{
    Ledger::post('2026-01-01', 'seed reserve', 'test', 1, [['cash', null, $amount, 0], ['fund.emergency', null, 0, $amount]], null);
}

it('calculates landed unit cost, break-even and both pricing methods', function () {
    // 1,000 units at 3.00 + 2,000.00 of costs = 5,000.00 landed = 5.00/unit
    $landed = 300000 + 50000 + 30000 + 70000 + 30000 + 20000;
    expect($landed)->toBe(500000)
        ->and(Costing::unitCost($landed, '1000.00'))->toBe('500.0000')
        ->and(Costing::price('500.0000', 'fixed', 100))->toBe(600)
        ->and(Costing::price('500.0000', 'markup', 2000))->toBe(600)
        ->and(Costing::breakEven(1000, '3.00'))->toBe(334)
        ->and(Costing::price('333.3333', 'markup', 2000))->toBe(400);
});

it('spreads shared costs exactly across lines and shows the unit cost', function () {
    $finance = sUser('finance');
    $a = Supplier::create(['name' => 'A']);
    $b = Supplier::create(['name' => 'B']);
    $lot = Sourcing::createLot(['title' => 'Two products'], $finance);
    Sourcing::addLine($lot, ['supplier_id' => $a->id, 'description' => 'P1', 'quantity' => '100', 'unit_price' => 300], $finance);
    Sourcing::addLine($lot, ['supplier_id' => $b->id, 'description' => 'P2', 'quantity' => '50', 'unit_price' => 1000], $finance);
    Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => 1001, 'kind' => 'actual', 'incurred_on' => '2026-01-05'], $finance);

    $f = LotFinancials::for($lot->refresh());
    $shared = collect($f['lines'])->pluck('shared')->all();
    expect(array_sum($shared))->toBe(1001)
        ->and($f['landed'])->toBe(30000 + 50000 + 1001)
        ->and($f['lines'][$lot->lines[0]->id]['shared'])->toBe(array_values($shared)[0]);
});

it('keeps the supplier price snapshot when the catalog price changes', function () {
    $finance = sUser('finance');
    $supplier = Supplier::create(['name' => 'S']);
    $product = SupplierProduct::create(['supplier_id' => $supplier->id, 'name' => 'Cup', 'unit' => 'pcs', 'reference_price' => 300]);
    $lot = Sourcing::createLot(['title' => 'Cups'], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => $supplier->id, 'supplier_product_id' => $product->id, 'quantity' => '10'], $finance);

    $product->update(['reference_price' => 350]);

    expect($line->refresh()->unit_price)->toBe(300)
        ->and(LotFinancials::for($lot->refresh())['purchase'])->toBe(3000);
    $second = Sourcing::createLot(['title' => 'Later'], $finance);
    expect(Sourcing::addLine($second, ['supplier_id' => $supplier->id, 'supplier_product_id' => $product->id, 'quantity' => '1'], $finance)->unit_price)->toBe(350);
});

it('absorbs an unexpected cost below the margin without any fund coverage (scenarios A and B)', function () {
    $lot = approvedLot();
    Sourcing::addCost($lot, ['category' => 'VAT', 'amount' => 30000, 'kind' => 'actual', 'incurred_on' => '2026-01-05'], sUser('finance'));
    $f = LotFinancials::for($lot->refresh());
    expect($f['profit'])->toBe(20000)->and($f['fund_suggested'])->toBe(0)->and($f['unexpected'])->toBe(30000);

    Sourcing::addCost($lot, ['category' => 'VAT', 'amount' => 20000, 'kind' => 'actual', 'incurred_on' => '2026-01-06'], sUser('finance'));
    $f = LotFinancials::for($lot->refresh());
    expect($f['profit'])->toBe(0)->and($f['fund_suggested'])->toBe(0)->and($f['margin_absorbed'])->toBe(50000);
});

it('shows the uncovered loss and suggested coverage when the cost exceeds the margin (scenarios C and D)', function () {
    $lot = approvedLot();
    Sourcing::addCost($lot, ['category' => 'Customs', 'amount' => 80000, 'kind' => 'actual', 'incurred_on' => '2026-01-05'], sUser('finance'));
    $f = LotFinancials::for($lot->refresh());
    expect($f['revenue'])->toBe(1000000)->and($f['landed'])->toBe(1030000)->and($f['profit'])->toBe(-30000)
        ->and($f['original_margin'])->toBe(50000)->and($f['margin_absorbed'])->toBe(50000)->and($f['uncovered_loss'])->toBe(30000)
        ->and($f['fund_suggested'])->toBe(30000);

    Sourcing::addCost($lot, ['category' => 'Other', 'amount' => 70000, 'kind' => 'actual', 'incurred_on' => '2026-01-06'], sUser('finance'));
    expect(LotFinancials::for($lot->refresh()))->toMatchArray(['profit' => -100000, 'fund_suggested' => 100000]);
});

it('counts an actual cost instead of its estimate, never both', function () {
    $finance = sUser('finance');
    $lot = approvedLot();
    $estimate = Sourcing::addCost($lot, ['category' => 'Delivery', 'amount' => 10000, 'kind' => 'estimate', 'incurred_on' => '2026-01-02'], $finance);
    Sourcing::addCost($lot, ['category' => 'Delivery', 'amount' => 12000, 'kind' => 'actual', 'incurred_on' => '2026-01-03', 'replaces_id' => $estimate->id], $finance);

    $f = LotFinancials::for($lot->refresh());
    expect($f['additional'])->toBe(12000)->and($f['estimate_additional'])->toBe(10000)->and($f['estimates_open'])->toBe(0);
    expect(fn () => Sourcing::addCost($lot, ['category' => 'Delivery', 'amount' => 1, 'kind' => 'actual', 'incurred_on' => '2026-01-03', 'replaces_id' => $estimate->id], $finance))->toThrow(RuntimeException::class);
});

it('runs the fund request through Board approval, one-time utilisation and reversal', function () {
    $lot = approvedLot();
    $finance = sUser('finance');
    $board = sUser('board_member');
    Sourcing::addCost($lot, ['category' => 'Customs', 'amount' => 80000, 'kind' => 'actual', 'incurred_on' => '2026-01-05'], $finance);
    seedReserve(100000);

    expect(fn () => EmergencyFund::request($lot, 40000, 'too much', $finance))->toThrow(RuntimeException::class); // > uncovered loss
    $request = EmergencyFund::request($lot, 30000, 'VAT surprise', $finance);
    expect(Ledger::creditBalance('fund.emergency'))->toBe(100000); // nothing reserved before approval

    expect(fn () => EmergencyFund::decide($request, true, null, $finance))->toThrow(RuntimeException::class); // no self-approval
    expect(fn () => EmergencyFund::utilise($request, $finance))->toThrow(RuntimeException::class); // not approved yet
    EmergencyFund::decide($request, true, 'ok', $board);
    EmergencyFund::utilise($request->refresh(), $finance);

    expect(Ledger::creditBalance('fund.emergency'))->toBe(70000)
        ->and(JournalLine::whereIn('account', ['income', 'expense'])->count())->toBe(0); // reserve movement only
    expect(fn () => EmergencyFund::utilise($request->refresh(), $finance))->toThrow(RuntimeException::class); // never twice
    $f = LotFinancials::for($lot->refresh());
    expect($f['profit'])->toBe(-30000)->and($f['fund_utilised'])->toBe(30000)->and($f['fund_suggested'])->toBe(0); // loss is not hidden

    EmergencyFund::reverse($request->refresh(), 'client reimbursed', $finance);
    expect(Ledger::creditBalance('fund.emergency'))->toBe(100000)->and($request->refresh()->status)->toBe('reversed');
});

it('refuses approval beyond the available reserve and lets the loss stay uncovered', function () {
    $lot = approvedLot();
    Sourcing::addCost($lot, ['category' => 'Customs', 'amount' => 80000, 'kind' => 'actual', 'incurred_on' => '2026-01-05'], sUser('finance'));
    seedReserve(10000);
    $request = EmergencyFund::request($lot, 30000, 'x', sUser('finance'));

    expect(fn () => EmergencyFund::decide($request, true, null, sUser('board_member')))->toThrow(RuntimeException::class, 'Insufficient');
    EmergencyFund::decide($request->refresh(), false, 'no funds', sUser('board_member'));
    expect(Ledger::creditBalance('fund.emergency'))->toBe(10000)->and(LotFinancials::for($lot->refresh())['uncovered_loss'])->toBe(30000);
});

it('keeps supplier payable separate from cost and posts each payment as one expense', function () {
    $lot = approvedLot(units: 100, price: 500, profit: 100); // purchase 50,000
    $finance = sUser('finance');
    $supplierId = $lot->lines->first()->supplier_id;

    $first = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $supplierId, 'amount' => 30000, 'paid_on' => today()->toDateString(), 'idempotency_key' => 'k1'], $finance);
    $again = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $supplierId, 'amount' => 30000, 'paid_on' => today()->toDateString(), 'idempotency_key' => 'k1'], $finance);

    $f = LotFinancials::for($lot->refresh());
    expect($first)->not->toBeNull()->and($again)->toBeNull()
        ->and($f['landed'])->toBe(50000)->and($f['supplier_paid'])->toBe(30000)->and($f['supplier_outstanding'])->toBe(20000)
        ->and(Expense::count())->toBe(1);
    expect(fn () => SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $supplierId, 'amount' => 20001, 'paid_on' => today()->toDateString()], $finance))->toThrow(RuntimeException::class);

    SourcingPayments::voidSupplierPayment($first, 'typo', $finance);
    expect(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(50000)->and(Expense::first()->status)->toBe('rejected');
});

it('allocates only available units and bills through the existing invoice without exposing costs', function () {
    $lot = approvedLot(units: 10, price: 500, profit: 100); // client price 6.00 per unit
    $finance = sUser('finance');
    $client = Client::create(['name' => 'Client', 'mobile' => '0170']);
    $project = $client->projects()->create(['name' => 'Brand', 'status' => 'active', 'currency' => 'BDT', 'started_on' => today()]);
    $line = $lot->lines->first();

    expect(fn () => Sourcing::allocate($lot, ['project_id' => $project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '11'], $finance))->toThrow(RuntimeException::class);
    $allocation = Sourcing::allocate($lot, ['project_id' => $project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '4'], $finance);
    $invoice = Sourcing::bill($allocation, null, null, $finance);

    $item = $invoice->items()->first();
    expect($invoice->total)->toBe(2400)->and($item->unit_price)->toBe(600)->and($item->description)->toBe('Widget')
        ->and($project->segments()->first()->amount)->toBe(2400);
    expect(fn () => Sourcing::bill($allocation->refresh(), null, null, $finance))->toThrow(RuntimeException::class); // never twice
    $this->actingAs($finance)->get(route('invoices.pdf', $invoice))->assertOk(); // PDF renders
    expect(LotFinancials::for($lot->refresh())['billed'])->toBe(2400);
});

it('enforces roles, separation of duties and negative-margin approval', function () {
    $this->actingAs(sUser('auditor'))->get(route('sourcing.overview'))->assertOk();
    $this->actingAs(sUser('auditor'))->post(route('sourcing.lots.store'), ['title' => 'x', 'allocation_basis' => 'value'])->assertForbidden();
    $this->actingAs(sUser('employee'))->get(route('sourcing.overview'))->assertForbidden();

    $finance = sUser('finance');
    $supplier = Supplier::create(['name' => 'S']);
    $lot = Sourcing::createLot(['title' => 'Loss pricing'], $finance);
    Sourcing::addLine($lot, ['supplier_id' => $supplier->id, 'description' => 'W', 'quantity' => '1', 'unit_price' => 1000], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 0, [$lot->lines()->first()->id => 900], $finance); // below break-even
    Sourcing::advance($lot, $finance);

    expect(fn () => Sourcing::approvePricing($lot, $finance))->toThrow(RuntimeException::class, 'submitted'); // creator cannot approve
    $other = User::create(['name' => 'f2', 'email' => 'f2@src.test', 'password' => 'password-123']);
    $other->setRoles(['finance']);
    expect(fn () => Sourcing::approvePricing($lot, $other))->toThrow(RuntimeException::class, 'negative margin');
    Sourcing::approvePricing($lot, sUser('board_member'));
    expect($lot->refresh()->status)->toBe('approved');

    // Finance cannot decide fund requests; routes are permission-gated.
    $request = EmergencyFund::request($lot, 100, 'priced below cost', $finance); // loss = 1000 cost - 900 price
    $this->actingAs($finance)->post(route('sourcing.fund.decide', $request), ['decision' => 'approve'])->assertForbidden();
    expect($request->refresh()->status)->toBe('pending');
    expect(sUser('super_admin')->can('sourcing.fund.approve'))->toBeFalse();
});

it('settles only when payables are cleared and records an audited reopen', function () {
    $lot = approvedLot(units: 10, price: 500, profit: 100);
    $finance = sUser('finance');
    Sourcing::advance($lot, $finance);
    Sourcing::advance($lot, $finance);
    expect(fn () => Sourcing::settle($lot, $finance))->toThrow(RuntimeException::class);

    SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $lot->lines->first()->supplier_id, 'amount' => 5000, 'paid_on' => today()->toDateString()], $finance);
    Sourcing::settle($lot, $finance);
    expect($lot->refresh()->status)->toBe('settled');
    expect(fn () => Sourcing::addCost($lot, ['category' => 'x', 'amount' => 1, 'kind' => 'actual', 'incurred_on' => '2026-01-01'], $finance))->toThrow(RuntimeException::class);

    Sourcing::reopen($lot, 'late customs bill');
    expect($lot->refresh()->status)->toBe('completed');
});

it('renders every sourcing page and lot tab', function () {
    $lot = approvedLot(units: 10, price: 500, profit: 100);
    Sourcing::addCost($lot, ['category' => 'Customs', 'amount' => 9000, 'kind' => 'actual', 'incurred_on' => '2026-01-05'], sUser('finance'));
    $supplier = Supplier::first();
    $product = SupplierProduct::create(['supplier_id' => $supplier->id, 'name' => 'Cup', 'unit' => 'pcs', 'reference_price' => 300]);
    $user = sUser('finance');

    foreach (['sourcing.overview', 'sourcing.reports', 'sourcing.payments.index', 'sourcing.suppliers.index', 'sourcing.suppliers.create', 'sourcing.products.index', 'sourcing.lots.index', 'sourcing.lots.create'] as $name) {
        $this->actingAs($user)->get(route($name))->assertOk();
    }
    $this->actingAs($user)->get(route('sourcing.suppliers.show', $supplier))->assertOk();
    $this->actingAs($user)->get(route('sourcing.suppliers.edit', $supplier))->assertOk();
    $this->actingAs($user)->get(route('sourcing.products.edit', $product))->assertOk();
    foreach (['overview', 'lines', 'pricing', 'billing', 'payments', 'emergency', 'activity'] as $tab) {
        $this->actingAs($user)->get(route('sourcing.lots.show', [$lot, 'tab' => $tab]))->assertOk();
    }
    $this->actingAs($user)->get(route('sourcing.lots.show', [$lot, 'tab' => 'pricing', 'preview' => 1, 'method' => 'markup', 'value' => '20']))->assertOk();
    $this->actingAs($user)->get(route('finance.dashboard'))->assertOk();
});

/** Seeds partners/policy, signs the policy, and returns a lot with a BDT 300 loss (30000 minor) and $coverage utilised. */
function lossLotWithCoverage(int $coverage): SourcingLot
{
    signedPolicySetup();
    $lot = approvedLot(); // expected profit 500, price 10,000
    Sourcing::addCost($lot, ['category' => 'Customs', 'amount' => 80000, 'kind' => 'actual', 'incurred_on' => '2026-01-05'], sUser('finance')); // loss 300
    seedReserve(100000);
    $request = EmergencyFund::request($lot, $coverage, 'unexpected', sUser('finance'));
    EmergencyFund::decide($request, true, null, sUser('board_member'));
    EmergencyFund::utilise($request->refresh(), sUser('finance'));

    return $lot->refresh();
}

function signedPolicySetup(): void
{
    config(['admin.email' => 'admin@src.test', 'admin.password' => 'password-123']);
    test()->seed(DatabaseSeeder::class);
    PolicyVersion::first()->update(['effective_from' => '2020-01-01', 'approved_at' => now(), 'signed_reference' => 'R1', 'approved_by' => sUser('board_member')->id,
        'profit_confirmed_at' => now(), 'profit_signed_reference' => 'R2']);
}

function approveCoveragePolicy(): void
{
    Resolution::create(['type' => 'other', 'title' => SourcingObligations::COVERAGE_RESOLUTION, 'status' => 'approved',
        'requested_by' => sUser('finance')->id, 'decided_by' => sUser('board_member')->id, 'decided_at' => now()]);
}

function lossPeriod(string $month, int $loss): AccountingPeriod
{
    return AccountingPeriod::create(['month' => $month, 'income' => 0, 'expenses' => $loss, 'net_profit' => -$loss, 'status' => 'verified', 'closed_by' => sUser('finance')->id]);
}

it('applies fully approved coverage to the partner loss only after a Board resolution, keeping the loss itself', function () {
    $lot = lossLotWithCoverage(30000);
    $request = $lot->fundRequests()->first();

    // Without the Board resolution the partners still carry the whole loss.
    $plain = ProfitAllocator::prepare(lossPeriod('2026-01', 30000), sUser('finance'));
    expect($plain->lines->where('account', 'partner.capital')->sum('amount'))->toBe(30000)->and($plain->lines->where('account', 'fund.coverage')->count())->toBe(0);

    approveCoveragePolicy();
    $period = lossPeriod('2026-02', 30000);
    $allocation = ProfitAllocator::prepare($period, sUser('finance'));
    expect($allocation->lines->where('account', 'partner.capital')->sum('amount'))->toBe(0) // remaining partner burden
        ->and($allocation->lines->where('account', 'fund.coverage')->sum('amount'))->toBe(30000)
        ->and($period->refresh()->net_profit)->toBe(-30000) // accounting loss untouched
        ->and(LotFinancials::for($lot)['profit'])->toBe(-30000);

    expect(fn () => EmergencyFund::reverse($request, 'oops', sUser('finance')))->toThrow(RuntimeException::class, 'claimed'); // coverage is spoken for

    ProfitAllocator::approve($allocation, sUser('board_member'));
    ProfitAllocator::post($allocation, sUser('finance'));
    expect(Ledger::creditBalance('fund.coverage'))->toBe(0)->and(Ledger::creditBalance('fund.emergency'))->toBe(70000)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'))
        ->and(JournalLine::where('account', 'income')->count())->toBe(0); // coverage is never revenue
});

it('charges partners equally only for the loss left after partial coverage', function () {
    lossLotWithCoverage(20000); // loss 300, coverage 200 -> 100 uncovered
    approveCoveragePolicy();
    $allocation = ProfitAllocator::prepare(lossPeriod('2026-01', 30000), sUser('finance'));

    expect($allocation->lines->where('account', 'fund.coverage')->sum('amount'))->toBe(20000)
        ->and($allocation->lines->where('account', 'partner.capital')->pluck('amount')->sort()->values()->all())->toBe([3333, 3333, 3334])
        ->and((int) $allocation->lines->sum('amount'))->toBe(30000);
    expect(LotFinancials::for(SourcingLot::first())['pending_loss_after_fund'])->toBe(10000);
});

it('holds back profit allocation while sourcing obligations are unpaid or unverified', function () {
    signedPolicySetup();
    $finance = sUser('finance');
    $lot = approvedLot(units: 10, price: 500, profit: 100); // owes the supplier 5,000
    $lot->forceFill(['created_at' => '2026-01-10'])->save();
    $period = AccountingPeriod::create(['month' => '2026-01', 'income' => 100000, 'expenses' => 0, 'net_profit' => 100000, 'status' => 'verified', 'closed_by' => $finance->id]);

    expect(fn () => ProfitAllocator::prepare($period, $finance))->toThrow(RuntimeException::class, 'sourcing obligations'); // unpaid payable

    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $lot->lines->first()->supplier_id, 'amount' => 5000, 'paid_on' => today()->toDateString()], $finance);
    expect(fn () => ProfitAllocator::prepare($period, $finance))->toThrow(RuntimeException::class, 'awaiting verification'); // paid, not yet verified

    $this->actingAs(sUser('board_member'))->post(route('finance.expenses.decide', $payment->expense_id), ['decision' => 'verify']);
    expect(Expense::first()->status)->toBe('verified')->and(Ledger::debitBalance('expense'))->toBe(5000);
    expect(ProfitAllocator::prepare($period, $finance)->kind)->toBe('profit');
});

it('does not treat a payment as made when the Board rejects its expense', function () {
    $lot = approvedLot(units: 10, price: 500, profit: 100);
    $finance = sUser('finance');
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $lot->lines->first()->supplier_id, 'amount' => 2000, 'paid_on' => today()->toDateString()], $finance);
    expect(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(3000);

    $this->actingAs(sUser('board_member'))->post(route('finance.expenses.decide', $payment->expense_id), ['decision' => 'reject']);
    $f = LotFinancials::for($lot->refresh());
    expect($f['supplier_outstanding'])->toBe(5000)->and($f['landed'])->toBe(5000)->and(Ledger::debitBalance('expense'))->toBe(0)
        ->and($lot->lines->first()->supplier->payable()['outstanding'])->toBe(5000)
        ->and(Expense::count())->toBe(1);
});
