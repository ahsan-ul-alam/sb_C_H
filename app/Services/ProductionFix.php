<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * TEMPORARY. The logic behind `php artisan sellbees:production-fix`, a one-time, owner-approved production repair. It is removed together with the command once the
 * repair has succeeded (see the deployment notes); nothing else in the application refers to it.
 *
 * What it may change (and only with --apply, and only after every guard below passes): the DERIVED invoice totals (amount paid / status, recalculated from the
 * payments by the invoice's own method) and the client SB-number counter (raised, never lowered, to the highest number in use). It never touches a payment, a
 * journal, an invoice total, a loan, an account statement or a supplier record, and it never creates a void: the two erroneous Suchona Mart receipts (#8 and #13)
 * are voided only through the existing Finance-proposes / different-Board-member-approves workflow; here they are merely reported as PENDING or APPROVED.
 *
 * Guards: default read-only preview; --apply needs the database name and the state fingerprint printed by the preview; exact record and amount preconditions that fail
 * closed; a database advisory lock; a before/after hash of every financial amount that must be identical; and a permanent marker in the audit log that makes a
 * successful run impossible to repeat. The marker is written only when the whole repair is complete (both voids approved and every figure as expected).
 */
class ProductionFix
{
    public const VERSION = 'production-fix-2026-11';

    public const MARKER = 'production_fix.applied';

    private const LOCK = 'sellbees:production-fix';

    /** payment id => [project, invoice, amount in minor units]: the exact records the owner's decision is about. */
    private const PAYMENTS = [7 => [2, null, 1_000_000], 8 => [2, 6, 1_000_000], 9 => [2, 6, 920_000], 13 => [2, null, 1_920_000], 14 => [2, 10, 2_904_000]];

    private const VALID = [7, 9, 14];

    private const ERRONEOUS = [8, 13];

    /** invoice id => [project, total]: unchanged by this repair. */
    private const INVOICES = [4 => [2, 1_000_000], 6 => [2, 1_920_000], 10 => [2, 2_904_000]];

    /** @return object|null the marker of a successful run */
    public static function marker(): ?object
    {
        return DB::table('audit_logs')->where('action', self::MARKER)->orderBy('id')->first();
    }

    /**
     * Everything the command reports. Read-only.
     *
     * @return array<string, mixed>
     */
    public static function report(): array
    {
        $blockers = [];
        $checks = [];
        $check = function (string $label, bool $ok, string $detail = '') use (&$checks, &$blockers): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
            if (! $ok) {
                $blockers[] = $label.($detail !== '' ? ': '.$detail : '');
            }
        };

        foreach (['2026_12_06_100000_add_payment_voids', '2026_12_07_100000_add_statement_evidence_and_cutoff'] as $migration) {
            $check('Migration applied: '.$migration, DB::table('migrations')->where('migration', $migration)->exists(), 'run php artisan migrate first');
        }
        $schemaReady = Schema::hasColumn('payments', 'voided_at') && Schema::hasTable('payment_void_requests') && Schema::hasColumn('account_statements', 'evidence');
        $check('Void and statement-evidence columns exist', $schemaReady);

        $engine = self::engineProblems();
        $check('Every table uses InnoDB', $engine === [], implode(', ', $engine));
        $orphans = self::orphans();
        $check('No row points at a missing parent (foreign keys consistent)', $orphans === [], implode('; ', $orphans));

        $voids = [];
        $invoices = [];
        $drift = [];
        if ($schemaReady) {
            $identity = self::identityProblems();
            $check('Suchona Mart records are exactly as backed up (payments #7 #8 #9 #13 #14, invoices #4 #6 #10)', $identity === [], implode('; ', $identity));
            foreach (self::ERRONEOUS as $id) {
                $voids[$id] = self::voidState($id);
            }
            $unexpected = self::unexpectedVoids();
            $check('No other payment is voided or has a void request', $unexpected === [], implode('; ', $unexpected));
            $drift = self::invoiceDrift();
            foreach (self::INVOICES as $id => $_) {
                $invoice = Invoice::find($id);
                $invoices[$id] = $invoice ? ['number' => $invoice->number, 'total' => (int) $invoice->total, 'paid' => (int) $invoice->amount_paid, 'balance' => (int) $invoice->total - (int) $invoice->amount_paid, 'status' => $invoice->status->value] : null;
            }
        }

        $sb = self::sbProblems();
        $check('Client SB numbers are present, unique and well formed', $sb['blockers'] === [], implode('; ', $sb['blockers']));

        $cash = $schemaReady ? CashReconciliation::snapshot() : null;
        $outstanding = [];
        foreach ($voids as $id => $v) {
            if ($v['state'] !== 'APPROVED') {
                $outstanding[] = 'Payment #'.$id.' void: '.$v['state'].' ('.$v['next'].')';
            }
        }
        if ($cash !== null && $cash['status'] === 'not_verified') {
            $outstanding[] = 'Actual cash balance: Not Verified ('.$cash['reason'].')';
        } elseif ($cash !== null && $cash['evidence'] === 'owner_confirmed') {
            $outstanding[] = 'Actual cash balance is owner-confirmed only; no independent bank/bKash statement backs it yet';
        }

        return [
            'database' => DB::connection()->getDatabaseName(), 'applied' => self::marker() !== null, 'checks' => $checks, 'blockers' => $blockers, 'voids' => $voids, 'invoices' => $invoices,
            'drift' => $drift, 'sb_drift' => $sb['drift'], 'cash' => $cash, 'loans' => self::loanTotals(), 'outstanding' => $outstanding,
            'complete' => $blockers === [] && $drift === [] && $sb['drift'] === null && self::fullyCorrected($voids, $invoices),
            'fingerprint' => $schemaReady ? self::fingerprint() : null,
        ];
    }

    /**
     * Apply the authorised technical repairs. Throws (changing nothing) unless every guard holds.
     *
     * @return array{status: string, report: array<string, mixed>, repaired: list<string>}
     */
    public static function apply(string $expectDatabase, string $fingerprint): array
    {
        if ($expectDatabase === '' || $expectDatabase !== DB::connection()->getDatabaseName()) {
            throw new RuntimeException('The database name does not match: pass --expect-database=<the database the preview printed>.');
        }
        if ($fingerprint === '') {
            throw new RuntimeException('Pass --fingerprint=<the value the preview printed>: it proves the state being repaired is the state that was reviewed.');
        }

        return self::withLock(function () use ($fingerprint): array {
            if (self::marker() !== null) {
                throw new RuntimeException('This one-time repair has already been applied successfully. It will not run again.');
            }
            $report = self::report();
            if ($report['blockers'] !== []) {
                throw new RuntimeException("Refused: the preconditions do not hold.\n- ".implode("\n- ", $report['blockers']));
            }
            if ($report['fingerprint'] !== $fingerprint) {
                throw new RuntimeException('Refused: the database changed since the preview (fingerprint mismatch). Run the preview again and review it.');
            }

            $repaired = [];
            DB::transaction(function () use (&$repaired, $report): void {
                $before = self::financialHash();
                foreach ($report['drift'] as $d) {
                    $invoice = Invoice::whereKey($d['id'])->lockForUpdate()->firstOrFail();
                    $invoice->refreshPaymentStatus();
                    $repaired[] = 'invoice '.$d['number'].': paid '.$d['stored_paid'].' -> '.$d['computed_paid'].', '.$d['stored_status'].' -> '.$d['computed_status'];
                    Audit::log('production_fix.invoice_rebuilt', 'invoice '.$d['number'], end($repaired));
                }
                if ($report['sb_drift'] !== null) {
                    DB::table('sb_number_sequence')->where('id', 1)->update(['last_value' => $report['sb_drift']['max']]);
                    $repaired[] = 'SB counter '.$report['sb_drift']['last_value'].' -> '.$report['sb_drift']['max'];
                    Audit::log('production_fix.sb_counter_raised', 'sb_number_sequence', end($repaired));
                }
                if (self::financialHash() !== $before) {
                    throw new RuntimeException('A financial amount changed during the repair: rolled back.');
                }
                if (self::invoiceDrift() !== []) {
                    throw new RuntimeException('Invoice totals still differ from their payments after the rebuild: rolled back.');
                }
            });

            $after = self::report();
            $status = $after['complete'] ? 'SUCCESS' : 'PENDING';
            if ($status === 'SUCCESS') {
                Audit::log(self::MARKER, self::VERSION, 'fingerprint '.$fingerprint.'; repaired: '.($repaired === [] ? 'nothing to rebuild' : implode(' | ', $repaired)));
            } else {
                Audit::log('production_fix.partial', self::VERSION, 'technical repairs applied; outstanding: '.implode(' | ', $after['outstanding']));
            }

            return ['status' => $status, 'report' => $after, 'repaired' => $repaired];
        });
    }

    /** @template T @param  callable(): T  $run @return T */
    private static function withLock(callable $run): mixed
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if ((int) (DB::selectOne('SELECT GET_LOCK(?, 0) AS l', [self::LOCK])->l ?? 0) !== 1) {
                throw new RuntimeException('Another run of this repair is in progress.');
            }
            try {
                return $run();
            } finally {
                DB::select('SELECT RELEASE_LOCK(?)', [self::LOCK]);
            }
        }
        $lock = Cache::lock(self::LOCK, 600);
        if (! $lock->get()) {
            throw new RuntimeException('Another run of this repair is in progress.');
        }
        try {
            return $run();
        } finally {
            $lock->release();
        }
    }

    /** @return list<string> */
    private static function engineProblems(): array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }
        $rows = DB::select('SELECT TABLE_NAME t, ENGINE e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? AND (ENGINE IS NULL OR ENGINE <> ?)', ['BASE TABLE', 'InnoDB']);

        return array_map(fn ($r) => $r->t.' ('.($r->e ?? 'unknown').')', $rows);
    }

    /** @return list<string> */
    private static function orphans(): array
    {
        $found = [];
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $fk) {
                if (count($fk['columns']) !== 1 || count($fk['foreign_columns']) !== 1) {
                    continue;
                }
                $n = DB::table($table['name'].' as c')->leftJoin($fk['foreign_table'].' as p', 'c.'.$fk['columns'][0], '=', 'p.'.$fk['foreign_columns'][0])
                    ->whereNotNull('c.'.$fk['columns'][0])->whereNull('p.'.$fk['foreign_columns'][0])->count();
                if ($n > 0) {
                    $found[] = $table['name'].'.'.$fk['columns'][0].' -> '.$fk['foreign_table'].': '.$n.' row(s)';
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function identityProblems(): array
    {
        $problems = [];
        foreach (self::PAYMENTS as $id => [$project, $invoice, $amount]) {
            $p = DB::table('payments')->where('id', $id)->first();
            if (! $p) {
                $problems[] = 'payment #'.$id.' is missing';
            } elseif ((int) $p->project_id !== $project || ($p->invoice_id === null ? null : (int) $p->invoice_id) !== $invoice || (int) $p->amount !== $amount) {
                $problems[] = 'payment #'.$id.' is not project '.$project.' / invoice '.($invoice ?? 'none').' / '.$amount;
            }
        }
        foreach (self::INVOICES as $id => [$project, $total]) {
            $i = DB::table('invoices')->where('id', $id)->first();
            if (! $i) {
                $problems[] = 'invoice #'.$id.' is missing';
            } elseif ((int) $i->project_id !== $project || (int) $i->total !== $total || $i->voided_at !== null) {
                $problems[] = 'invoice #'.$id.' is not project '.$project.' / total '.$total.' / unvoided';
            }
        }
        foreach (self::VALID as $id) {
            if (DB::table('payments')->where('id', $id)->whereNotNull('voided_at')->exists() || DB::table('payment_void_requests')->where('payment_id', $id)->exists()) {
                $problems[] = 'valid payment #'.$id.' is voided or has a void request';
            }
        }
        foreach (self::ERRONEOUS as $id) {
            if (self::voidState($id)['state'] === 'INVALID') {
                $problems[] = 'payment #'.$id.' is voided without an approved request';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function unexpectedVoids(): array
    {
        $known = array_keys(self::PAYMENTS);
        $found = [];
        foreach (DB::table('payments')->whereNotNull('voided_at')->whereNotIn('id', $known)->pluck('id') as $id) {
            $found[] = 'payment #'.$id.' is voided';
        }
        foreach (DB::table('payment_void_requests')->whereNotIn('payment_id', $known)->pluck('payment_id') as $id) {
            $found[] = 'payment #'.$id.' has a void request';
        }

        return $found;
    }

    /** @return array{state: string, next: string} */
    private static function voidState(int $paymentId): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $latest = DB::table('payment_void_requests')->where('payment_id', $paymentId)->orderByDesc('id')->first();
        if ($payment && $payment->voided_at !== null) {
            return $latest && $latest->status === 'approved' ? ['state' => 'APPROVED', 'next' => 'voided through the approved workflow; the payment is kept'] : ['state' => 'INVALID', 'next' => 'voided without an approved request'];
        }
        if ($latest && $latest->status === 'pending') {
            return ['state' => 'PENDING', 'next' => 'a Finance request awaits a different Board member\'s approval (Finance > Payment Voids)'];
        }
        if ($latest && $latest->status === 'rejected') {
            return ['state' => 'REJECTED', 'next' => 'the Board rejected it; the payment stays active; the owner must decide'];
        }

        return ['state' => 'PENDING', 'next' => 'no request yet: Finance must request the void on the payment, then a different Board member approves'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function invoiceDrift(): array
    {
        $drift = [];
        foreach (Invoice::query()->orderBy('id')->get() as $invoice) {
            $paid = (int) $invoice->payments()->sum('amount') + $invoice->segmentPaymentsCredit();
            $status = match (true) {
                $invoice->total > 0 && $paid >= $invoice->total => InvoiceStatus::Paid,
                $paid > 0 => InvoiceStatus::Partial,
                default => InvoiceStatus::Unpaid,
            };
            if ($paid !== (int) $invoice->amount_paid || $status !== $invoice->status) {
                $drift[] = ['id' => $invoice->id, 'number' => $invoice->number, 'stored_paid' => (int) $invoice->amount_paid, 'computed_paid' => $paid, 'stored_status' => $invoice->status->value, 'computed_status' => $status->value];
            }
        }

        return $drift;
    }

    /** @return array{blockers: list<string>, drift: array<string, int>|null} */
    private static function sbProblems(): array
    {
        $blockers = [];
        if (! Schema::hasColumn('clients', 'sb_number') || ! Schema::hasTable('sb_number_sequence')) {
            return ['blockers' => ['the SB-number column or counter is missing'], 'drift' => null];
        }
        $numbers = DB::table('clients')->pluck('sb_number');
        if ($numbers->contains(null)) {
            $blockers[] = DB::table('clients')->whereNull('sb_number')->count().' client(s) have no SB number (needs a person, not a blind assignment)';
        }
        $filled = $numbers->filter();
        if ($filled->count() !== $filled->unique()->count()) {
            $blockers[] = 'duplicate SB numbers exist';
        }
        if ($filled->contains(fn ($n) => ! SbNumber::isValid($n))) {
            $blockers[] = 'a client has a malformed SB number';
        }
        $max = (int) $filled->map(fn ($n) => (int) substr((string) $n, 3))->max();
        $last = DB::table('sb_number_sequence')->where('id', 1)->value('last_value');
        if ($last === null) {
            $blockers[] = 'the SB counter row is missing';
        }

        return ['blockers' => $blockers, 'drift' => $last !== null && (int) $last < $max && $blockers === [] ? ['last_value' => (int) $last, 'max' => $max] : null];
    }

    /** @return array{count: int, principal: int, outstanding: int} */
    private static function loanTotals(): array
    {
        if (! Schema::hasTable('loan_receivables')) {
            return ['count' => 0, 'principal' => 0, 'outstanding' => 0];
        }
        $verified = DB::table('loan_receivables')->where('status', 'verified');
        $repaid = (int) DB::table('loan_receivable_repayments')->where('status', 'verified')->sum('amount');

        return ['count' => (int) (clone $verified)->count(), 'principal' => (int) (clone $verified)->sum('principal'), 'outstanding' => (int) (clone $verified)->sum('principal') - $repaid];
    }

    /** @param array<int, array{state: string}> $voids @param array<int, ?array<string, mixed>> $invoices */
    private static function fullyCorrected(array $voids, array $invoices): bool
    {
        if (count($voids) !== 2 || collect($voids)->contains(fn ($v) => $v['state'] !== 'APPROVED')) {
            return false;
        }
        $four = $invoices[4] ?? null;
        $six = $invoices[6] ?? null;
        $ten = $invoices[10] ?? null;

        return $four && $six && $ten && $four['status'] === 'paid' && $four['balance'] === 0 && $ten['status'] === 'paid' && $ten['balance'] === 0
            && $six['status'] === 'partial' && $six['paid'] === 920_000 && $six['balance'] === 1_000_000;
    }

    /** Every financial amount and link, excluding only what this repair may change (invoice amount paid / status) and a void's stamp. Identical before and after, or the run is rolled back. */
    private static function financialHash(): string
    {
        $parts = [];
        foreach ([
            'payments' => ['id', 'project_id', 'invoice_id', 'amount', 'paid_on', 'method', 'financial_account_id'], 'payment_allocations' => ['id', 'payment_id', 'segment_id', 'amount'],
            'invoices' => ['id', 'project_id', 'number', 'total'], 'invoice_items' => ['id', 'invoice_id', 'segment_id', 'amount'], 'segments' => ['id', 'project_id', 'amount'],
            'journal_entries' => ['id', 'entry_date', 'source_type', 'source_id'], 'journal_lines' => ['id', 'journal_entry_id', 'account', 'debit', 'credit', 'financial_account_id'],
            'expenses' => ['id', 'amount', 'status', 'category', 'spent_on'], 'capital_entries' => ['id', 'partner_id', 'amount', 'status'],
            'loan_receivables' => ['id', 'principal', 'status', 'expense_id'], 'loan_receivable_repayments' => ['id', 'amount', 'status'],
            'account_statements' => ['id', 'financial_account_id', 'statement_balance', 'status', 'verified_by'], 'supplier_payments' => ['id', 'amount', 'expense_id'],
            'sourcing_lots' => ['id', 'status', 'ownership', 'project_id'],
        ] as $table => $columns) {
            if (Schema::hasTable($table)) {
                $parts[$table] = DB::table($table)->orderBy('id')->get($columns)->map(fn ($r) => array_map(fn ($v) => $v === null ? null : (string) $v, (array) $r))->all();
            }
        }

        return hash('sha256', json_encode($parts));
    }

    /** The state being repaired: if anything changes between the preview and --apply, the fingerprint differs and the run is refused. */
    private static function fingerprint(): string
    {
        return hash('sha256', json_encode([
            DB::connection()->getDatabaseName(), self::financialHash(),
            DB::table('invoices')->orderBy('id')->get(['id', 'amount_paid', 'status'])->map(fn ($r) => (array) $r)->all(),
            DB::table('payments')->orderBy('id')->pluck('voided_at', 'id')->all(),
            DB::table('payment_void_requests')->orderBy('id')->get(['id', 'payment_id', 'status', 'decided_at'])->map(fn ($r) => (array) $r)->all(),
            DB::table('clients')->orderBy('id')->pluck('sb_number', 'id')->all(), DB::table('sb_number_sequence')->value('last_value'),
            DB::table('migrations')->count(),
        ]));
    }
}
