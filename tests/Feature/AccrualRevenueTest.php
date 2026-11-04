<?php

use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\RevenueEvent;
use App\Services\AccrualRevenue;
use App\Services\LedgerStatements;
use App\Services\RevenueRecognition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Accrual customer revenue, forward-only from the configured cutover. Contract: BDT 10,000 (1,000,000 paisa), invoiced as INV-1. "Today" is 2026-11-10.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.accrual_revenue_from' => '2026-11-01', 'finance.completeness_from' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->board2 = $this->co->users['board2'];
    $this->account = FinancialAccount::factory()->asDefault()->create(['name' => 'City Bank']);
    $this->co->invest('A', '1000000', '2026-11-02');
    $this->client = $this->co->client('Revenue Client');
    $this->project = $this->co->project($this->client, 'Fit-out');
    $this->segment = $this->co->segment($this->project, 'Build', '10000');
    $this->invoice = $this->co->invoice($this->project, [['segment_id' => $this->segment->id, 'description' => 'Build', 'quantity' => '1', 'unit_price' => '10000']], '2026-11-03', 'INV-1');
});

afterEach(fn () => Carbon::setTestNow());

function arEvent($project, string $kind, int $amount, array $over = [], bool $approve = true)
{
    $event = AccrualRevenue::propose(test()->finance, $project, $kind, $over + ['amount' => $amount, 'effective_on' => '2026-11-10', 'basis' => 'service', 'evidence_reference' => 'Completion certificate CC-1'], (string) Str::uuid());
    if ($approve) {
        AccrualRevenue::decide($event, true, test()->board);
    }

    return $event->refresh();
}

function arLines(RevenueEvent $e): array
{
    return JournalEntry::where('source_type', 'revenue_event')->where('source_id', $e->id)->firstOrFail()->lines->map(fn ($l) => [$l->account, (int) $l->debit, (int) $l->credit])->sortBy(0)->values()->all();
}

it('holds a customer payment as a liability, earns revenue only on an approved event, applies the advance first and never counts the same money twice', function () {
    AccrualRevenue::setArrangement($this->project, 'principal', 'Contract clause 4: Sellbees controls the work and bears delivery risk', $this->finance);
    $this->co->payInvoice($this->invoice, '4000', '2026-11-05');
    expect(dr('cash'))->toBe(1_000_000_00 + 400_000)->and(dr('income'))->toBe(0)->and(dr('customer.advances'))->toBe(-400_000)->and(AccrualRevenue::balances($this->project)['advances'])->toBe(400_000);

    $event = arEvent($this->project, 'recognition', 1_000_000, ['invoice_id' => $this->invoice->id], approve: false);
    expect($event->status)->toBe('pending')->and(dr('income'))->toBe(0)
        ->and(fn () => AccrualRevenue::decide($event, true, $this->finance))->toThrow(RuntimeException::class, 'cannot approve an event you proposed')
        ->and(fn () => AccrualRevenue::decide($event, true, $this->co->users['multi']))->toThrow(RuntimeException::class, 'Only a Board member');
    AccrualRevenue::decide($event, true, $this->board);

    // Earned 1,000,000: the 400,000 the customer already paid is applied, and 600,000 is a receivable.
    expect(arLines($event->refresh()))->toBe([['customer.advances', 400_000, 0], ['income', 0, 1_000_000], ['receivables', 600_000, 0]])
        ->and(dr('income'))->toBe(-1_000_000)->and(dr('receivables'))->toBe(600_000)->and(dr('customer.advances'))->toBe(0)->and(fn () => AccrualRevenue::decide($event, true, $this->board2))->toThrow(RuntimeException::class, 'Already decided');

    // The rest is collected: the payment is a liability that settles the receivable, never a second income.
    $this->co->payInvoice($this->invoice, '6000', '2026-11-08');
    $b = AccrualRevenue::balances($this->project);
    expect(dr('income'))->toBe(-1_000_000)->and(dr('receivables'))->toBe(0)->and(dr('customer.advances'))->toBe(0)->and(dr('cash'))->toBe(1_000_000_00 + 1_000_000)
        ->and($b['receivable'])->toBe(0)->and($b['advances'])->toBe(0)->and($b['revenue'])->toBe(1_000_000)
        ->and(AccrualRevenue::reconcile()['ok'])->toBeTrue()->and(ledgerAudit()['unbalanced'])->toBe(0);
    // The invoice cannot be recognised twice.
    expect(fn () => arEvent($this->project, 'recognition', 1, ['invoice_id' => $this->invoice->id], approve: false))->toThrow(RuntimeException::class, 'exceed the contract amounts');
});

it('reverses revenue with a credit note: the receivable first, any excess a credit owed to the customer, refunded only from what is owed', function () {
    AccrualRevenue::setArrangement($this->project, 'principal', 'Contract clause 4: Sellbees controls the work and bears delivery risk', $this->finance);
    $this->co->payInvoice($this->invoice, '4000', '2026-11-05');
    $sale = arEvent($this->project, 'recognition', 1_000_000, ['invoice_id' => $this->invoice->id]);       // receivable 600,000

    // A credit of 700,000: 600,000 clears the receivable and the 100,000 beyond it is owed to the customer.
    $credit = arEvent($this->project, 'credit_note', 700_000, ['related_event_id' => $sale->id, 'evidence_reference' => 'Price adjustment agreement PA-3']);
    expect(arLines($credit))->toBe([['customer.advances', 0, 100_000], ['income.returns', 700_000, 0], ['receivables', 0, 600_000]])
        ->and(AccrualRevenue::balances($this->project)['receivable'])->toBe(0)->and(AccrualRevenue::balances($this->project)['advances'])->toBe(100_000)
        ->and(fn () => arEvent($this->project, 'credit_note', 400_000, ['related_event_id' => $sale->id, 'evidence_reference' => 'Price agreement PA-4'], approve: false))->toThrow(RuntimeException::class, 'Only 3,000.00');

    expect(fn () => arEvent($this->project, 'refund', 200_000, ['evidence_reference' => 'Bank ref RF-1', 'financial_account_id' => $this->account->id], approve: false))->toThrow(RuntimeException::class, 'owed to them');
    $cash = dr('cash');
    $refund = arEvent($this->project, 'refund', 100_000, ['evidence_reference' => 'Bank ref RF-1', 'financial_account_id' => $this->account->id]);
    expect(arLines($refund))->toBe([['cash', 0, 100_000], ['customer.advances', 100_000, 0]])->and(dr('cash'))->toBe($cash - 100_000)->and(AccrualRevenue::balances($this->project)['advances'])->toBe(0)
        ->and(dr('income.returns'))->toBe(700_000)
        ->and(fn () => arEvent($this->project, 'refund', 1, ['evidence_reference' => 'Bank ref RF-2', 'financial_account_id' => $this->account->id], approve: false))->toThrow(RuntimeException::class, 'owed to them');
    $profit = LedgerStatements::operationalProfit('2026-11-01', '2026-11-30');
    expect($profit['income'])->toBe(300_000)->and(AccrualRevenue::reconcile()['ok'])->toBeTrue();      // 1,000,000 earned less 700,000 credited
});

it('recognises earned but unbilled revenue as a contract asset and moves it to a receivable only when invoiced', function () {
    AccrualRevenue::setArrangement($this->project, 'principal', 'Contract clause 4: Sellbees controls the work and bears delivery risk', $this->finance);
    $earned = arEvent($this->project, 'recognition', 600_000);
    expect(arLines($earned))->toBe([['assets.contract', 600_000, 0], ['income', 0, 600_000]])->and(AccrualRevenue::balances($this->project)['contract_asset'])->toBe(600_000)->and(dr('receivables'))->toBe(0);

    expect(fn () => arEvent($this->project, 'bill', 700_000, ['invoice_id' => $this->invoice->id], approve: false))->toThrow(RuntimeException::class, 'still unbilled');
    $bill = arEvent($this->project, 'bill', 600_000, ['invoice_id' => $this->invoice->id]);
    expect(arLines($bill))->toBe([['assets.contract', 0, 600_000], ['receivables', 600_000, 0]])->and(dr('income'))->toBe(-600_000)->and(dr('receivables'))->toBe(600_000)->and(dr('assets.contract'))->toBe(0)
        ->and(AccrualRevenue::reconcile()['ok'])->toBeTrue();
    // Revenue cannot exceed the contract amounts recorded for the project.
    expect(fn () => arEvent($this->project, 'recognition', 500_000, [], approve: false))->toThrow(RuntimeException::class, 'exceed the contract amounts');
});

it('blocks revenue until the project is classified, and keeps an agent to commission and a principal sale to a verified transfer of control', function () {
    expect(fn () => arEvent($this->project, 'recognition', 100_000, ['invoice_id' => $this->invoice->id], approve: false))->toThrow(RuntimeException::class, 'classification_required')
        ->and(fn () => AccrualRevenue::setArrangement($this->project, 'principal', 'short', $this->finance))->toThrow(RuntimeException::class, 'contract terms');

    $agent = $this->co->project($this->client, 'Order fulfilment');
    $this->co->segment($agent, 'Commission', '1000');
    AccrualRevenue::setArrangement($agent, 'agent', 'Fulfilment agreement 7: the client owns the stock and sets the price; Sellbees earns a commission', $this->finance);
    expect(fn () => arEvent($agent, 'recognition', 100_000, ['basis' => 'sale'], approve: false))->toThrow(RuntimeException::class, 'client\'s merchandise proceeds belong to the client')
        ->and(arEvent($agent, 'recognition', 100_000, ['basis' => 'commission'])->status)->toBe('approved');

    AccrualRevenue::setArrangement($this->project, 'principal', 'Contract clause 4: Sellbees controls the goods and bears delivery risk', $this->finance);
    expect(fn () => arEvent($this->project, 'recognition', 100_000, ['basis' => 'sale'], approve: false))->toThrow(RuntimeException::class, 'verified transfer of control')
        ->and(fn () => AccrualRevenue::setArrangement($agent, 'principal', 'Changed my mind about this project entirely', $this->finance))->toThrow(RuntimeException::class, 'cannot change');
});

it('voids only an invoice nothing hangs on, never one with revenue or payments', function () {
    AccrualRevenue::setArrangement($this->project, 'principal', 'Contract clause 4: Sellbees controls the work and bears delivery risk', $this->finance);
    $spare = $this->co->invoice($this->project, [['segment_id' => $this->segment->id, 'description' => 'Extra', 'quantity' => '1', 'unit_price' => '100']], '2026-11-04', 'INV-2');
    arEvent($this->project, 'recognition', 500_000, ['invoice_id' => $this->invoice->id]);

    expect(fn () => AccrualRevenue::voidInvoice($this->invoice->refresh(), 'Issued in error', $this->finance))->toThrow(RuntimeException::class, 'a void cannot hide them');
    AccrualRevenue::voidInvoice($spare, 'Issued in error', $this->finance);
    expect($spare->refresh()->voided_at)->not->toBeNull()->and(fn () => AccrualRevenue::voidInvoice($spare, 'Again', $this->finance))->toThrow(RuntimeException::class, 'already void')
        ->and(fn () => arEvent($this->project, 'recognition', 1, ['invoice_id' => $spare->id], approve: false))->toThrow(RuntimeException::class, 'voided');
});

it('cuts over forward-only: money and revenue before the cutover keep their cash-basis booking and cannot be recognised again', function () {
    config(['finance.accrual_revenue_from' => '2026-12-15']);                       // normalised to the first of the next month
    expect(AccrualRevenue::cutover())->toBe('2027-01-01')->and(AccrualRevenue::covers('2026-11-10'))->toBeFalse();
    AccrualRevenue::setArrangement($this->project, 'principal', 'Contract clause 4: Sellbees controls the work and bears delivery risk', $this->finance);
    $this->co->payInvoice($this->invoice, '4000', '2026-11-05');

    // Before the cutover a payment is income, exactly as it was booked before this engine existed, and no event can recognise it again.
    expect(dr('income'))->toBe(-400_000)->and(dr('customer.advances'))->toBe(0)
        ->and(fn () => arEvent($this->project, 'recognition', 400_000, ['invoice_id' => $this->invoice->id], approve: false))->toThrow(RuntimeException::class, 'does not apply to that date')
        ->and(RevenueRecognition::figures('2026-11')['income'])->toBe(400_000)->and(AccrualRevenue::reconcile()['ok'])->toBeTrue();

    // With the engine off nothing changes at all.
    config(['finance.accrual_revenue_from' => null]);
    $this->co->payInvoice($this->invoice, '1000', '2026-11-06');
    expect(AccrualRevenue::cutover())->toBeNull()->and(dr('income'))->toBe(-500_000)->and(RevenueRecognition::figures('2026-11')['income'])->toBe(500_000);
});

it('reports income as earned revenue across the cutover: the cash-basis months stand and the accrual months read the events', function () {
    config(['finance.realtime_from' => '2026-09-01', 'finance.accrual_revenue_from' => '2026-11-01']);
    AccrualRevenue::setArrangement($this->project, 'principal', 'Contract clause 4: Sellbees controls the work and bears delivery risk', $this->finance);
    $this->co->payInvoice($this->invoice, '4000', '2026-11-05');
    arEvent($this->project, 'recognition', 250_000, ['invoice_id' => $this->invoice->id]);
    $f = RevenueRecognition::between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));

    // Collected 400,000 is cash; income is what was EARNED (250,000), never the cash.
    expect($f['collected'])->toBe(400_000)->and($f['income'])->toBe(250_000)->and($f['recognised'])->toBe(250_000);
    expect(LedgerStatements::exceptions())->toBe([]);
    $page = $this->co->as('finance')->get(route('finance.ledger-reports'))->assertOk()->getContent();
    expect($page)->toContain('Operational profit (ledger)');
});

it('applies a payment only once and refuses to void a payment that revenue has already been applied against', function () {
    AccrualRevenue::setArrangement($this->project, 'principal', 'Contract clause 4: Sellbees controls the work and bears delivery risk', $this->finance);
    $this->co->payInvoice($this->invoice, '4000', '2026-11-05');
    arEvent($this->project, 'recognition', 1_000_000, ['invoice_id' => $this->invoice->id]);        // applies the 400,000, receivable 600,000
    $payment = Payment::firstOrFail();

    $this->co->post('finance', 'payments.destroy', [], $payment);
    expect(Payment::count())->toBe(1)->and(AccrualRevenue::balances($this->project)['advances'])->toBe(0)->and(AccrualRevenue::reconcile()['ok'])->toBeTrue();
    expect(RevenueEvent::where('kind', 'application')->count())->toBe(0);
});

it('serves the earned revenue screens with the same separation of duties', function () {
    $page = $this->co->as('finance')->get(route('finance.revenue-events'))->assertOk()->getContent();
    expect($page)->toContain('classification required')->and($page)->toContain('Accrual engine active from 2026-11-01');
    $this->co->post('finance', 'finance.revenue-events.classify', ['arrangement' => 'principal', 'evidence' => 'Contract clause 4: Sellbees controls the work and bears risk'], $this->project)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $this->co->post('finance', 'finance.revenue-events.store', ['project_id' => $this->project->id, 'kind' => 'recognition', 'amount' => '5000', 'effective_on' => '2026-11-10', 'basis' => 'service',
        'invoice_id' => $this->invoice->id, 'evidence_reference' => 'Completion certificate CC-9', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertSessionMissing('error');
    $event = RevenueEvent::firstOrFail();
    $this->co->post('board', 'finance.revenue-events.store', ['project_id' => $this->project->id, 'kind' => 'recognition', 'amount' => '1', 'effective_on' => '2026-11-10'])->assertForbidden();
    $this->co->post('finance', 'finance.revenue-events.decide', ['decision' => 'approve'], $event)->assertForbidden();
    $this->co->post('board', 'finance.revenue-events.decide', ['decision' => 'approve'], $event)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($event->refresh()->status)->toBe('approved')->and(dr('income'))->toBe(-500_000)->and($this->co->as('board')->get(route('finance.revenue-events'))->getContent())->toContain('Reconciled');
});
