<?php

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\ReceiptReview;
use App\Models\ReconciliationException;
use App\Models\SourcingLot;
use App\Services\AccountingService;
use App\Services\CashAllocationService;
use App\Services\HistoricalReconciliation;
use App\Services\ReconciliationExceptions;
use App\Services\StatementReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** A company with the same kind of unposted historical receipts: one onboarding fee and one product purchase, both paid in cash with no financial account. */
function historicalCompany($test): array
{
    Carbon::setTestNow('2026-11-05 10:00:00');
    $co = SellbeesCompany::boot($test);
    $project = $co->project($co->client('Rivo Mart Client'), 'Rivo Mart');
    $onboarding = $co->segment($project, 'Onboarding', '10000');
    $purchasing = $co->segment($project, 'Product purchasing', '7350');
    $service = $co->invoice($project, [['segment_id' => $onboarding->id, 'description' => 'Onboarding', 'quantity' => '1', 'unit_price' => '10000']], '2026-10-30', 'INV-OB');
    $product = $co->invoice($project, [['segment_id' => $purchasing->id, 'description' => 'Product purchasing', 'quantity' => '1', 'unit_price' => '7350']], '2026-10-30', 'INV-PP');
    $co->payInvoice($service, '10000', '2026-10-29');
    $co->payInvoice($product, '7350', '2026-10-30');

    return ['co' => $co, 'service' => Payment::where('invoice_id', $service->id)->firstOrFail(), 'product' => Payment::where('invoice_id', $product->id)->firstOrFail()];
}

/** Every field of every payment, as plain values (so two snapshots compare by content, not by object identity). */
function paymentSnapshot(): array
{
    return Payment::orderBy('id')->get()->map(fn ($p) => [$p->id, $p->amount, $p->paid_on->toDateString(), $p->reference, $p->invoice_id, $p->financial_account_id, $p->method instanceof BackedEnum ? $p->method->value : $p->method])->all();
}

function proposeBoth(array $h, bool $ok = true): void
{
    $response = $h['co']->post('finance', 'finance.historical.receipts.propose', ['owner_confirmed_on' => '2026-11-04', 'owner_reference' => 'Owner confirmed by phone to Finance on 4 Nov', 'rows' => [
        ['payment_id' => $h['service']->id, 'selected' => '1', 'nature' => 'service'], ['payment_id' => $h['product']->id, 'selected' => '1', 'nature' => 'product_sourcing'],
    ]])->assertSessionHasNoErrors();
    $ok ? $response->assertSessionMissing('error') : $response->assertSessionHas('error');
}

it('moves a receipt through propose, review, approve and apply by different people without posting anything or touching the payment', function () {
    $h = historicalCompany($this);
    $co = $h['co'];
    $journals = JournalEntry::count();
    $before = paymentSnapshot();

    proposeBoth($h);
    $r = ReceiptReview::where('payment_id', $h['service']->id)->firstOrFail();
    expect(ReceiptReview::count())->toBe(2)->and($r->status)->toBe('proposed')->and($r->account_verified)->toBeFalse()->and($r->revenue_recognition_verified)->toBeFalse();
    proposeBoth($h, false);                                                                            // the same receipts again are refused
    expect(ReceiptReview::count())->toBe(2);

    $co->post('finance', 'finance.historical.receipts.review', [], $r)->assertSessionHas('error');      // the proposer cannot review
    $co->post('board', 'finance.historical.receipts.review', [], $r)->assertSessionHasNoErrors();
    $co->post('board', 'finance.historical.receipts.approve', [], $r)->assertSessionHas('error');       // the reviewer cannot approve
    $co->post('finance', 'finance.historical.receipts.approve', [], $r)->assertForbidden();             // approval is a Board permission
    $co->post('clients', 'finance.historical.receipts.apply', [], $r)->assertForbidden();
    $co->post('finance', 'finance.historical.receipts.apply', [], $r)->assertSessionHas('error');       // not approved yet
    expect(ReceiptClassification::count())->toBe(0);
    $co->post('board2', 'finance.historical.receipts.approve', [], $r)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.historical.receipts.apply', [], $r)->assertSessionHasNoErrors();

    expect(ReceiptClassification::where('payment_id', $h['service']->id)->value('nature'))->toBe('service')->and($r->refresh()->status)->toBe('applied')
        ->and($r->account_verified)->toBeFalse()->and($r->revenue_recognition_verified)->toBeFalse();
    expect(JournalEntry::count())->toBe($journals)                                                     // nothing was posted, so legacy period-close posting cannot double count
        ->and(paymentSnapshot())->toBe($before);
    $co->post('finance', 'finance.historical.receipts.apply', [], $r)->assertSessionHas('error');      // and it cannot be applied twice
    expect(ReceiptClassification::count())->toBe(1);
});

it('keeps the statuses apart: received is not allocated, verified, approved, posted or reconciled to a supplier cost', function () {
    $h = historicalCompany($this);
    proposeBoth($h);
    $rows = HistoricalReconciliation::receipts()->keyBy(fn ($r) => $r['payment']->id);
    $service = $rows[$h['service']->id];
    $product = $rows[$h['product']->id];
    expect($service['owner_confirmed'])->toBeTrue()->and($service['received_unallocated'])->toBeTrue()->and($service['account_verified'])->toBeFalse()->and($service['posted'])->toBe('no')
        ->and($service['classification'])->toBe('proposed')->and($service['awaiting_supplier_cost'])->toBeFalse();
    expect($product['nature'])->toBe('product_sourcing')->and($product['awaiting_supplier_cost'])->toBeTrue()->and($product['classification'])->toBe('proposed');
    $summary = HistoricalReconciliation::summary(HistoricalReconciliation::receipts());
    expect($summary)->toMatchArray(['received_unallocated' => 2, 'account_verified' => 0, 'classification_approved' => 0, 'posted_to_ledger' => 0, 'awaiting_supplier_cost' => 1]);
    expect(ReceiptReview::first()->suggestion_basis)->toContain('Invoice');
});

it('prepares bKash and Bank accounts inactive, and activates one only after identity, a verified statement and a second person', function () {
    $h = historicalCompany($this);
    $co = $h['co'];
    $co->post('finance', 'finance.historical.accounts.prepare', ['type' => 'mobile', 'name' => 'bKash'])->assertSessionHasNoErrors();
    $account = FinancialAccount::where('name', 'bKash')->firstOrFail();
    expect($account->is_active)->toBeFalse()->and($account->activation_status)->toBe('pending_confirmation')->and($account->identity_reference)->toBeNull()->and($account->opening_date)->toBeNull();
    $co->post('finance', 'finance.historical.accounts.prepare', ['type' => 'mobile', 'name' => 'BKASH'])->assertSessionHas('error');       // no duplicate
    expect(AccountingService::balance($account->id))->toBe(0);                                                                           // no balance was assumed

    $co->post('finance', 'finance.historical.accounts.activate', [], $account)->assertSessionHas('error');                                // identity first
    $co->post('finance', 'finance.historical.accounts.confirm', ['identity_reference' => '', 'opening_date' => '2026-11-01'], $account)->assertSessionHasErrors('identity_reference');
    $co->post('finance', 'finance.historical.accounts.confirm', ['identity_reference' => 'bKash merchant wallet, statement ref BK-2026-11', 'opening_date' => '2026-11-01'], $account)->assertSessionHasNoErrors();
    expect($account->refresh()->activation_status)->toBe('awaiting_statement')->and($account->is_active)->toBeFalse();

    $co->post('finance', 'finance.historical.accounts.activate', [], $account)->assertSessionHas('error');                                // the confirmer cannot activate
    $co->post('board', 'finance.historical.accounts.activate', [], $account)->assertSessionHas('error');                                  // no verified statement yet
    $statement = StatementReconciliationService::record($account, ['statement_date' => '2026-11-04', 'statement_balance' => 0, 'reference' => 'bKash statement 1-4 Nov'], $co->users['finance']);
    StatementReconciliationService::decide($statement, true, $co->users['board']);
    $co->post('board', 'finance.historical.accounts.activate', [], $account)->assertSessionHasNoErrors();
    expect($account->refresh()->is_active)->toBeTrue()->and($account->activation_status)->toBe('active');
});

it('lists all historical cash lines with the destination unresolved, refuses to allocate a negative balance, and never posts a balancing entry', function () {
    $h = historicalCompany($this);
    $co = $h['co'];
    $co->invest('A', '10000', '2026-10-25');
    $co->expense('office expense', 'Office decoration', '15000', '2026-10-28');
    $co->verifyAllExpenses();
    $lines = HistoricalReconciliation::cashLines();
    expect($lines)->toHaveCount(2)->and($lines->every(fn ($l) => $l['attribution'] === 'unassigned' && str_contains($l['candidate'], 'Unresolved')))->toBeTrue()
        ->and($lines->firstWhere('source', 'expense #1')['missing'])->toContain('account')->toContain('document');
    $cash = HistoricalReconciliation::cashPosition();
    expect($cash['unassigned'])->toBe(-500_000)->and($cash['allocation_possible'])->toBeFalse()->and($cash['receipts_not_in_ledger'])->toBe(1_735_000)->and($cash['projection'])->toBe(1_235_000);

    $account = FinancialAccount::factory()->create(['name' => 'Some Bank']);
    expect(fn () => CashAllocationService::prepare([$account->id => 100], null, $co->users['finance']))->toThrow(RuntimeException::class, 'no unassigned cash');
    $journals = JournalEntry::count();
    $co->post('finance', 'finance.historical.exceptions.register')->assertSessionHasNoErrors();
    expect(JournalEntry::count())->toBe($journals)->and(ReconciliationException::where('type', 'historical_cash')->value('amount'))->toBe(-500_000);
    $co->as('finance')->get(route('finance.historical'))->assertOk()->assertSee('unverified projection only')->assertSee('Unresolved');
});

it('keeps lot ownership unknown until evidence and a second person approve it, without any valuation or journal entry', function () {
    $h = historicalCompany($this);
    $co = $h['co'];
    $lot = SourcingLot::create(['number' => 'SL-000099', 'title' => 'Hoodie Rivo', 'status' => 'draft', 'created_by' => $co->users['finance']->id]);
    $journals = JournalEntry::count();
    $co->post('finance', 'finance.historical.ownership.propose', ['ownership' => 'client', 'evidence_note' => 'short'], $lot)->assertSessionHas('error');
    $co->post('finance', 'finance.historical.ownership.propose', ['ownership' => 'client', 'evidence_note' => 'Signed order from the client for 15 hoodies'], $lot)->assertSessionHas('error'); // a client lot needs its project
    $co->post('finance', 'finance.historical.ownership.propose', ['ownership' => 'client', 'project_id' => $h['service']->project_id, 'evidence_note' => 'Signed order from the client for 15 hoodies'], $lot)->assertSessionHasNoErrors();
    $request = $lot->ownershipRequests()->firstOrFail();
    expect($lot->refresh()->ownership)->toBeNull();

    $co->post('finance', 'product.lots.ownership', ['ownership' => 'client'], $lot)->assertSessionHas('error');                            // the direct route is closed for this lot
    $co->post('finance', 'finance.historical.ownership.decide', ['decision' => 'approve'], $request)->assertForbidden();                    // only the Board decides
    $co->post('board', 'finance.historical.ownership.decide', ['decision' => 'approve'], $request)->assertSessionHas('error');             // no supporting document yet
    expect($lot->refresh()->ownership)->toBeNull();
    Document::create(['subject_type' => 'sourcing_lot', 'subject_id' => $lot->id, 'name' => 'Signed order', 'path' => 'finance-documents/x.pdf', 'uploaded_by' => $co->users['finance']->id]);
    $co->post('board', 'finance.historical.ownership.decide', ['decision' => 'approve'], $request)->assertSessionHasNoErrors();
    expect($lot->refresh()->ownership)->toBe('client')->and($lot->project_id)->toBe($h['service']->project_id)->and(JournalEntry::count())->toBe($journals);
    $co->post('board2', 'finance.historical.ownership.decide', ['decision' => 'approve'], $request)->assertSessionHas('error');             // already decided
});

it('registers the voided payment difference as an open item, creates nothing, and resolves only with a note from another person', function () {
    $h = historicalCompany($this);
    $co = $h['co'];
    $replacement = Payment::where('id', $h['product']->id)->firstOrFail();                              // 7,350.00 paid on 30 Oct
    AuditLog::create(['user_id' => 1, 'action' => 'payment.voided', 'subject' => 'payment '.($replacement->id - 1), 'details' => 'amount 773500 paid 2026-10-30']);   // a voided 7,735.00 payment
    $pairs = ReconciliationExceptions::voidedPayments();
    expect($pairs)->toHaveCount(1)->and($pairs->first()['difference'])->toBe(-38_500)->and($pairs->first()['replacement']->id)->toBe($replacement->id);

    $payments = Payment::count();
    $journals = JournalEntry::count();
    $co->post('finance', 'finance.historical.exceptions.register')->assertSessionHasNoErrors();
    $co->post('finance', 'finance.historical.exceptions.register')->assertSessionHasNoErrors();         // safe to repeat
    $x = ReconciliationException::where('type', 'voided_payment')->firstOrFail();
    expect(ReconciliationException::where('type', 'voided_payment')->count())->toBe(1)->and($x->status)->toBe('open')->and($x->amount)->toBe(-38_500)->and($x->evidence_required)->toContain('No payment, expense or refund');
    expect(Payment::count())->toBe($payments)->and(JournalEntry::count())->toBe($journals);

    $co->post('finance', 'finance.historical.exceptions.resolve', ['note' => 'bKash record seen showing the right amount'], $x)->assertSessionHas('error');   // the opener cannot resolve it
    $co->post('board', 'finance.historical.exceptions.resolve', ['note' => 'short'], $x)->assertSessionHas('error');
    expect($x->refresh()->status)->toBe('open');
    $co->post('board', 'finance.historical.exceptions.resolve', ['note' => 'bKash record seen showing 7,350.00 was received on 30 Oct'], $x)->assertSessionHasNoErrors();
    expect($x->refresh()->status)->toBe('resolved')->and(AuditLog::where('action', 'reconciliation.exception_resolved')->count())->toBe(1);
});

it('shows the screen to finance staff and auditors, never to employees, and shows controls only to those allowed to use them', function () {
    $h = historicalCompany($this);
    $co = $h['co'];
    proposeBoth($h);
    $co->as('finance')->get(route('finance.historical'))->assertOk()->assertSee('Historical reconciliation')->assertSee('Received but unallocated')->assertSee('Cash in Hand')->assertSee('Register open items');
    $co->as('auditor')->get(route('finance.historical'))->assertOk()->assertDontSee(route('finance.historical.exceptions.register'), false)->assertDontSee('Record proposals');
    $co->as('employee')->get(route('finance.historical'))->assertForbidden();
    $co->post('auditor', 'finance.historical.accounts.prepare', ['type' => 'bank', 'name' => 'Bank Account'])->assertForbidden();
    expect(FinancialAccount::count())->toBe(0);
});

it('derives the posting and allocation sequence from the data and never completes a step for anyone', function () {
    $h = historicalCompany($this);
    $co = $h['co'];
    $steps = collect(HistoricalReconciliation::cutoverSequence());
    expect($steps->take(5)->pluck('state')->all())->toBe(['next', 'waiting', 'waiting', 'waiting', 'done'])                       // no lots, so ownership is trivially settled
        ->and($steps->firstWhere('n', 6)['state'])->toBe('waiting')->and($steps->firstWhere('n', 8)['note'])->toContain('Nothing is forced');
    expect($steps->firstWhere('n', 1)['note'])->toContain('required account');

    $journals = JournalEntry::count();
    proposeBoth($h);
    $co->post('board', 'finance.historical.receipts.review', [], ReceiptReview::where('payment_id', $h['service']->id)->firstOrFail());
    $co->post('board2', 'finance.historical.receipts.approve', [], ReceiptReview::where('payment_id', $h['service']->id)->firstOrFail());
    $steps = collect(HistoricalReconciliation::cutoverSequence());
    expect($steps->firstWhere('n', 1)['state'])->toBe('next')->and($steps->firstWhere('n', 3)['state'])->toBe('waiting')       // an earlier step still gates it
        ->and($steps->firstWhere('n', 3)['note'])->toContain('1 of 2');                                                           // one approved, one still proposed
    expect(JournalEntry::count())->toBe($journals);
    $co->as('finance')->get(route('finance.historical'))->assertOk()->assertSee('Safe posting and allocation sequence')->assertSee('finance:backfill-realtime')->assertSee('Do not close a month only to post receipts');
});

it('lets authorised staff upload ownership evidence from the workbench, shows it for review, and still sets no owner', function () {
    Storage::fake();
    $h = historicalCompany($this);
    $co = $h['co'];
    $lot = SourcingLot::create(['number' => 'SL-000098', 'title' => 'Hoodie Rivo', 'status' => 'draft', 'created_by' => $co->users['finance']->id]);
    $co->as('finance')->get(route('finance.historical'))->assertOk()->assertSee('Upload evidence')->assertSee('SL-000098');
    $co->post('finance', 'sourcing.lots.documents.store', ['name' => 'Signed order', 'file' => UploadedFile::fake()->createWithContent('order.pdf', '%PDF-1.4 order')], $lot)->assertSessionHasNoErrors();
    $co->post('auditor', 'sourcing.lots.documents.store', ['name' => 'x', 'file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x')], $lot)->assertForbidden();
    $co->as('finance')->get(route('finance.historical'))->assertSee('Signed order');
    expect(Document::where('subject_type', 'sourcing_lot')->where('subject_id', $lot->id)->count())->toBe(1)->and($lot->refresh()->ownership)->toBeNull();
    $co->as('auditor')->get(route('finance.historical'))->assertOk()->assertDontSee('Upload evidence');
});
