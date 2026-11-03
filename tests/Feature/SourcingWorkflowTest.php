<?php

use App\Models\AdvanceSettlement;
use App\Models\Document;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AccountingService;
use App\Services\AdvanceSettlementService;
use App\Services\ExpenseService;
use App\Services\FinancialCompleteness;
use App\Services\FinancialReportingService;
use App\Services\LotFinancials;
use App\Services\PendingApprovals;
use App\Services\ProjectFinancialOverview;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingPayments;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Day-to-day sourcing workflow under the accrual sourcing basis: shortfall payables, unpaid transport payables, refunds to the receiving
| account, the approval list, the dashboard and project overview, and a smoke test of the new screens. Amounts are paisa
| (BDT 40,000 = 4,000,000). "Today" is 2026-11-10.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'accrual',
        'finance.sourcing_accrual_from' => '2026-11-01', 'finance.sourcing_recognition_trigger' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $this->bkash = FinancialAccount::factory()->create(['name' => 'bKash', 'type' => 'mobile']);
    $this->co->invest('A', '1000000', '2026-11-02');
    foreach ([[$this->bank, '300000'], [$this->bkash, '100000']] as [$to, $amount]) {
        $this->co->post('finance', 'finance.transfers.store', ['from' => (string) $this->cash->id, 'to' => (string) $to->id, 'amount' => $amount, 'date' => '2026-11-02', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }
    $this->supplier = Supplier::create(['name' => 'Uttara Buying House']);
});

afterEach(fn () => Carbon::setTestNow());

function p7Lot(array $costs = [], int $profit = 75_000): array
{
    $finance = test()->finance;
    $lot = Sourcing::createLot(['title' => 'Lot '.Str::random(4), 'purchase_order' => 'PO-7'], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => test()->supplier->id, 'description' => 'Zipper Hoodie', 'quantity' => '10', 'unit_price' => 400_000], $finance);
    $made = [];
    foreach ($costs as [$category, $amount, $incurred]) {
        $made[] = Sourcing::addCost($lot, ['category' => $category, 'amount' => $amount, 'kind' => 'actual', 'incurred_on' => $incurred ?? '2026-11-04'], $finance);
    }
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', $profit, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);

    return [$lot->refresh(), $line->refresh(), $made];
}

function p7Verify(Expense $expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

function p7Pay(SourcingLot $lot, int $amount, ?FinancialAccount $account = null, array $extra = []): SupplierPayment
{
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => test()->supplier->id, 'amount' => $amount, 'paid_on' => '2026-11-03', 'purchase_order' => 'PO-7', 'reference' => 'PAY-'.Str::random(4),
        'evidence_reference' => 'INV-7', 'financial_account_id' => $account?->id, 'idempotency_key' => (string) Str::uuid(), ...$extra], test()->finance);
    p7Verify($payment->expense);

    return $payment->refresh()->load('expense');
}

function p7Arrive($line, string $received = '10', string $accepted = '10'): void
{
    SourcingArrival::record($line->refresh(), $received, $accepted, '2026-11-08', test()->finance, 'DN-7');
}

function p7Settle(SourcingLot $lot): Collection
{
    $settlements = AdvanceSettlementService::propose($lot, test()->supplier->id, test()->finance);
    foreach ($settlements as $s) {
        AdvanceSettlementService::decide($s, true, test()->board);
    }

    return $settlements;
}

it('records accepted goods beyond the advance as a supplier payable and settles it with one payment', function () {
    [$lot, $line] = p7Lot();
    $start = AccountingService::totalCash();
    p7Pay($lot, 3_000_000, $this->bank);   // only BDT 30,000 of the BDT 40,000 was paid in advance
    p7Arrive($line);

    $settled = p7Settle($lot);
    expect($settled->pluck('kind')->sort()->values()->all())->toBe(['advance', 'payable'])->and($settled->sum('amount'))->toBe(4_000_000)
        ->and(dr('assets.inventory'))->toBe(4_000_000)->and(dr('assets.supplier_advances'))->toBe(0)->and(dr('liabilities'))->toBe(-1_000_000)
        ->and(AccountingService::totalCash())->toBe($start - 3_000_000)               // settlement moved no cash
        ->and(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id))->toBe(1_000_000)
        ->and(FinancialReportingService::accountsPayable())->toBe(1_000_000);
    expect(AdvanceSettlementService::propose($lot, $this->supplier->id, $this->finance))->toBeEmpty(); // repeating it creates nothing

    // Paying the supplier the balance settles the payable (Dr payable / Cr cash): it is not another advance and not an expense.
    expect(fn () => SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $this->supplier->id, 'amount' => 1_000_001, 'paid_on' => '2026-11-06', 'reference' => 'X'], $this->finance))
        ->toThrow(RuntimeException::class, 'exceeds what this lot owes');
    $bank = AccountingService::balance($this->bank->id);
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $this->supplier->id, 'amount' => 1_000_000, 'paid_on' => '2026-11-06', 'reference' => 'PAY-BAL', 'financial_account_id' => $this->bank->id], $this->finance);
    expect($payment->expense->classification)->toBe('supplier_payable');
    p7Verify($payment->expense);

    expect(AccountingService::balance($this->bank->id))->toBe($bank - 1_000_000)->and(AccountingService::totalCash())->toBe($start - 4_000_000) // BDT 40,000 left exactly once in total
        ->and(dr('liabilities'))->toBe(0)->and(dr('assets.inventory'))->toBe(4_000_000)->and(dr('expense'))->toBe(0)
        ->and(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id))->toBe(0)->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(0)
        ->and(FinancialReportingService::accountsPayable())->toBe(0);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('capitalises an unpaid transport cost as a payable, pays it later once, and accepts a cost paid before the service', function () {
    [$lot, , $costs] = p7Lot([['Transportation', 200_000, null], ['Loading/unloading', 50_000, '2026-11-20']]);
    $cash = AccountingService::totalCash();

    SourcingPayments::payCost($costs[0], '2026-11-05', $this->finance, null, asPayable: true);
    p7Verify($costs[0]->refresh()->expense);
    expect(AccountingService::totalCash())->toBe($cash)->and(dr('assets.inventory'))->toBe(200_000)->and(dr('liabilities'))->toBe(-200_000)->and(dr('expense'))->toBe(0)
        ->and($costs[0]->refresh()->isPayable())->toBeTrue()->and($costs[0]->isPaid())->toBeFalse()->and(LotFinancials::for($lot)['costs_unpaid'])->toBe(250_000)
        ->and(FinancialReportingService::accountsPayable())->toBe(200_000);
    expect(fn () => SourcingPayments::payCost($costs[0], '2026-11-06', $this->finance))->toThrow(RuntimeException::class, 'already recorded as a payable')
        ->and(fn () => Sourcing::removeCost($costs[0]->refresh()))->toThrow(RuntimeException::class, 'recorded in the books');

    // Paid later through the normal expense payment: cash leaves once, inventory is unchanged, and it cannot be paid twice.
    ExpenseService::pay($costs[0]->expense, ['amount' => 200_000, 'paid_on' => '2026-11-07', 'financial_account_id' => $this->bank->id], $this->finance);
    expect(AccountingService::totalCash())->toBe($cash - 200_000)->and(dr('assets.inventory'))->toBe(200_000)->and(dr('liabilities'))->toBe(0)->and($costs[0]->refresh()->isPaid())->toBeTrue()
        ->and(FinancialReportingService::accountsPayable())->toBe(0);
    expect(fn () => ExpenseService::pay($costs[0]->expense->refresh(), ['amount' => 1, 'paid_on' => '2026-11-07', 'financial_account_id' => $this->bank->id], $this->finance))->toThrow(RuntimeException::class);

    // Transport paid before the service happens (its incurred date is later): the cost sits in inventory, never in profit.
    SourcingPayments::payCost($costs[1], '2026-11-08', $this->finance, $this->cash->id);
    p7Verify($costs[1]->refresh()->expense);
    expect(dr('assets.inventory'))->toBe(250_000)->and(dr('expense'))->toBe(0)->and(AccountingService::totalCash())->toBe($cash - 250_000);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('returns a supplier refund to the account that actually receives it', function () {
    [$lot] = p7Lot();
    $payment = p7Pay($lot, 4_000_000, $this->bank);
    [$bank, $bkash] = [AccountingService::balance($this->bank->id), AccountingService::balance($this->bkash->id)];

    SourcingPayments::voidSupplierPayment($payment, 'Supplier short-delivered', $this->finance, 'refund_received', 1_000_000, 'BKASH-TXN-1', $this->bkash->id);
    p7Verify(Expense::findOrFail($payment->refresh()->reversal_expense_id));

    expect(AccountingService::balance($this->bkash->id))->toBe($bkash + 1_000_000)->and(AccountingService::balance($this->bank->id))->toBe($bank)->and(dr('assets.supplier_advances'))->toBe(3_000_000);
});

it('lists what waits for a second person and never lets the creator approve it', function () {
    [$lot, $line] = p7Lot();
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $this->supplier->id, 'amount' => 4_000_000, 'paid_on' => '2026-11-03', 'purchase_order' => 'PO-7', 'reference' => 'R', 'evidence_reference' => 'E'], $this->finance);
    $items = collect(PendingApprovals::all());
    expect($items->pluck('group')->all())->toContain('Supplier advances')->and(PendingApprovals::count())->toBe(1);
    $item = $items->first();
    expect(PendingApprovals::canDecide($this->finance, $item))->toBeFalse()   // the creator, even holding expense rights, cannot
        ->and(PendingApprovals::canDecide($this->board, $item))->toBeTrue();

    $this->co->as('finance')->get(route('finance.approvals'))->assertOk()->assertSee('Supplier advances')->assertSee('Waiting for another approver')->assertDontSee('btn-success');
    $this->co->as('board')->get(route('finance.approvals'))->assertOk()->assertSee('Verify');

    p7Verify($payment->expense);
    p7Arrive($line);
    AdvanceSettlementService::propose($lot, $this->supplier->id, $this->finance);
    $groups = collect(PendingApprovals::all())->pluck('group')->all();
    expect($groups)->toBe(['Arrival & settlement'])->and(PendingApprovals::count())->toBe(1);
    $this->co->post('finance', 'sourcing.advances.decide', ['decision' => 'verify'], AdvanceSettlement::firstOrFail())->assertForbidden();
    $this->co->post('board', 'sourcing.advances.decide', ['decision' => 'verify'], AdvanceSettlement::firstOrFail())->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(PendingApprovals::count())->toBe(0);
});

it('shows each project\'s figures separately and a dashboard fed by the ledger', function () {
    $co = $this->co;
    [$lot, $line, $costs] = p7Lot([['Transportation', 200_000, null], ['Loading/unloading', 50_000, null]]);
    $project = $co->project($co->client('Client'), 'Project');
    $allocation = Sourcing::allocate($lot, ['project_id' => $project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '10'], $this->finance);
    $invoice = Sourcing::bill($allocation, null, null, $this->finance);
    $co->payInvoice($invoice->refresh(), '50000.00', '2026-11-10')->assertSessionHasNoErrors();
    p7Pay($lot, 4_000_000, $this->bank);
    foreach ($costs as $cost) {
        SourcingPayments::payCost($cost, '2026-11-05', $this->finance, $this->cash->id);
        p7Verify($cost->refresh()->expense);
    }

    $o = ProjectFinancialOverview::for($project);
    expect($o['agreed'])->toBe(5_000_000)->and($o['invoiced'])->toBe(5_000_000)->and($o['received'])->toBe(5_000_000)->and($o['client_outstanding'])->toBe(0)
        ->and($o['supplier_advances_paid'])->toBe(4_000_000)->and($o['purchase_cost'])->toBe(4_000_000)->and($o['handling_cost'])->toBe(250_000)->and($o['acquisition_cost'])->toBe(4_250_000)
        ->and($o['actual_margin'])->toBe(750_000)->and($o['cash_surplus'])->toBe(750_000)->and($o['recognised_margin'])->toBe(0)->and($o['margin_status'])->toBe('provisional');
    $co->as('finance')->get(route('sourcing.projects.show', $project))->assertOk()->assertSee('Client payments received')->assertSee('Actual sourcing margin (provisional)')->assertSee('Project cash surplus');

    $from = CarbonImmutable::parse('2026-11-01');
    $kpis = FinancialReportingService::kpis($from, $from->endOfMonth());
    expect($kpis['supplier_advances'])->toBe(dr('assets.supplier_advances'))->and($kpis['supplier_advances'])->toBe(4_000_000)->and($kpis['inventory'])->toBe(dr('assets.inventory'))
        ->and($kpis['customer_advances'])->toBe(0)->and($kpis['pending_approvals'])->toBe(0);
    $co->as('finance')->get(route('finance.overview'))->assertOk()->assertSee('Supplier advances (asset)')->assertSee('Product sourcing margin')->assertSee('Cash by account type')->assertSee('Inventory / acquisition cost held');
});

it('runs the lot workflow end to end through the screens: create, receive, document, status, pay', function () {
    Storage::fake();
    $co = $this->co;
    $project = $co->project($co->client('Screen client'), 'Screen project');

    $co->post('finance', 'sourcing.lots.store', ['title' => 'Screen lot', 'allocation_basis' => 'value', 'purchase_order' => 'PO-SCREEN', 'project_id' => $project->id])->assertSessionHasNoErrors();
    $lot = SourcingLot::where('title', 'Screen lot')->firstOrFail();
    expect($lot->purchase_order)->toBe('PO-SCREEN')->and($lot->project_id)->toBe($project->id)->and($lot->procurement_status)->toBe('ordered');
    $co->post('finance', 'sourcing.lots.lines.store', ['supplier_id' => (string) $this->supplier->id, 'description' => 'Hoodie', 'quantity' => '10', 'unit_price' => '4000.00'], $lot)->assertSessionHasNoErrors();
    $line = $lot->lines()->firstOrFail();

    $co->post('finance', 'sourcing.lots.procurement', ['procurement_status' => 'in_transit'], $lot)->assertSessionHasNoErrors();
    expect($lot->refresh()->procurement_status)->toBe('in_transit');
    $co->post('finance', 'sourcing.lots.procurement', ['procurement_status' => 'bogus'], $lot)->assertSessionHasErrors('procurement_status');

    $co->post('finance', 'sourcing.lots.documents.store', ['name' => 'Supplier proforma', 'file' => UploadedFile::fake()->create('proforma.pdf', 20, 'application/pdf')], $lot)->assertSessionHasNoErrors();
    $document = Document::where('subject_type', 'sourcing_lot')->firstOrFail();
    $co->as('finance')->get(route('sourcing.documents.download', $document))->assertOk();
    $co->post('finance', 'sourcing.lots.documents.store', ['name' => 'Bad', 'file' => UploadedFile::fake()->create('x.exe', 5)], $lot)->assertSessionHasErrors('file');

    $co->post('finance', 'sourcing.lines.arrival', ['received_quantity' => '9', 'accepted_quantity' => '8', 'arrived_on' => '2026-11-08', 'acceptance_reference' => 'DN-SCREEN'], $line)->assertSessionHasNoErrors();
    expect($line->refresh()->received_quantity)->toBe('9.00')->and($line->usable_quantity)->toBe('8.00')->and($line->acceptance_reference)->toBe('DN-SCREEN');

    foreach (['overview', 'lines', 'receiving', 'pricing', 'billing', 'payments', 'activity'] as $tab) {
        $co->as('finance')->get(route('sourcing.lots.show', [$lot, 'tab' => $tab]))->assertOk();
    }
    $co->as('finance')->get(route('sourcing.lots.show', [$lot, 'tab' => 'receiving']))->assertSee('Receive and inspect goods')->assertSee('Supplier proforma')->assertSee('DN-SCREEN')->assertSee('Damaged / rejected');
    $co->as('finance')->get(route('sourcing.lots.create'))->assertOk()->assertSee('Purchase order no.')->assertSee('Screen project');
    $co->as('finance')->get(route('sourcing.advances'))->assertOk();
    $co->as('board')->get(route('finance.approvals'))->assertOk();

    // The lot's purchase order is the default on its supplier advance, and the payment goes through the screen's own route.
    Sourcing::advance($lot, $this->finance);
    Sourcing::savePricing($lot, 'fixed', 50_000, [], $this->finance);
    Sourcing::advance($lot, $this->finance);
    Sourcing::approvePricing($lot, $this->board);
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => (string) $this->supplier->id, 'amount' => '40000.00', 'paid_on' => '2026-11-03', 'reference' => 'SLIP-1', 'evidence_reference' => 'INV-S1',
        'financial_account_id' => (string) $this->bank->id, 'idempotency_key' => (string) Str::uuid()], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(SupplierPayment::firstOrFail()->purchase_order)->toBe('PO-SCREEN')->and(SupplierPayment::first()->expense->financial_account_id)->toBe($this->bank->id);
});

it('gives every completeness finding a link to the screen that fixes it', function () {
    $co = $this->co;
    $project = $co->project($co->client('Receipt client'), 'Receipt project');
    $segment = $co->segment($project, 'Product purchasing', '100');
    $invoice = $co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Goods', 'quantity' => '1', 'unit_price' => '100']], '2026-10-30', 'LINK-1');
    $co->payInvoice($invoice, '100', '2026-10-30')->assertSessionHasNoErrors();
    $co->expense('Loan', 'Loan to someone', '50', '2026-10-26');

    $report = FinancialCompleteness::report();
    $all = collect($report['layers'])->flatMap(fn ($l) => $l['findings']);
    expect($all->every(fn ($f) => ! empty($f['link']) && ! empty($f['link_label'])))->toBeTrue()
        ->and($all->pluck('link_label')->all())->toContain('Classify the receipts')->toContain('Reclassify as a loan')->toContain('Open the expenses')->toContain('Record the statement');
    $co->as('finance')->get(route('finance.completeness'))->assertOk()->assertSee('Required action')->assertSee('Amount affected')->assertSee('Classify the receipts');
});
