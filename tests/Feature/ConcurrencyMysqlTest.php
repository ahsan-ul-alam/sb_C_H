<?php

use App\Models\AccountTransfer;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\AccountingService;
use App\Services\Ledger;
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
        $args = json_encode([...$argsFor($i), 'user' => test()->co->users['finance']->id, 'start' => $start]);
        $cmd = [PHP_BINARY, __DIR__.'/../Support/concurrency_worker.php', $operation, $args];
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, [...getenv(), 'FINANCE_REALTIME_FROM' => '2026-11-01']);
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
