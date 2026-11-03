<?php

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\Supplier;
use App\Services\FinancialCompleteness;
use App\Services\LoanReceivableService;
use App\Services\PeriodProcessor;
use App\Services\ProfitAllocator;
use App\Services\Sourcing;
use App\Services\SourcingPayments;
use App\Services\StatementReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Financial completeness: an arithmetically balanced ledger is not reconciled accounting. These tests reproduce the production
| pattern (customer receipts for products with no sourcing record, an unlinked draft lot, a loan booked as an expense, no verified
| cash) and check that each layer reports it, that only the right things block, and that drafts, purchases in progress and ordinary
| partial payments never do. "Today" is 2026-11-10; October 2026 is a finished, unclosed month, as in production.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
});

afterEach(fn () => Carbon::setTestNow());

/** A manual product invoice paid in October, exactly like the production pattern (no lot, no allocation, no supplier cost). */
function fcProductReceipt(string $number = 'PR-1', string $amount = '7350'): array
{
    $co = test()->co;
    $project = $co->project($co->client('Client '.$number), 'Project '.$number);
    $segment = $co->segment($project, 'Product purchasing', '7350');
    $invoice = $co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Zipper Hoodie', 'quantity' => '15', 'unit_price' => '490']], '2026-10-30', $number);
    $co->payInvoice($invoice, $amount, '2026-10-30')->assertSessionHasNoErrors();

    return [$invoice, $invoice->payments()->latest('id')->firstOrFail()];
}

function fcClassify(int $paymentId, string $nature, string $by = 'finance')
{
    return test()->co->post($by, 'finance.completeness.classify', ['nature' => $nature], Payment::findOrFail($paymentId));
}

function fcLayer(string $key): array
{
    return FinancialCompleteness::report()['layers'][$key];
}

it('does not call a balanced ledger reconciled', function () {
    $this->co->invest('A', '10000', '2026-10-20');
    $report = FinancialCompleteness::report();

    expect($report['ledger_balanced'])->toBeTrue()->and($report['fully_reconciled'])->toBeFalse()
        ->and($report['headline'])->toContain('balances arithmetically')->toContain('NOT fully reconciled')
        ->and($report['layers']['ledger']['status'])->toBe('clear')->and($report['layers']['accounts']['status'])->toBe('needs review');
    // Calculated cash is never labelled verified.
    expect(collect($report['layers']['accounts']['findings'])->pluck('message')->implode(' '))->toContain('calculated, not verified');

    $statement = StatementReconciliationService::record($this->cash, ['statement_date' => '2026-11-09', 'statement_balance' => 10_000_00, 'reference' => 'S-1'], $this->finance);
    StatementReconciliationService::decide($statement, true, $this->board);
    expect(fcLayer('accounts')['status'])->toBe('clear');
});

it('reports product receipts with no sourcing record and never links them to a lot by matching quantities', function () {
    [$invoice, $payment] = fcProductReceipt();
    // A draft lot with the same 15 pieces exists, unlinked, like SL-000001: it is not evidence for this invoice.
    $supplier = Supplier::create(['name' => 'Uttara']);
    $lot = Sourcing::createLot(['title' => 'Hoodie lot'], $this->finance);
    Sourcing::addLine($lot, ['supplier_id' => $supplier->id, 'description' => 'Zipper Hoodie', 'quantity' => '15', 'unit_price' => 40_000], $this->finance);

    $row = FinancialCompleteness::receipts()->firstWhere(fn ($r) => $r['payment']->id === $payment->id);
    expect($row['nature'])->toBeNull()->and($row['source'])->toBeNull()->and($row['covered'])->toBeTrue(); // unclassified, not yet claimed to be product
    // Reported (warning) but not blocking while enforcement is off.
    expect(fcLayer('classification')['status'])->toBe('needs review')->and(FinancialCompleteness::blockersForMonth('2026-10'))->toBe([]);
    expect(collect(fcLayer('classification')['findings'])->pluck('message')->implode(' '))->toContain('PR-1');

    // Owner states it is a product-sourcing receipt: now it needs a sourcing record, which a same-quantity draft lot is not.
    fcClassify($payment->id, 'product_sourcing')->assertSessionHasNoErrors();
    $row = FinancialCompleteness::receipts()->firstWhere(fn ($r) => $r['payment']->id === $payment->id);
    expect($row['nature'])->toBe('product_sourcing')->and($row['covered'])->toBeFalse();
    $blockers = FinancialCompleteness::blockersForMonth('2026-10');
    expect($blockers)->toHaveCount(1)->and($blockers[0])->toContain('no sourcing record')->toContain('PR-1')->toContain('7,350.00');
    expect(fcLayer('sourcing')['status'])->toBe('blocking')->and(FinancialCompleteness::receipts()->firstWhere(fn ($r) => $r['payment']->id === $payment->id)['payment']->invoice_id)->toBe($invoice->id);
    expect(fn () => PeriodProcessor::close('2026-10', $this->finance))->toThrow(RuntimeException::class, 'no sourcing record');
    expect(AccountingPeriod::count())->toBe(0);

    // A service receipt, by contrast, closes normally.
    fcClassify($payment->id, 'service')->assertSessionHasNoErrors();
    expect(FinancialCompleteness::blockersForMonth('2026-10'))->toBe([]);
    PeriodProcessor::close('2026-10', $this->finance);
    expect(AccountingPeriod::where('month', '2026-10')->exists())->toBeTrue();
});

it('blocks the close of a month with unclassified receipts only once enforcement is switched on', function () {
    [, $payment] = fcProductReceipt();

    expect(FinancialCompleteness::blockersForMonth('2026-10'))->toBe([]); // not enforced: reported (see the classification layer), not blocking
    expect(fcLayer('classification')['status'])->toBe('needs review');

    config(['finance.completeness_from' => '2026-10']);
    expect(FinancialCompleteness::blockersForMonth('2026-10'))->toHaveCount(1)->and(FinancialCompleteness::blockersForMonth('2026-10')[0])->toContain('not classified');
    expect(fn () => PeriodProcessor::close('2026-10', $this->finance))->toThrow(RuntimeException::class, 'not classified');
    // Earlier months are untouched by a later enforcement start.
    expect(FinancialCompleteness::blockersForMonth('2026-09'))->toBe([]);

    fcClassify($payment->id, 'service')->assertSessionHasNoErrors();
    expect(FinancialCompleteness::blockersForMonth('2026-10'))->toBe([]);
    PeriodProcessor::close('2026-10', $this->finance);
});

it('stops profit verification and allocation when a closed month is later found to hold an unsupported product receipt', function () {
    [, $payment] = fcProductReceipt();
    fcClassify($payment->id, 'service')->assertSessionHasNoErrors();
    $period = PeriodProcessor::close('2026-10', $this->finance);

    fcClassify($payment->id, 'product_sourcing')->assertSessionHasNoErrors(); // the owner corrects the label
    expect(fn () => PeriodProcessor::verify($period, $this->board))->toThrow(RuntimeException::class, 'verify the profit of');
    expect($period->refresh()->status)->toBe('closed');

    fcClassify($payment->id, 'service')->assertSessionHasNoErrors();
    PeriodProcessor::verify($period->refresh(), $this->board);
    fcClassify($payment->id, 'product_sourcing')->assertSessionHasNoErrors();
    expect(fn () => ProfitAllocator::prepare($period->refresh(), $this->finance))->toThrow(RuntimeException::class, 'allocate the profit of');
});

it('never holds back drafts, purchases in progress or ordinary partial payments', function () {
    config(['finance.completeness_from' => '2026-10']);
    $co = $this->co;
    $this->co->invest('A', '100000', '2026-10-01');
    $s = Supplier::create(['name' => 'Supplier']);
    $draft = Sourcing::createLot(['title' => 'Draft only'], $this->finance);
    Sourcing::addLine($draft, ['supplier_id' => $s->id, 'description' => 'Not bought yet', 'quantity' => '3', 'unit_price' => 500], $this->finance);

    $lot = Sourcing::createLot(['title' => 'In progress'], $this->finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => $s->id, 'description' => 'Goods', 'quantity' => '10', 'unit_price' => 1_000], $this->finance);
    Sourcing::advance($lot, $this->finance);
    Sourcing::savePricing($lot, 'fixed', 500, [], $this->finance);
    Sourcing::advance($lot, $this->finance);
    Sourcing::approvePricing($lot, $this->board);
    $project = $co->project($co->client('Billed client'), 'Billed project');
    $allocation = Sourcing::allocate($lot->refresh(), ['project_id' => $project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '10'], $this->finance);
    $invoice = Sourcing::bill($allocation, null, null, $this->finance);
    SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $s->id, 'amount' => 3_000, 'paid_on' => '2026-11-05', 'idempotency_key' => (string) Str::uuid()], $this->finance); // part of 10,000
    $co->payInvoice($invoice->refresh(), '20.00', '2026-11-10')->assertSessionHasNoErrors(); // part of the invoice

    // Billed from a lot: product sourcing by record, covered, and not relabelable.
    $payment = $invoice->payments()->firstOrFail();
    $row = FinancialCompleteness::receipts()->firstWhere(fn ($r) => $r['payment']->id === $payment->id);
    expect($row['nature'])->toBe('product_sourcing')->and($row['source'])->toBe('sourcing billing')->and($row['covered'])->toBeTrue();
    expect(FinancialCompleteness::blockersForMonth('2026-11'))->toBe([])->and(fcLayer('sourcing')['status'])->toBe('clear');
    fcClassify($payment->id, 'service')->assertSessionHas('error');
    expect(ReceiptClassification::count())->toBe(0);

    // An unfinished lot with an unpaid supplier balance and no arrival yet is simply in progress.
    expect(FinancialCompleteness::lotBlockers($draft))->not->toBe([]); // arrival still to come...
    expect(fcLayer('sourcing')['findings'])->toBe([]);                  // ...but nothing is reported or blocked until the lot is finished
});

it('reports a finished lot with no arrival and blocks only its settlement', function () {
    $s = Supplier::create(['name' => 'Supplier']);
    $lot = Sourcing::createLot(['title' => 'Finished'], $this->finance);
    Sourcing::addLine($lot, ['supplier_id' => $s->id, 'description' => 'Goods', 'quantity' => '2', 'unit_price' => 1_000], $this->finance);
    Sourcing::advance($lot, $this->finance);
    Sourcing::savePricing($lot, 'fixed', 100, [], $this->finance);
    Sourcing::advance($lot, $this->finance);
    Sourcing::approvePricing($lot, $this->board);
    SourcingPayments::recordSupplierPayment($lot->refresh(), ['supplier_id' => $s->id, 'amount' => 2_000, 'paid_on' => '2026-11-05', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    Sourcing::advance($lot, $this->finance);
    Sourcing::advance($lot, $this->finance);

    expect(collect(fcLayer('sourcing')['findings'])->pluck('message')->implode(' '))->toContain('no arrival on record');
    expect(fn () => Sourcing::settle($lot->refresh(), $this->finance))->toThrow(RuntimeException::class, 'arrival');
    expect($lot->refresh()->status)->toBe('completed');
});

it('flags a loan booked as an expense, expenses without documents and receipts not linked to an invoice, and clears the loan once reclassified', function () {
    $co = $this->co;
    $co->invest('A', '100000', '2026-10-01');
    $loan = $co->expense('Loan', 'Loan for a partner', '8000', '2026-10-26');
    $co->expense('Office', 'Chairs', '500', '2026-10-27');
    $project = $co->project($co->client('Unlinked'), 'Unlinked project');
    $co->paySegment($co->segment($project, 'Onboarding', '10000'), '10000', '2026-10-28')->assertSessionHasNoErrors(); // no invoice behind it

    $classification = collect(fcLayer('classification')['findings'])->pluck('message')->implode(' | ');
    expect($classification)->toContain('looks like a loan')->toContain('Expense #'.$loan->id)->toContain('no supporting document');
    expect(collect(fcLayer('source')['findings'])->pluck('message')->implode(' '))->toContain('not linked to an invoice');

    $proposal = LoanReceivableService::propose($loan, ['borrower_name' => 'A partner'], $this->finance);
    LoanReceivableService::decide($proposal, true, $this->board);
    expect(collect(fcLayer('classification')['findings'])->pluck('message')->implode(' | '))->not->toContain('looks like a loan');
});

it('lets Finance and the Board classify receipts and nobody else', function () {
    [, $payment] = fcProductReceipt();

    fcClassify($payment->id, 'service', 'partnerA')->assertForbidden();
    fcClassify($payment->id, 'bogus', 'finance')->assertSessionHasErrors('nature');
    fcClassify($payment->id, 'service', 'board')->assertSessionHasNoErrors();
    fcClassify($payment->id, 'other', 'finance')->assertSessionHasNoErrors();

    expect(ReceiptClassification::count())->toBe(1)->and(ReceiptClassification::first()->nature)->toBe('other')
        ->and(AuditLog::where('action', 'receipt.classified')->count())->toBe(2);
    // The payment row itself is untouched.
    expect($payment->fresh()->amount)->toBe(7_350_00)->and($payment->fresh()->getAttributes())->toEqual($payment->getAttributes());
    $this->co->as('finance')->get(route('finance.completeness'))->assertOk()->assertSee('Financial Completeness')->assertSee('NOT fully reconciled');
});
