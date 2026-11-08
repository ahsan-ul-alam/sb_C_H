<?php

/*
| TEMPORARY: removed together with the sellbees:production-fix command after the one-time repair has succeeded.
| Rehearses the command against the rows of the 2026-11-08 production backup (loaded under their original ids) in an isolated test database.
*/

use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\PaymentVoidRequest;
use App\Services\Ledger;
use App\Services\ProductionFix;
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
});

afterEach(fn () => Carbon::setTestNow());

function pfFingerprint(): string
{
    return ProductionFix::report()['fingerprint'];
}

function pfApply(?string $fingerprint = null, ?string $database = null): array
{
    return ProductionFix::apply($database ?? DB::connection()->getDatabaseName(), $fingerprint ?? pfFingerprint());
}

/** Finance requests and a different Board member approves, through the real routes. */
function pfApproveVoid(int $paymentId): void
{
    test()->co->post('finance', 'payments.void-requests.store', ['reason' => 'Not received (owner confirmation)', 'evidence' => 'Owner written confirmation of receipts: 10,000 + 9,200 + 29,040'], $paymentId)->assertSessionHasNoErrors()->assertSessionMissing('error');
    test()->co->post('board', 'finance.payment-voids.decide', ['decision' => 'approve'], PaymentVoidRequest::where('payment_id', $paymentId)->sole())->assertSessionHasNoErrors()->assertSessionMissing('error');
}

function pfPaymentRows(): array
{
    return DB::table('payments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
}

it('previews without changing anything and reports both corrections as PENDING', function () {
    $before = [pfPaymentRows(), DB::table('invoices')->orderBy('id')->get()->all(), DB::table('audit_logs')->count()];

    $this->artisan('sellbees:production-fix')
        ->expectsOutputToContain('PREVIEW ONLY')->expectsOutputToContain('payment #8: PENDING')->expectsOutputToContain('payment #13: PENDING')
        ->expectsOutputToContain('Actual balance: Not Verified')->expectsOutputToContain('To apply: php artisan sellbees:production-fix --apply --expect-database=')
        ->assertExitCode(0);

    expect([pfPaymentRows(), DB::table('invoices')->orderBy('id')->get()->all(), DB::table('audit_logs')->count()])->toEqual($before)
        ->and(ProductionFix::marker())->toBeNull();
});

it('refuses --apply without the database name and fingerprint, or with the wrong ones', function () {
    $this->artisan('sellbees:production-fix', ['--apply' => true])->expectsOutputToContain('database name does not match')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName()])->expectsOutputToContain('--fingerprint')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => 'some_other_db', '--fingerprint' => pfFingerprint()])->expectsOutputToContain('database name does not match')->assertExitCode(1);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => 'stale'])->expectsOutputToContain('fingerprint mismatch')->assertExitCode(1);
    expect(DB::table('audit_logs')->where('action', 'like', 'production_fix.%')->count())->toBe(0);
});

it('applies the technical repairs but reports PENDING, not SUCCESS, while the voids await approval, and never touches a payment', function () {
    DB::table('invoices')->where('id', 6)->update(['amount_paid' => 0, 'status' => 'unpaid']);   // a derived total out of step with its payments
    DB::table('sb_number_sequence')->where('id', 1)->update(['last_value' => 1]);                  // the SB counter behind the numbers in use
    $payments = pfPaymentRows();

    $this->artisan('sellbees:production-fix')->expectsOutputToContain('stored 0/unpaid should be 1920000/paid')->expectsOutputToContain('SB counter 1 is behind')->assertExitCode(0);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => pfFingerprint()])
        ->expectsOutputToContain('TECHNICAL REPAIRS APPLIED, BUT THE REPAIR IS NOT COMPLETE')->assertExitCode(0);

    $six = Invoice::findOrFail(6);
    expect($six->amount_paid)->toBe(1_920_000)->and($six->status->value)->toBe('paid')->and((int) DB::table('sb_number_sequence')->value('last_value'))->toBe(3)
        ->and(pfPaymentRows())->toEqual($payments)                                                       // #8 and #13 are untouched: still active
        ->and(ProductionFix::marker())->toBeNull()                                                       // not complete: no marker, so it can be run again later
        ->and(DB::table('audit_logs')->where('action', 'production_fix.invoice_rebuilt')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'production_fix.sb_counter_raised')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'production_fix.partial')->count())->toBe(1);
});

it('completes after both approved voids: SUCCESS, a permanent marker, no second run, and no financial amount changed', function () {
    pfApproveVoid(8);
    pfApproveVoid(13);
    $financial = fn () => [DB::table('journal_lines')->get()->all(), DB::table('expenses')->get(['id', 'amount', 'status'])->all(), DB::table('loan_receivables')->get(['id', 'principal', 'status'])->all(),
        DB::table('invoices')->get(['id', 'total'])->all(), DB::table('payments')->get(['id', 'project_id', 'invoice_id', 'amount', 'paid_on', 'method'])->all()];
    $before = $financial();

    $this->artisan('sellbees:production-fix')->expectsOutputToContain('payment #8: APPROVED')->expectsOutputToContain('payment #13: APPROVED')->assertExitCode(0);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => pfFingerprint()])
        ->expectsOutputToContain('SUCCESS: every authorised repair is complete')->assertExitCode(0);

    expect($financial())->toEqual($before)->and(ProductionFix::marker())->not->toBeNull()
        ->and(DB::table('payments')->whereIn('id', [8, 13])->whereNotNull('voided_at')->count())->toBe(2)->and(DB::table('payments')->count())->toBe(10)   // kept, never deleted
        ->and(DB::table('payments')->whereIn('id', [7, 9, 14])->whereNotNull('voided_at')->count())->toBe(0)
        ->and(DB::table('payment_allocations')->where('payment_id', 13)->count())->toBe(1);
    $four = Invoice::findOrFail(4);
    $six = Invoice::findOrFail(6);
    $ten = Invoice::findOrFail(10);
    expect([$four->status->value, $four->balance()])->toBe(['paid', 0])->and([$six->status->value, $six->amount_paid, $six->balance()])->toBe(['partial', 920_000, 1_000_000])->and([$ten->status->value, $ten->balance()])->toBe(['paid', 0])
        ->and((int) DB::table('loan_receivables')->where('status', 'verified')->sum('principal'))->toBe(850_000);

    // Once successful it never runs again, however it is called; the preview still verifies.
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => pfFingerprint()])->expectsOutputToContain('already been applied')->assertExitCode(1);
    $this->artisan('sellbees:production-fix')->expectsOutputToContain('already applied successfully')->assertExitCode(0);
});

it('shows the signed difference from the live database after the approved voids, as an unreconciled difference with owner-confirmed evidence', function () {
    pfApproveVoid(8);
    pfApproveVoid(13);
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Cash in Hand']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'Bank + bKash']);
    foreach ([[$cash, 0], [$bank, 4_900_000]] as [$account, $balance]) {
        $s = StatementReconciliationService::record($account, ['statement_date' => '2026-11-08', 'statement_balance' => $balance, 'reference' => 'Owner written confirmation', 'evidence' => 'owner_confirmed'], $this->co->users['finance']);
        StatementReconciliationService::decide($s, true, $this->co->users['board']);
    }
    $entries = DB::table('journal_entries')->count();

    $this->artisan('sellbees:production-fix')
        ->expectsOutputToContain('ERP expected cash: BDT 43,571.00')->expectsOutputToContain('Actual balance: BDT 49,000.00 (owner-confirmed, not statement-verified')
        ->expectsOutputToContain('Unreconciled cash difference (actual - expected): +BDT 5,429.00')->expectsOutputToContain('no independent bank/bKash statement')
        ->assertExitCode(0);
    expect(DB::table('journal_entries')->count())->toBe($entries);   // no balancing entry, ever
});

it('fails closed when a record is not what the owner decision was made about', function () {
    foreach ([['payments', 9, ['amount' => 920_001]], ['invoices', 6, ['total' => 1_920_001]]] as [$table, $id, $change]) {
        DB::table($table)->where('id', $id)->update($change);
        $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => pfFingerprint()])
            ->expectsOutputToContain('Refused: the preconditions do not hold')->assertExitCode(1);
        $this->artisan('sellbees:production-fix')->expectsOutputToContain('Blocked')->assertExitCode(1);
        DB::table($table)->where('id', $id)->update($table === 'payments' ? ['amount' => 920_000] : ['total' => 1_920_000]);
    }

    // A void request on a payment the owner confirmed as valid, or a stray void elsewhere, also blocks it.
    DB::table('payment_void_requests')->insert(['payment_id' => 9, 'amount' => 920_000, 'reason' => 'x', 'evidence' => 'y', 'status' => 'pending', 'requested_by' => $this->co->users['finance']->id, 'active_payment_id' => 9, 'created_at' => now(), 'updated_at' => now()]);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => pfFingerprint()])->expectsOutputToContain('valid payment #9')->assertExitCode(1);
    DB::table('payment_void_requests')->delete();
    DB::table('payments')->where('id', 4)->update(['voided_at' => now()]);
    $this->artisan('sellbees:production-fix')->expectsOutputToContain('payment #4 is voided')->assertExitCode(1);
    DB::table('payments')->where('id', 4)->update(['voided_at' => null]);

    // A malformed or missing SB number, and a row with a missing parent, also block it.
    DB::table('clients')->where('id', 3)->update(['sb_number' => null]);
    $this->artisan('sellbees:production-fix')->expectsOutputToContain('have no SB number')->assertExitCode(1);
    DB::table('clients')->where('id', 3)->update(['sb_number' => 'SB-000003']);
    // (Inside the test transaction, which is never committed, so a dangling row can be simulated.)
    DB::connection()->getDriverName() === 'sqlite' ? DB::statement('PRAGMA defer_foreign_keys = ON') : DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    DB::table('payment_allocations')->where('id', 5)->update(['segment_id' => 999]);
    $this->artisan('sellbees:production-fix')->expectsOutputToContain('payment_allocations.segment_id')->assertExitCode(1);
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

    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => pfFingerprint()])
        ->expectsOutputToContain('Another run of this repair is in progress')->assertExitCode(1);
    $lock->release();
    expect(ProductionFix::marker())->toBeNull();
});

it('does not use the ledger or post anything: no journal entry is created by a preview or an apply', function () {
    $entries = DB::table('journal_entries')->count();
    $lines = DB::table('journal_lines')->count();
    $this->artisan('sellbees:production-fix')->assertExitCode(0);
    $this->artisan('sellbees:production-fix', ['--apply' => true, '--expect-database' => DB::connection()->getDatabaseName(), '--fingerprint' => pfFingerprint()])->assertExitCode(0);

    expect(DB::table('journal_entries')->count())->toBe($entries)->and(DB::table('journal_lines')->count())->toBe($lines)->and(Ledger::class)->toBeString();
});
