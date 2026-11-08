<?php

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\PaymentVoids;
use Illuminate\Support\Facades\DB;
use Tests\Support\SellbeesCompany;

if (! function_exists('approvedPaymentVoid')) {
    /** Void a customer payment through the service workflow with a Finance proposer and a different Board approver (for tests that do not boot a whole company). */
    function approvedPaymentVoid(Payment $payment): PaymentVoidRequest
    {
        $finance = SellbeesCompany::user('void-finance-'.uniqid().'@test.local', 'finance');
        $board = SellbeesCompany::user('void-board-'.uniqid().'@test.local', 'board_member');
        $request = PaymentVoids::propose($payment, $finance, 'Duplicate entry', 'STMT-1');

        return PaymentVoids::decide($request, $board, true, null);
    }
}

if (! function_exists('disposableDatabaseProblem')) {
    /**
     * Why the configured database must NOT be wiped or rebuilt by a test, or null when it is a disposable test database. Destructive test
     * commands (migrate:fresh, truncating every table) run only when ALL of these hold: the app is in the testing environment, the
     * connection is MySQL/MariaDB on the loopback interface, the port is not the application's own server (3306), and the database name
     * ends in "_test" (the approved name is sellbees_p1_test). Nothing here reads or trusts the application's .env database.
     */
    function disposableDatabaseProblem(): ?string
    {
        $default = config('database.default');
        $db = config('database.connections.'.$default);
        if (! app()->environment('testing')) {
            return 'APP_ENV is not "testing"';
        }
        if ($default !== 'mysql') {
            return 'the connection is "'.$default.'", not a local MySQL/MariaDB test server';
        }
        if (! in_array($db['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            return 'the database host "'.($db['host'] ?? '').'" is not the loopback interface';
        }
        if ((string) ($db['port'] ?? '3306') === '3306') {
            return 'port 3306 is the application\'s own database server; use the disposable instance on another port';
        }
        if (! str_ends_with((string) ($db['database'] ?? ''), '_test')) {
            return 'the database "'.($db['database'] ?? '').'" does not end in "_test" (approved name: sellbees_p1_test)';
        }

        return null;
    }
}

if (! function_exists('assertDisposableDatabase')) {
    /** Stop the calling test run before any destructive command when the database is not positively a disposable test database. */
    function assertDisposableDatabase(): void
    {
        if ($problem = disposableDatabaseProblem()) {
            throw new RuntimeException('Refusing to run destructive test commands: '.$problem.'.');
        }
    }
}

if (! function_exists('financeUser')) {
    /** A user holding one role, created once per test database. */
    function financeUser(string $role): User
    {
        $user = User::firstOrCreate(['email' => $role.'@x.test'], ['name' => $role, 'password' => 'password-123']);
        if ($user->wasRecentlyCreated) {
            $user->setRoles([$role]);
        }

        return $user;
    }
}

if (! function_exists('signPolicy')) {
    /** The Board has signed the policy (and, optionally, its profit ratio) so live allocations are possible. */
    function signPolicy(PolicyVersion $policy, bool $profitConfirmed = true): void
    {
        $policy->update(['effective_from' => '2020-01-01', 'approved_at' => now(), 'signed_reference' => 'RES-1', 'approved_by' => financeUser('board_member')->id]);
        if ($profitConfirmed) {
            $policy->update(['profit_confirmed_at' => now(), 'profit_signed_reference' => 'RES-2']);
        }
    }
}

if (! function_exists('dr')) {
    /** Debit-minus-credit of a ledger account straight from the journal rows (independent of the app's own balance helpers). */
    function dr(string $account, ?int $partnerId = null): int
    {
        $q = JournalLine::where('account', $account)->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId));

        return (int) $q->sum('debit') - (int) $q->sum('credit');
    }
}

if (! function_exists('largestRemainder')) {
    /**
     * Independent implementation of the largest-remainder split used to check the app's shared-cost allocation.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    function largestRemainder(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $floor = [];
        $rem = [];
        foreach ($weights as $k => $w) {
            $floor[$k] = intdiv($amount * $w, $total);
            $rem[$k] = ($amount * $w) % $total;
        }
        $left = $amount - array_sum($floor);
        $keys = array_keys($weights);
        usort($keys, fn ($a, $b) => [$rem[$b], $a] <=> [$rem[$a], $b]);
        foreach (array_slice($keys, 0, $left) as $k) {
            $floor[$k]++;
        }

        return $floor;
    }
}

if (! function_exists('ledgerAudit')) {
    /**
     * Ledger-wide integrity audit used at the end of the simulations: every entry balances, no source is posted twice, and every
     * entry points at a record that really exists. Entries with source type "audit" are the scenarios' declared opening reserve.
     *
     * @return array{entries: int, unbalanced: int, duplicates: int, orphans: list<string>}
     */
    function ledgerAudit(): array
    {
        $tables = ['expense' => 'expenses', 'capital' => 'capital_entries', 'period' => 'accounting_periods', 'allocation' => 'allocations', 'payout' => 'payouts',
            'sourcing_fund' => 'emergency_fund_requests', 'sourcing_fund_reversal' => 'emergency_fund_requests', 'resolution' => 'resolutions', 'exit' => 'exit_settlements',
            'opening' => 'opening_batches', 'revenue' => 'revenue_adjustments', 'order_collection' => 'order_collections', 'client_settlement' => 'client_settlements'];
        $entries = JournalEntry::all();
        $orphans = [];
        foreach ($entries as $e) {
            if ($e->source_type === 'audit' || $e->source_type === 'test') {
                continue;
            }
            $table = $tables[$e->source_type] ?? null;
            if (! $table || ! DB::table($table)->where('id', $e->source_id)->exists()) {
                $orphans[] = $e->source_type.'#'.$e->source_id;
            }
        }

        return [
            'entries' => $entries->count(),
            'unbalanced' => $entries->filter(fn ($e) => $e->lines()->sum('debit') !== $e->lines()->sum('credit') || $e->lines()->sum('debit') <= 0)->count(),
            'duplicates' => JournalEntry::selectRaw('source_type, source_id, COUNT(*) n')->groupBy('source_type', 'source_id')->get()->where('n', '>', 1)->count(),
            'orphans' => $orphans,
        ];
    }
}
