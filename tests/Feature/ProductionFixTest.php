<?php

/*
| TEMPORARY: removed together with the sellbees:production-fix command after the one-time cutover has succeeded.
| Rehearses the operational cutover against the rows of the 2026-11-08 production backup (loaded under their original ids) in an isolated test database.
*/

use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Models\ReconciliationException;
use App\Services\IntegrityGate;
use App\Services\ProductionFix;
use App\Services\ReconciliationExceptions;
use App\Services\StatementReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/production_fix_rows.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
    $this->co = SellbeesCompany::boot($this);
    pfLoadBackup();
    $this->operator = $this->co->users['finance']->email;
});

afterEach(fn () => Carbon::setTestNow());

function pfFingerprint(): string
{
    return ProductionFix::report()['fingerprint'];
}

/** @return array<string, mixed> the options of a valid --apply */
function pfApplyOptions(?string $operator = null): array
{
    return ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => pfFingerprint(), '--as' => $operator ?? test()->operator];
}

/** Finance requests and a different Board member approves, through the real routes. */
function pfApproveVoid(int $paymentId): void
{
    test()->co->post('finance', 'payments.void-requests.store', ['reason' => 'Not received (owner confirmation)', 'evidence' => 'Owner written confirmation of receipts: 10,000 + 9,200 + 29,040'], $paymentId)->assertSessionHasNoErrors()->assertSessionMissing('error');
    test()->co->post('board', 'finance.payment-voids.decide', ['decision' => 'approve'], PaymentVoidRequest::where('payment_id', $paymentId)->sole())->assertSessionHasNoErrors()->assertSessionMissing('error');
}

/** Every financial amount, link and verification state the cutover must never touch. */
function pfFinancialState(): array
{
    return [
        DB::table('payments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), DB::table('payment_allocations')->get()->all(), DB::table('journal_entries')->get()->all(), DB::table('journal_lines')->get()->all(),
        DB::table('expenses')->orderBy('id')->get(['id', 'amount', 'status', 'verified_by'])->all(), DB::table('loan_receivables')->get(['id', 'principal', 'status'])->all(),
        DB::table('invoices')->orderBy('id')->get(['id', 'total'])->all(), DB::table('account_statements')->get()->all(), DB::table('receipt_classifications')->get()->all(), DB::table('receipt_reviews')->get()->all(),
        DB::table('sourcing_lots')->get(['id', 'status', 'ownership'])->all(), DB::table('payment_void_requests')->get()->all(),
    ];
}

it('previews without changing anything and shows the two results separately', function () {
    $before = [pfFinancialState(), DB::table('invoices')->orderBy('id')->get()->all(), DB::table('audit_logs')->count(), DB::table('reconciliation_exceptions')->count()];

    $this->artisan('sellbees:production-fix')
        ->expectsOutputToContain('PREVIEW ONLY')->expectsOutputToContain('payment #8: PENDING')->expectsOutputToContain('payment #13: PENDING')->expectsOutputToContain('Actual balance: Not Verified')
        ->expectsOutputToContain('TECHNICAL CUTOVER: NOT YET APPLIED')->expectsOutputToContain('FINANCIAL RECONCILIATION: PENDING')->expectsOutputToContain('--as=<your email>')
        ->assertExitCode(0);

    expect([pfFinancialState(), DB::table('invoices')->orderBy('id')->get()->all(), DB::table('audit_logs')->count(), DB::table('reconciliation_exceptions')->count()])->toEqual($before)->and(ProductionFix::marker())->toBeNull();
});

it('refuses --apply without the database name, fingerprint and a Finance or Board operator, or with stale values', function () {
    $db = DB::connection()->getDatabaseName();
    $this->artisan('sellbees:production-fix', ['--apply' => true])->expectsOutputToContain('database name does not match')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => $db])->expectsOutputToContain('--fingerprint')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => $db, '--fingerprint' => pfFingerprint()])->expectsOutputToContain('--as=<email')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => $db, '--fingerprint' => pfFingerprint(), '--as' => $this->co->users['employee']->email])->expectsOutputToContain('--as=<email')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => 'some_other_db', '--fingerprint' => pfFingerprint(), '--as' => $this->operator])->expectsOutputToContain('database name does not match')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => $db, '--fingerprint' => 'stale', '--as' => $this->operator])->expectsOutputToContain('fingerprint mismatch')->assertExitCode(1);
    expect(DB::table('audit_logs')->where('action', 'like', 'production_fix.%')->count())->toBe(0);
});

it('completes the technical cutover while the historical financial review is still pending, and verifies nothing', function () {
    DB::table('invoices')->where('id', 6)->update(['amount_paid' => 0, 'status' => 'unpaid']);   // a derived total out of step with its payments
    DB::table('sb_number_sequence')->where('id', 1)->update(['last_value' => 1]);                  // the SB counter behind the numbers in use
    $state = pfFinancialState();

    $this->artisan('sellbees:production-fix', pfApplyOptions())
        ->expectsOutputToContain('TECHNICAL CUTOVER: COMPLETE')->expectsOutputToContain('FINANCIAL RECONCILIATION: PENDING')->expectsOutputToContain('does not certify financial reconciliation')
        ->assertExitCode(0);

    $six = Invoice::findOrFail(6);
    $operatorId = $this->co->users['finance']->id;
    $marker = ProductionFix::marker();
    expect($six->amount_paid)->toBe(1_920_000)->and($six->status->value)->toBe('paid')->and((int) DB::table('sb_number_sequence')->value('last_value'))->toBe(3)
        ->and(pfFinancialState())->toEqual($state)                                                       // no amount, link or verification state changed
        ->and($marker)->not->toBeNull()->and($marker->details)->toContain('TECHNICAL CUTOVER COMPLETE')->and($marker->details)->toContain('NOT certified')->and((int) $marker->user_id)->toBe($operatorId)
        ->and(DB::table('payments')->whereIn('id', [8, 13])->whereNotNull('voided_at')->count())->toBe(0)   // still active, still counted, still waiting for the Board
        ->and(DB::table('account_statements')->count())->toBe(0)                                          // nothing was marked verified, nothing invented
        ->and(DB::table('audit_logs')->where('action', 'production_fix.owner_confirmed')->value('details'))->toContain('OWNER-CONFIRMED, NOT BANK-VERIFIED')->toContain('Cutoff 2026-11-08');

    // The historical items are registered for legacy review, all open, none resolved by the command.
    $items = ReconciliationException::orderBy('key')->get()->keyBy('key');
    expect($items->keys()->all())->toContain('cutover:payment_void:8', 'cutover:payment_void:13', 'cutover:cash_difference', 'cash:unassigned')
        ->and($items->every(fn ($x) => $x->status === 'open' && (int) $x->opened_by === $operatorId))->toBeTrue()
        ->and($items['cutover:payment_void:13']->type)->toBe('legacy_review')->and($items['cutover:payment_void:13']->detail)->toContain('LEGACY REVIEW')->toContain('not verified')
        ->and($items['cutover:payment_void:13']->amount)->toBe(1_920_000)
        ->and(DB::table('audit_logs')->where('action', 'like', 'production_fix.%')->whereNull('user_id')->count())->toBe(0);   // every action is attributed

    // A second person resolves an item with evidence, as before; the opener cannot.
    expect(fn () => ReconciliationExceptions::resolve($items['cutover:cash_difference'], 'Verified statements explain the whole difference item by item', $this->co->users['finance']))->toThrow(RuntimeException::class);
});

it('never reruns once the technical cutover is complete, and the preview still reports both results', function () {
    $options = pfApplyOptions();
    $this->artisan('sellbees:production-fix', $options)->assertExitCode(0);
    $audit = DB::table('audit_logs')->count();

    $this->artisan('sellbees:production-fix', pfApplyOptions())->expectsOutputToContain('already been completed')->assertExitCode(1);
    $this->artisan('sellbees:production-fix')->expectsOutputToContain('already completed')->expectsOutputToContain('TECHNICAL CUTOVER: COMPLETE')->expectsOutputToContain('FINANCIAL RECONCILIATION: PENDING')->assertExitCode(0);

    expect(DB::table('audit_logs')->count())->toBe($audit)->and(DB::table('audit_logs')->where('action', ProductionFix::MARKER)->count())->toBe(1);
});

it('keeps financial reconciliation PENDING after both approved voids, with correct balances, loans and a signed difference, and never certifies it', function () {
    pfApproveVoid(8);
    pfApproveVoid(13);
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Cash in Hand']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'Bank + bKash']);
    foreach ([[$cash, 0], [$bank, 4_900_000]] as [$account, $balance]) {
        $s = StatementReconciliationService::record($account, ['statement_date' => '2026-11-08', 'statement_balance' => $balance, 'reference' => 'Owner written confirmation', 'evidence' => 'owner_confirmed'], $this->co->users['finance']);
        StatementReconciliationService::decide($s, true, $this->co->users['board']);
    }
    $state = pfFinancialState();

    $this->artisan('sellbees:production-fix', pfApplyOptions())
        ->expectsOutputToContain('payment #8: APPROVED')->expectsOutputToContain('payment #13: APPROVED')
        ->expectsOutputToContain('ERP expected cash: BDT 43,571.00')->expectsOutputToContain('Actual balance: BDT 49,000.00 (owner-confirmed, not statement-verified')
        ->expectsOutputToContain('Unreconciled cash difference (actual - expected): +BDT 5,429.00')
        ->expectsOutputToContain('TECHNICAL CUTOVER: COMPLETE')->expectsOutputToContain('FINANCIAL RECONCILIATION: PENDING')
        ->assertExitCode(0);

    expect(pfFinancialState())->toEqual($state)->and(DB::table('payments')->count())->toBe(10)
        ->and(DB::table('payments')->whereIn('id', [8, 13])->whereNotNull('voided_at')->count())->toBe(2)->and(DB::table('payment_allocations')->where('payment_id', 13)->count())->toBe(1)
        ->and(ProductionFix::report()['financial'])->toBe('PENDING')->and(ProductionFix::report()['outstanding'])->not->toBe([]);
    $four = Invoice::findOrFail(4);
    $six = Invoice::findOrFail(6);
    $ten = Invoice::findOrFail(10);
    expect([$four->status->value, $four->balance()])->toBe(['paid', 0])->and([$six->status->value, $six->amount_paid, $six->balance()])->toBe(['partial', 920_000, 1_000_000])
        ->and([$ten->status->value, $ten->balance()])->toBe(['paid', 0])->and((int) DB::table('loan_receivables')->where('status', 'verified')->sum('principal'))->toBe(850_000);

    // The controls stay enforced: an unexplained difference against a verified statement still blocks period close and profit allocation.
    expect(fn () => IntegrityGate::assertClear('close 2026-11'))->toThrow(RuntimeException::class, 'unexplained difference');
});

it('keeps normal ERP workflows and approvals working after the cutover, with the historical items still pending', function () {
    $this->artisan('sellbees:production-fix', pfApplyOptions())->assertExitCode(0);
    $historical = [Invoice::findOrFail(4)->only(['total', 'amount_paid', 'status']), Invoice::findOrFail(6)->only(['total', 'amount_paid', 'status']), Invoice::findOrFail(10)->only(['total', 'amount_paid', 'status'])];

    // A new client gets the next SB number, and the normal client -> project -> invoice -> payment flow works.
    $client = $this->co->client('Fresh Client');
    expect($client->sb_number)->toBe('SB-000004');
    $project = $this->co->project($client);
    $segment = $this->co->segment($project, 'Onboarding', '500');
    $invoice = $this->co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Onboarding', 'quantity' => '1', 'unit_price' => '500']], '2026-11-08', 'NEW-1');
    $this->co->payInvoice($invoice, '500', '2026-11-08')->assertSessionHasNoErrors();
    expect($invoice->refresh()->status->value)->toBe('paid')->and($invoice->balance())->toBe(0);

    // Expenses keep their Finance -> Board approval, and post to the ledger when approved.
    $expense = $this->co->expense('Rent', 'November office', '100', '2026-11-08');
    expect($expense->status)->toBe('verified')->and(DB::table('journal_entries')->where('source_type', 'expense')->where('source_id', $expense->id)->count())->toBe(1);

    // A new payment can still only be voided through the Finance request and a different Board member.
    $new = Payment::where('invoice_id', $invoice->id)->sole();
    $this->co->post('board', 'payments.void-requests.store', ['reason' => 'x', 'evidence' => 'y'], $new)->assertForbidden();
    $this->co->voidPayment($new);
    expect($invoice->refresh()->status->value)->toBe('unpaid')->and(Payment::withVoided()->whereKey($new->id)->value('voided_at'))->not->toBeNull();

    // History is untouched, and the owner's pending items are still pending.
    expect([Invoice::findOrFail(4)->only(['total', 'amount_paid', 'status']), Invoice::findOrFail(6)->only(['total', 'amount_paid', 'status']), Invoice::findOrFail(10)->only(['total', 'amount_paid', 'status'])])->toEqual($historical)
        ->and(DB::table('payments')->whereIn('id', [8, 13])->whereNotNull('voided_at')->count())->toBe(0)->and(ReconciliationException::where('status', 'open')->count())->toBeGreaterThan(0);
});

it('fails closed on genuine technical failures only', function () {
    $options = fn () => pfApplyOptions();
    $refused = fn (string $text) => $this->artisan('sellbees:production-fix', $options())->expectsOutputToContain($text)->assertExitCode(1);

    foreach ([['payments', 9, ['amount' => 920_001], ['amount' => 920_000]], ['invoices', 6, ['total' => 1_920_001], ['total' => 1_920_000]]] as [$table, $id, $bad, $good]) {
        DB::table($table)->where('id', $id)->update($bad);
        $refused('technical preconditions do not hold');
        $this->artisan('sellbees:production-fix')->expectsOutputToContain('TECHNICAL CUTOVER: BLOCKED')->assertExitCode(1);
        DB::table($table)->where('id', $id)->update($good);
    }

    // A void request on a payment the owner confirmed as valid blocks it.
    DB::table('payment_void_requests')->insert(['payment_id' => 9, 'amount' => 920_000, 'reason' => 'x', 'evidence' => 'y', 'status' => 'pending', 'requested_by' => $this->co->users['finance']->id, 'active_payment_id' => 9, 'created_at' => now(), 'updated_at' => now()]);
    $refused('valid payment #9');
    DB::table('payment_void_requests')->delete();

    // A payment voided WITHOUT an approved request blocks it; one voided properly through the workflow (ordinary later business) does not.
    DB::table('payments')->where('id', 4)->update(['voided_at' => now()]);
    $refused('payment #4 is voided without an approved request');
    DB::table('payments')->where('id', 4)->update(['voided_at' => null]);
    pfApproveVoid(12);
    $this->artisan('sellbees:production-fix')->expectsOutputToContain('TECHNICAL CUTOVER: NOT YET APPLIED')->assertExitCode(0);

    // Ledger integrity (a technical matter): an unbalanced entry, or a verified expense that never reached the ledger.
    DB::table('journal_lines')->where('journal_entry_id', 4)->where('account', 'expense')->update(['debit' => 1]);
    $refused('out of balance');
    DB::table('journal_lines')->where('journal_entry_id', 4)->where('account', 'expense')->update(['debit' => 198_600]);
    DB::table('journal_entries')->where('source_type', 'expense')->where('source_id', 2)->update(['source_type' => 'backup', 'source_id' => 905]);
    $refused('no journal entry');
    DB::table('journal_entries')->where('source_type', 'backup')->where('source_id', 905)->update(['source_type' => 'expense', 'source_id' => 2]);

    // A missing SB number, and a row with a missing parent.
    DB::table('clients')->where('id', 3)->update(['sb_number' => null]);
    $refused('have no SB number');
    DB::table('clients')->where('id', 3)->update(['sb_number' => 'SB-000003']);
    DB::connection()->getDriverName() === 'sqlite' ? DB::statement('PRAGMA defer_foreign_keys = ON') : DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    DB::table('payment_allocations')->where('id', 5)->update(['segment_id' => 999]);
    $refused('payment_allocations.segment_id');
    DB::table('payment_allocations')->where('id', 5)->update(['segment_id' => 4]);
    DB::connection()->getDriverName() === 'sqlite' ?: DB::statement('SET FOREIGN_KEY_CHECKS = 1');

    expect(DB::table('audit_logs')->where('action', 'like', 'production_fix.%')->count())->toBe(0);
});

it('refuses while another run holds the lock', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('On MySQL/MariaDB the lock is GET_LOCK, exercised from a second connection by the rehearsal script.');
    }
    $lock = Cache::lock('sellbees:production-fix', 60);
    expect($lock->get())->toBeTrue();

    $this->artisan('sellbees:production-fix', pfApplyOptions())->expectsOutputToContain('Another run of this repair is in progress')->assertExitCode(1);
    $lock->release();
    expect(ProductionFix::marker())->toBeNull();
});
