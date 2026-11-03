<?php

use App\Models\AccountTransfer;
use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AccountingService;
use App\Services\AdvanceSettlementService;
use App\Services\Ledger;
use App\Services\LoanReceivableService;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingPayments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

/*
| Real concurrency, which SQLite's single in-memory connection cannot show: several PHP processes perform the same financial
| operation at the same moment against a committed MySQL test database. Skipped unless the suite runs on a local *_test MySQL
| (see the deployment checklist for the command). The schema is rebuilt with migrate:fresh instead of using RefreshDatabase because the worker
| processes must see committed rows.
*/
beforeEach(function () {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Needs a local MySQL test database (DB_CONNECTION=mysql, database name ending _test).');
    }
    // The worker processes need COMMITTED rows, so this file builds a fresh schema itself (a rollback-based trait cannot: older
    // down() methods cannot run on MySQL) and wipes it again afterwards.
    ccWipe(fresh: ! ($GLOBALS['cc_schema_ready'] ?? false));
    $GLOBALS['cc_schema_ready'] = true;
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $this->co->invest('A', '100000', '2026-11-05');
});

afterEach(function () {
    Carbon::setTestNow();
    if (config('database.default') === 'mysql') {
        ccWipe(fresh: false);
    }
});

/** Build the schema once (migrate:fresh is slow on MySQL), then just empty every table between tests. */
function ccWipe(bool $fresh): void
{
    if ($fresh) {
        Artisan::call('migrate:fresh', ['--force' => true]);

        return;
    }
    Schema::disableForeignKeyConstraints();
    foreach (collect(Schema::getTables())->pluck('name') as $table) {
        if ($table !== 'migrations') {
            DB::table($table)->truncate();
        }
    }
    Schema::enableForeignKeyConstraints();
}

/**
 * Run $count workers at once; returns each worker's decoded JSON result.
 *
 * @param  callable(int): array<string, mixed>  $argsFor
 * @return list<array<string, mixed>>
 */
function ccRun(string $operation, int $count, callable $argsFor): array
{
    $start = microtime(true) + 4.0;
    $procs = [];
    for ($i = 0; $i < $count; $i++) {
        $args = json_encode(['user' => test()->co->users['finance']->id, ...$argsFor($i), 'start' => $start]);
        $cmd = [PHP_BINARY, __DIR__.'/../Support/concurrency_worker.php', $operation, $args];
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, [...getenv(), 'FINANCE_REALTIME_FROM' => '2026-11-01',
            'FINANCE_SOURCING_BASIS' => 'accrual', 'FINANCE_SOURCING_ACCRUAL_FROM' => '2026-11-01', 'FINANCE_SOURCING_RECOGNITION' => 'lot_completed']);
    }
    $results = [];
    foreach ($procs as $i => $proc) {
        $out = stream_get_contents($pipes[$i][1]);
        $err = stream_get_contents($pipes[$i][2]);
        $exit = proc_close($proc);
        $results[] = json_decode($out, true) ?? ['ok' => false, 'error' => 'no output (exit '.$exit.'): '.$out.' | stderr: '.substr($err, 0, 600)];
    }
    // If no worker could even run, the setup is broken; say what they reported instead of failing on a count.
    if (collect($results)->where('ok', true)->isEmpty()) {
        test()->fail('No worker ran: '.json_encode($results));
    }

    return $results;
}

it('records a transfer submitted four times at once exactly once', function () {
    $key = (string) Str::uuid();
    $results = ccRun('transfer', 4, fn () => ['from' => $this->cash->id, 'to' => $this->bank->id, 'amount' => 30_000_00, 'date' => '2026-11-08', 'key' => $key]);

    expect(collect($results)->where('ok', true)->where('recorded', true)->count())->toBe(1)
        ->and(AccountTransfer::count())->toBe(1)->and(JournalEntry::where('source_type', 'transfer')->count())->toBe(1)
        ->and(AccountingService::balance($this->cash->id))->toBe(70_000_00)->and(AccountingService::balance($this->bank->id))->toBe(30_000_00);
});

it('never lets simultaneous transfers overdraw an account', function () {
    // 100,000 in cash; four different transfers of 60,000 race. Exactly one can win.
    $results = ccRun('transfer', 4, fn (int $i) => ['from' => $this->cash->id, 'to' => $this->bank->id, 'amount' => 60_000_00, 'date' => '2026-11-08', 'key' => (string) Str::uuid()]);

    expect(collect($results)->where('ok', true)->where('recorded', true)->count())->toBe(1)
        ->and(collect($results)->where('ok', false)->every(fn ($r) => str_contains($r['error'], 'Insufficient balance')))->toBeTrue()
        ->and(AccountingService::balance($this->cash->id))->toBe(40_000_00)->and(AccountingService::balance($this->bank->id))->toBe(60_000_00)
        ->and(AccountingService::totalCash())->toBe(100_000_00);
});

it('never pays more than an expense owes when payments race', function () {
    $expense = Expense::create(['category' => 'Supplies', 'description' => 'Vendor bill', 'amount' => 10_000_00, 'spent_on' => '2026-11-07', 'status' => 'verified', 'created_by' => $this->co->users['finance']->id,
        'verified_by' => $this->co->users['board']->id, 'verified_at' => now(), 'payment_status' => 'unpaid', 'paid_amount' => 0]);
    Ledger::post('2026-11-07', 'Vendor bill', 'expense', $expense->id, [['expense', null, 10_000_00, 0], ['liabilities', null, 0, 10_000_00]], null);

    $results = ccRun('pay_expense', 4, fn () => ['expense' => $expense->id, 'amount' => 6_000_00, 'date' => '2026-11-09', 'key' => (string) Str::uuid()]);

    expect(collect($results)->where('ok', true)->where('recorded', true)->count())->toBe(1)
        ->and(ExpensePayment::count())->toBe(1)->and($expense->refresh()->paid_amount)->toBe(6_000_00)
        ->and(AccountingService::totalCash())->toBe(94_000_00);
});

/** A verified loan of 8,000 lent on 2026-10-26 (the "Loan for Ahsan" correction), committed so the worker processes see it. */
function ccLoan(): LoanReceivable
{
    $co = test()->co;
    $expense = $co->expense('Loan', 'Loan for Ahsan', '8000', '2026-10-26');
    $loan = LoanReceivableService::propose($expense, ['borrower_partner_id' => $co->partner('B')->id], $co->users['finance']);
    LoanReceivableService::decide($loan, true, $co->users['board']);

    return $loan->refresh();
}

it('lets only one of several simultaneous loan repayments through when together they would exceed the loan', function () {
    $loan = ccLoan();
    // Four different 5,000 repayments race against 8,000 still owed: pending repayments reserve the balance, so only one fits.
    $results = ccRun('loan_repay', 4, fn () => ['loan' => $loan->id, 'amount' => 5_000_00, 'date' => '2026-11-06', 'key' => (string) Str::uuid()]);

    expect(collect($results)->where('ok', true)->where('recorded', true)->count())->toBe(1)
        ->and(collect($results)->where('ok', false)->every(fn ($r) => str_contains($r['error'], 'cannot exceed')))->toBeTrue()
        ->and(LoanReceivableRepayment::count())->toBe(1)->and($loan->refresh()->pendingRepayments())->toBe(5_000_00)->and($loan->outstanding())->toBe(8_000_00);
});

it('records one loan repayment submitted four times at once exactly once', function () {
    $loan = ccLoan();
    $key = (string) Str::uuid();
    $results = ccRun('loan_repay', 4, fn () => ['loan' => $loan->id, 'amount' => 3_000_00, 'date' => '2026-11-06', 'key' => $key]);

    expect(collect($results)->where('ok', true)->where('recorded', true)->count())->toBe(1)->and(LoanReceivableRepayment::count())->toBe(1);
});

it('posts a loan repayment once when four verifications race', function () {
    $loan = ccLoan();
    $repayment = LoanReceivableService::recordRepayment($loan, ['amount' => 3_000_00, 'repaid_on' => '2026-11-06'], $this->co->users['finance']);
    $cash = AccountingService::totalCash();

    $results = ccRun('loan_repay_verify', 4, fn () => ['repayment' => $repayment->id, 'user' => $this->co->users['board']->id]);

    expect(collect($results)->where('ok', true)->count())->toBe(1)
        ->and(collect($results)->where('ok', false)->every(fn ($r) => str_contains($r['error'], 'Already decided')))->toBeTrue()
        ->and(JournalEntry::where('source_type', 'loan_receivable_repayment')->count())->toBe(1)
        ->and(AccountingService::totalCash())->toBe($cash + 3_000_00)->and($loan->refresh()->outstanding())->toBe(5_000_00);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

/** An approved lot (10 pieces at BDT 4,000) under the accrual sourcing basis, committed so the worker processes see it. */
function ccAdvanceLot(): array
{
    config(['finance.sourcing_basis' => 'accrual', 'finance.sourcing_accrual_from' => '2026-11-01', 'finance.sourcing_recognition_trigger' => 'lot_completed']);
    $finance = test()->co->users['finance'];
    $supplier = Supplier::create(['name' => 'Race Supplier']);
    $lot = Sourcing::createLot(['title' => 'Race lot'], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => $supplier->id, 'description' => 'Goods', 'quantity' => '10', 'unit_price' => 400_000], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 75_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->co->users['board']);

    return [$lot->refresh(), $line, $supplier];
}

it('records one supplier advance submitted four times at once exactly once', function () {
    [$lot, , $supplier] = ccAdvanceLot();
    $key = (string) Str::uuid();
    $results = ccRun('adv_pay', 4, fn () => ['lot' => $lot->id, 'supplier' => $supplier->id, 'amount' => 4_000_000, 'date' => '2026-11-03', 'key' => $key]);

    expect(collect($results)->where('ok', true)->where('recorded', true)->count())->toBe(1)
        ->and(SupplierPayment::count())->toBe(1)->and(Expense::where('classification', 'supplier_advance')->count())->toBe(1);
});

it('proposes one set of advance settlements and one cost release however many people click at once', function () {
    [$lot, $line, $supplier] = ccAdvanceLot();
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $supplier->id, 'amount' => 4_000_000, 'paid_on' => '2026-11-03', 'purchase_order' => 'PO', 'reference' => 'R', 'evidence_reference' => 'E'], $this->co->users['finance']);
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $payment->expense)->assertSessionHasNoErrors();
    SourcingArrival::record($line->refresh(), '10', '10', '2026-11-08', $this->co->users['finance'], 'DN-1');

    $results = ccRun('adv_propose', 4, fn () => ['lot' => $lot->id, 'supplier' => $supplier->id]);
    expect(collect($results)->where('ok', true)->where('recorded', true)->count())->toBe(1)
        ->and(AdvanceSettlement::count())->toBe(1)->and((int) AdvanceSettlement::sum('amount'))->toBe(4_000_000);

    AdvanceSettlementService::decide(AdvanceSettlement::firstOrFail(), true, $this->co->users['board']);
    Sourcing::advance($lot, $this->co->users['finance']);
    Sourcing::advance($lot, $this->co->users['finance']);
    // (The workers run on the real clock, so the date must be one that is never in the future.)
    $releases = ccRun('cost_propose', 4, fn () => ['lot' => $lot->id, 'date' => '2026-11-01']);

    expect(collect($releases)->where('ok', true)->where('recorded', true)->count())->toBe(1)
        ->and(collect($releases)->where('ok', false)->every(fn ($r) => str_contains($r['error'], 'already been released')))->toBeTrue()
        ->and(CostRecognition::count())->toBe(1)->and((int) CostRecognition::sum('amount'))->toBe(4_000_000);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('records a customer payment submitted four times at once exactly once', function () {
    [$project] = [$this->co->project($this->co->client('Race Client'))];
    $this->co->segment($project, 'Build', '50000');
    $key = (string) Str::uuid();
    $results = ccRun('payment', 4, fn () => ['project' => $project->id, 'amount' => 20_000_00, 'date' => '2026-11-06', 'key' => $key]);

    test()->assertSame(1, Payment::count(), json_encode($results));
    expect(Payment::count())->toBe(1)->and(JournalEntry::where('source_type', 'payment')->count())->toBe(1)
        ->and(collect($results)->where('ok', true)->where('recorded', true)->count())->toBe(1)
        ->and(AccountingService::totalCash())->toBe(120_000_00);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});
