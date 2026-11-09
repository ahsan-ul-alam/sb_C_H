<?php

/*
| TEMPORARY: removed together with the sellbees:production-fix command after the one-time cutover has succeeded.
| Rehearses the operational cutover against the rows of the 2026-11-08 production backup (loaded under their original ids) in an isolated test database.
*/

use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Models\ReconciliationException;
use App\Services\BusinessDashboard;
use App\Services\CashReconciliation;
use App\Services\IntegrityGate;
use App\Services\Ledger;
use App\Services\LoanReceivableService;
use App\Services\LoanRecoveryAllocationService;
use App\Services\ProductionFix;
use App\Services\ReconciliationExceptions;
use App\Services\StatementReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/* ---------------------------------------------------------------- the one-time financial baseline (--baseline) */

function pfBackup(int $ageSeconds = 0, bool $withPayments = true): string
{
    $path = sys_get_temp_dir().'/pf-backup-'.uniqid().'.sql';
    file_put_contents($path, "-- MySQL dump 10.13\n".($withPayments ? "CREATE TABLE `payments` (\n  `id` bigint\n);\n" : "CREATE TABLE `other` (\n);\n").str_repeat("-- padding line so the file has a believable size\n", 400));
    touch($path, time() - $ageSeconds);

    return $path;
}

/** @param array<string, mixed> $override @return array<string, mixed> */
function pfBaselineOptions(array $override = []): array
{
    return array_merge(pfApplyOptions(), ['--baseline' => true, '--cutoff' => '2026-11-08 12:00', '--owner-cash' => '49000', '--expected-cash' => '43571', '--backup-file' => pfBackup(),
        '--owner-authorization' => 'Owner Ahsan Alam, written message of 8 Nov 2026 12:05', '--confirm' => ProductionFix::CONFIRMATION], $override);
}

it('previews the exact baseline before and after, with the bridge, and changes nothing', function () {
    $before = [pfFinancialState(), DB::table('audit_logs')->count(), DB::table('reconciliation_exceptions')->count()];

    $this->artisan('sellbees:production-fix', ['--baseline' => true, '--cutoff' => '2026-11-08 12:00', '--owner-cash' => '49000', '--expected-cash' => '43571', '--backup-file' => pfBackup()])
        ->expectsOutputToContain('BASELINE PREVIEW ONLY')->expectsOutputToContain('before corrections: BDT 72,771.00')->expectsOutputToContain('less erroneous receipt #8')->expectsOutputToContain('less erroneous receipt #13')
        ->expectsOutputToContain('Reconstructed historical cash after corrections: BDT 43,571.00')->expectsOutputToContain('Owner-confirmed actual cash (Bank + bKash + Cash in Hand): BDT 49,000.00')
        ->expectsOutputToContain('Legacy Opening Balance Adjustment / Historical Cash Difference: +BDT 5,429.00')->expectsOutputToContain('unreconciled difference 0 at the cutoff')
        ->expectsOutputToContain('FINANCIAL RECONCILIATION: PENDING')->assertExitCode(0);

    expect([pfFinancialState(), DB::table('audit_logs')->count(), DB::table('reconciliation_exceptions')->count()])->toEqual($before);
});

it('refuses the baseline unless every authorization, backup and identity input is present and valid', function () {
    $before = [pfFinancialState(), DB::table('audit_logs')->count()];
    $refused = fn (array $override, string $text) => $this->artisan('sellbees:production-fix', pfBaselineOptions($override))->expectsOutputToContain($text)->assertExitCode(1);

    $refused(['--confirm' => ''], '--confirm must be exactly');
    $refused(['--confirm' => 'yes'], '--confirm must be exactly');
    $refused(['--owner-authorization' => 'ok'], '--owner-authorization must say who authorized');
    $refused(['--backup-file' => ''], '--backup-file is required');
    $refused(['--backup-file' => '/nonexistent/backup.sql'], 'does not exist or cannot be read');
    $refused(['--backup-file' => pfBackup(3 * 3600)], 'within the last two hours');
    $refused(['--backup-file' => pfBackup(0, false)], 'does not contain the payments table');
    $refused(['--expected-cash' => '43572'], 'not the BDT 43,572.00 you supplied');
    $refused(['--expected-cash' => ''], '--expected-cash is required');
    $refused(['--owner-cash' => 'abc'], '--owner-cash must be an amount');
    $refused(['--cutoff' => '2027-01-01 10:00'], 'in the future');
    $refused(['--cutoff' => '2026-11-07 12:00'], 'after the cutoff date');
    $refused(['--cutoff' => 'yesterday'], '--cutoff must look like');
    $refused(['--fingerprint' => 'stale'], 'fingerprint mismatch');
    $refused(['--expect-database' => 'some_other_db'], 'database name does not match');
    $refused(['--as' => $this->co->users['employee']->email], '--as=<email');

    expect([pfFinancialState(), DB::table('audit_logs')->count()])->toEqual($before)->and(ProductionFix::baselineMarker())->toBeNull();
});

it('applies the one-time baseline exactly once: voids by explicit administrative correction, one balanced opening adjustment, nothing else changed', function () {
    $build = fn () => BusinessDashboard::build(null, CarbonImmutable::parse('2026-11-08'));
    $dashboardBefore = $build();
    $paymentsBefore = DB::table('payments')->orderBy('id')->get()->keyBy('id')->map(fn ($r) => (array) $r)->all();
    $journalBefore = DB::table('journal_lines')->where('journal_entry_id', '<=', 24)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $entries = DB::table('journal_entries')->count();
    $backup = pfBackup();
    $operatorId = $this->co->users['finance']->id;

    $this->artisan('sellbees:production-fix', pfBaselineOptions(['--backup-file' => $backup]))
        ->expectsOutputToContain('payment #8 (BDT 10,000.00) voided by administrative correction')->expectsOutputToContain('payment #13 (BDT 19,200.00) voided by administrative correction')
        ->expectsOutputToContain('Legacy Opening Balance Adjustment +BDT 5,429.00 posted as journal entry')->expectsOutputToContain('TECHNICAL CUTOVER: COMPLETE')->expectsOutputToContain('FINANCIAL RECONCILIATION: COMPLETE')
        ->expectsOutputToContain('EVIDENCE: the actual balance is owner-confirmed only')->assertExitCode(0);

    // The duplicate receipts are kept and voided; only voided_at differs; every other payment is identical; the allocation is kept.
    $payments = DB::table('payments')->orderBy('id')->get()->keyBy('id')->map(fn ($r) => (array) $r)->all();
    expect(array_keys(array_diff_assoc($payments[8], $paymentsBefore[8])))->toBe(['voided_at'])->and(array_keys(array_diff_assoc($payments[13], $paymentsBefore[13])))->toBe(['voided_at'])
        ->and(array_diff_key($payments, [8 => 1, 13 => 1]))->toEqual(array_diff_key($paymentsBefore, [8 => 1, 13 => 1]))->and(count($payments))->toBe(10)
        ->and(DB::table('payment_allocations')->where('payment_id', 13)->count())->toBe(1);
    $requests = DB::table('payment_void_requests')->orderBy('payment_id')->get();
    expect($requests->pluck('payment_id')->all())->toBe([8, 13])->and($requests->pluck('status')->unique()->all())->toBe(['authorized'])
        ->and($requests->every(fn ($r) => (int) $r->decided_by === $operatorId && str_contains((string) $r->decision_note, 'NOT a Board member approval')))->toBeTrue();

    // Invoices, receipts and loans.
    $four = Invoice::findOrFail(4);
    $six = Invoice::findOrFail(6);
    $ten = Invoice::findOrFail(10);
    expect([$four->status->value, $four->balance()])->toBe(['paid', 0])->and([$six->status->value, $six->amount_paid, $six->balance()])->toBe(['partial', 920_000, 1_000_000])->and([$ten->status->value, $ten->balance()])->toBe(['paid', 0])
        ->and((int) DB::table('payments')->where('project_id', 2)->whereNull('voided_at')->sum('amount'))->toBe(4_824_000)
        ->and((int) DB::table('loan_receivables')->where('status', 'verified')->sum('principal'))->toBe(850_000);

    // One new, balanced entry; every earlier journal line is untouched; cash reads exactly the owner-confirmed figure at the cutoff.
    $entry = DB::table('journal_entries')->where('source_type', ProductionFix::BASELINE_SOURCE)->sole();
    $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->orderBy('id')->get();
    expect(DB::table('journal_entries')->count())->toBe($entries + 1)->and(DB::table('journal_lines')->where('journal_entry_id', '<=', 24)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toEqual($journalBefore)
        ->and(substr((string) $entry->entry_date, 0, 10))->toBe('2026-11-08')->and($entry->memo)->toContain('Legacy Opening Balance Adjustment')->toContain('not revenue, profit or loss')
        ->and($lines->pluck('account')->all())->toBe(['cash', 'equity.opening'])->and((int) $lines[0]->debit)->toBe(542_900)->and((int) $lines[0]->credit)->toBe(0)->and($lines[0]->financial_account_id)->toBeNull()
        ->and((int) $lines[1]->credit)->toBe(542_900)->and((int) $lines[1]->debit)->toBe(0)
        ->and(CashReconciliation::expectedCash('BDT', '2026-11-08'))->toBe(4_900_000)->and((int) DB::table('journal_lines')->sum('debit'))->toBe((int) DB::table('journal_lines')->sum('credit'));

    // The bridge is permanent and the reconciliation reads the stored baseline: owner-confirmed, never statement-verified.
    $audit = json_decode((string) DB::table('audit_logs')->where('action', 'cash.baseline_set')->sole()->details, true);
    expect($audit)->toMatchArray(['cutoff_at' => '2026-11-08 12:00', 'evidence' => 'owner_confirmed', 'owner_cash' => 4_900_000, 'reconstructed_expected_before_corrections' => 7_277_100, 'duplicate_receipts_removed' => 2_920_000,
        'reconstructed_expected' => 4_357_100, 'adjustment' => 542_900, 'operator' => $this->operator])->and($audit['backup']['sha256'])->toBe(hash_file('sha256', $backup))->and($audit['authorization'])->toContain('Owner Ahsan Alam');
    $snap = CashReconciliation::snapshot();
    expect($snap['source'])->toBe('owner_baseline')->and($snap['actual'])->toBe(4_900_000)->and($snap['difference'])->toBe(0)->and($snap['status'])->toBe('reconciled')->and($snap['evidence'])->toBe('owner_confirmed')
        ->and(DB::table('account_statements')->count())->toBe(0)->and(ProductionFix::baselineMarker())->not->toBeNull()->and(ProductionFix::marker())->not->toBeNull();

    // Profit, revenue and expenses are untouched; the dashboard's cash is the ledger plus collections, not a typed number.
    $after = $build();
    expect($after['net_profit']['amount'])->toBe($dashboardBefore['net_profit']['amount'])->and($after['expenses']['total'])->toBe($dashboardBefore['expenses']['total'])->and($after['revenue']['verified'])->toBe($dashboardBefore['revenue']['verified'])
        ->and($after['fund']['available_cash'])->toBe(4_900_000)->and($after['fund']['loans_receivable'])->toBe($dashboardBefore['fund']['loans_receivable'])
        ->and($this->co->as('board')->get('/')->getContent())->toContain('Opening cash baseline')->toContain('not profit or loss')->toContain('owner-confirmed');

    // It never runs twice, in either mode, and a new transaction moves cash and profit normally from the baseline.
    $this->artisan('sellbees:production-fix', pfBaselineOptions())->expectsOutputToContain('already been applied')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', pfApplyOptions())->expectsOutputToContain('already been completed')->assertExitCode(1);
    $this->co->expense('Rent', 'November office', '100', '2026-11-08');
    $later = $build();
    expect($later['fund']['available_cash'])->toBe(4_900_000 - 10_000)->and($later['expenses']['total'])->toBe($dashboardBefore['expenses']['total'] + 10_000)->and($later['net_profit']['amount'])->toBe($dashboardBefore['net_profit']['amount'] - 10_000);
});

it('cannot post the opening adjustment twice, even if the markers are removed', function () {
    $this->artisan('sellbees:production-fix', pfBaselineOptions())->assertExitCode(0);
    DB::table('audit_logs')->whereIn('action', [ProductionFix::BASELINE_MARKER, ProductionFix::MARKER])->delete();

    $this->artisan('sellbees:production-fix', pfBaselineOptions())->expectsOutputToContain('has already been posted')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', pfBaselineOptions(['--expected-cash' => '49000']))->expectsOutputToContain('has already been posted')->assertExitCode(1);
    expect(DB::table('journal_entries')->where('source_type', ProductionFix::BASELINE_SOURCE)->count())->toBe(1);
});

it('stops when the Board rejected a void, and converts a pending request instead of duplicating it', function () {
    $this->co->post('finance', 'payments.void-requests.store', ['reason' => 'x', 'evidence' => 'y'], 13)->assertSessionHasNoErrors();
    $this->co->post('board', 'finance.payment-voids.decide', ['decision' => 'reject', 'note' => 'The Board wants proof first'], PaymentVoidRequest::where('payment_id', 13)->sole())->assertSessionHasNoErrors();
    $state = pfFinancialState();
    $this->artisan('sellbees:production-fix', pfBaselineOptions())->expectsOutputToContain('payment #13 is REJECTED')->assertExitCode(1);
    expect(pfFinancialState())->toEqual($state)->and(DB::table('journal_entries')->where('source_type', ProductionFix::BASELINE_SOURCE)->count())->toBe(0);

    DB::table('payment_void_requests')->delete();
    $this->co->post('finance', 'payments.void-requests.store', ['reason' => 'x', 'evidence' => 'y'], 13)->assertSessionHasNoErrors();   // a PENDING request now
    $this->artisan('sellbees:production-fix', pfBaselineOptions())->assertExitCode(0);
    $rows = DB::table('payment_void_requests')->where('payment_id', 13)->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->status)->toBe('authorized')->and((int) $rows[0]->requested_by)->toBe($this->co->users['finance']->id)->and(DB::table('payment_void_requests')->count())->toBe(2);
});

it('supersedes the earlier cutover legacy-review items, attributed, and leaves other historical items open', function () {
    $this->artisan('sellbees:production-fix', pfApplyOptions())->assertExitCode(0);   // the technical cutover registers its items first
    expect(ReconciliationException::where('key', 'like', 'cutover:%')->where('status', 'open')->count())->toBe(3);

    $this->artisan('sellbees:production-fix', pfBaselineOptions())->assertExitCode(0);
    $items = ReconciliationException::all()->keyBy('key');
    expect($items['cutover:payment_void:8']->status)->toBe('resolved')->and($items['cutover:cash_difference']->status)->toBe('resolved')->and($items['cutover:cash_difference']->resolution_note)->toContain('NOT independently bank-verified')
        ->and((int) $items['cutover:payment_void:13']->resolved_by)->toBe($this->co->users['finance']->id)->and($items['cash:unassigned']->status)->toBe('open')
        ->and(DB::table('audit_logs')->where('action', ProductionFix::MARKER)->count())->toBe(1);
});

it('rolls the whole baseline back when a final check fails', function () {
    DB::table('loan_receivables')->where('id', 1)->update(['principal' => 800_001]);   // loans are not BDT 8,500, so the final check must stop the run
    $state = pfFinancialState();
    $entries = DB::table('journal_entries')->count();

    $this->artisan('sellbees:production-fix', pfBaselineOptions())->expectsOutputToContain('Loans receivable are not as expected')->assertExitCode(1);

    expect(DB::table('payments')->whereNotNull('voided_at')->count())->toBe(0)->and(DB::table('payment_void_requests')->count())->toBe(0)->and(DB::table('journal_entries')->count())->toBe($entries)
        ->and(pfFinancialState())->toEqual($state)->and(ProductionFix::baselineMarker())->toBeNull()->and(ProductionFix::marker())->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'like', 'production_fix.%')->orWhere('action', 'cash.baseline_set')->orWhere('action', 'payment.void_authorized')->count())->toBe(0);
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

/* ---------------------------------------------------------------- loan repayments verified AFTER the baseline cutoff */

/** The two loans of the backup repaid in full on 2026-11-09, verified, with an investor tracking allocation on the larger one: business that happened after the cutoff. */
function pfLaterRepayments(): array
{
    Carbon::setTestNow('2026-11-09 12:00:00');
    $ids = [];
    foreach ([1 => '8000', 2 => '500'] as $loanId => $amount) {
        $repayment = LoanReceivableService::recordRepayment(LoanReceivable::findOrFail($loanId), ['amount' => (int) $amount * 100, 'repaid_on' => '2026-11-09', 'idempotency_key' => (string) Str::uuid()], test()->co->users['finance']);
        LoanReceivableService::decideRepayment($repayment, true, test()->co->users['board']);
        $ids[$loanId] = $repayment->id;
    }
    $allocation = LoanRecoveryAllocationService::propose(LoanReceivableRepayment::findOrFail($ids[1]), test()->co->partner('C'), 800_000, 'Owner tracking request', null, null, test()->co->users['finance']);
    LoanRecoveryAllocationService::decide($allocation, true, test()->co->users['board']);

    return ['repayments' => $ids, 'allocation' => $allocation->id];
}

/** @return array<string, mixed> everything about the repayments and the allocation that the baseline must leave alone */
function pfRepaymentState(): array
{
    return [DB::table('loan_receivable_repayments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), DB::table('loan_recovery_allocations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('journal_entries')->where('source_type', 'loan_receivable_repayment')->orderBy('id')->get()->map(fn ($e) => (array) $e + ['lines' => DB::table('journal_lines')->where('journal_entry_id', $e->id)->orderBy('id')->get()->map(fn ($l) => (array) $l)->all()])->all()];
}

it('previews and applies the baseline when verified loan repayments exist after the cutoff, leaving them and the investor allocation untouched', function () {
    $later = pfLaterRepayments();
    expect(CashReconciliation::expectedCash('BDT'))->toBe(8_127_100);                                         // the 81,271 an unfixed database shows today
    $repaymentsBefore = pfRepaymentState();
    $backup = pfBackup();

    $this->artisan('sellbees:production-fix', ['--baseline' => true, '--cutoff' => '2026-11-08 12:00', '--owner-cash' => '49000', '--expected-cash' => '43571', '--backup-file' => $backup])
        ->expectsOutputToContain('before corrections: BDT 72,771.00')->expectsOutputToContain('Reconstructed historical cash after corrections: BDT 43,571.00')
        ->expectsOutputToContain('Legacy Opening Balance Adjustment / Historical Cash Difference: +BDT 5,429.00')->expectsOutputToContain('verified loan repayment #'.$later['repayments'][2].'): +BDT 500.00')
        ->expectsOutputToContain('verified loan repayment #'.$later['repayments'][1].'): +BDT 8,000.00')->expectsOutputToContain('what happened after it): BDT 57,500.00')
        ->doesntExpectOutputToContain('Cannot apply yet')->assertExitCode(0);
    expect(pfRepaymentState())->toEqual($repaymentsBefore);                                                    // the preview changed nothing

    $this->artisan('sellbees:production-fix', pfBaselineOptions(['--backup-file' => $backup, '--as' => $this->operator]))
        ->expectsOutputToContain('payment #8 (BDT 10,000.00) voided by administrative correction')->expectsOutputToContain('payment #13 (BDT 19,200.00) voided by administrative correction')
        ->expectsOutputToContain('Legacy Opening Balance Adjustment +BDT 5,429.00 posted')->assertExitCode(0);

    expect(pfRepaymentState())->toEqual($repaymentsBefore)                                                     // repayments, their journals and the allocation are exactly as before
        ->and(DB::table('loan_receivable_repayments')->count())->toBe(2)->and(DB::table('journal_entries')->where('source_type', 'loan_receivable_repayment')->count())->toBe(2)
        ->and(DB::table('journal_entries')->where('source_type', ProductionFix::BASELINE_SOURCE)->count())->toBe(1)
        ->and(CashReconciliation::expectedCash('BDT', '2026-11-08'))->toBe(4_900_000)                           // the owner's 49,000 at the cutoff
        ->and(CashReconciliation::expectedCash('BDT'))->toBe(5_750_000)                                          // 57,500 today
        ->and(CashReconciliation::snapshot()['difference'])->toBe(0)->and(DB::table('capital_entries')->where('status', 'verified')->sum('amount'))->toEqual(DB::table('capital_entries')->where('status', 'verified')->sum('amount'));
    $dash = BusinessDashboard::build(null, CarbonImmutable::parse('2026-11-09'));
    expect($dash['fund']['available_cash'])->toBe(5_750_000)->and($dash['fund']['loans_receivable'])->toBe(Ledger::debitBalance('assets.loans_receivable'))->and($dash['fund']['loan_recovery_allocated'])->toBe(800_000)
        ->and($dash['funding']['remaining'])->toBe(5_750_000)->and(ProductionFix::baselineMarker())->not->toBeNull();
});

it('still stops the baseline for any later cash entry that is not a verified loan repayment', function () {
    pfLaterRepayments();
    $this->co->expense('Rent', 'Later office rent', '100', '2026-11-09');                                       // a real later expense: the owner's balance cannot be compared with it
    $this->artisan('sellbees:production-fix', ['--baseline' => true, '--cutoff' => '2026-11-08 12:00', '--owner-cash' => '49000', '--expected-cash' => '43571', '--backup-file' => pfBackup()])
        ->expectsOutputToContain('Cannot apply yet: the ERP has a journal cash entry dated 2026-11-09, after the cutoff date')->assertExitCode(0);
    $this->artisan('sellbees:production-fix', pfBaselineOptions(['--expected-cash' => '43571']))->expectsOutputToContain('after the cutoff date')->assertExitCode(1);
    expect(ProductionFix::baselineMarker())->toBeNull()->and(DB::table('journal_entries')->where('source_type', ProductionFix::BASELINE_SOURCE)->count())->toBe(0)
        ->and(DB::table('payments')->whereNotNull('voided_at')->count())->toBe(0);
});

it('keeps the baseline reconciled when loan repayments dated the same day as the cutoff are verified after it was applied', function () {
    // Production's real state: the baseline was applied on 2026-11-08 (cutoff that day), and the two loan repayments were recorded and verified LATER, dated that same day.
    $this->artisan('sellbees:production-fix', pfBaselineOptions())->assertExitCode(0);
    expect(CashReconciliation::snapshot()['difference'])->toBe(0);
    foreach ([1 => '8000', 2 => '500'] as $loanId => $amount) {
        $repayment = LoanReceivableService::recordRepayment(LoanReceivable::findOrFail($loanId), ['amount' => (int) $amount * 100, 'repaid_on' => '2026-11-08', 'idempotency_key' => (string) Str::uuid()], $this->co->users['finance']);
        if ($loanId === 1) {
            expect(CashReconciliation::snapshot()['difference'])->toBe(0)->and(CashReconciliation::expectedCash('BDT'))->toBe(4_900_000);   // pending: not cash yet
        }
        LoanReceivableService::decideRepayment($repayment, true, $this->co->users['board']);
    }

    $snap = CashReconciliation::snapshot();
    expect(CashReconciliation::expectedCash('BDT'))->toBe(5_750_000)                                                    // 49,000 + 8,500 = 57,500 today
        ->and($snap['actual'])->toBe(4_900_000)->and($snap['expected'])->toBe(4_900_000)->and($snap['difference'])->toBe(0)->and($snap['status'])->toBe('reconciled')   // the owner's 49,000 is still compared with the 49,000 the books held when it was struck
        ->and(BusinessDashboard::build(null, CarbonImmutable::parse('2026-11-08'))['funding']['remaining'])->toBe(5_750_000);

    // Anything else booked after the baseline on the cutoff date is still a visible difference: only the verified repayments are treated as later business.
    $this->co->expense('Rent', 'Booked after the baseline, same date', '100', '2026-11-08');
    expect(CashReconciliation::snapshot()['difference'])->toBe(10_000)->and(CashReconciliation::expectedCash('BDT'))->toBe(5_740_000);
});
