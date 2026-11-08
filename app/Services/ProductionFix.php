<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReconciliationException;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * TEMPORARY. The logic behind `php artisan sellbees:production-fix`, a one-time, owner-approved OPERATIONAL CUTOVER of the production ERP. It is removed together with the
 * command once it has succeeded (see the deployment notes); nothing else in the application refers to it.
 *
 * It reports TWO independent results:
 *   TECHNICAL CUTOVER        COMPLETE | BLOCKED   the database is sound and the ERP can be operated. Only genuine technical failures block it: a schema or migration gap,
 *                            a non-InnoDB table, a dangling foreign key, an unbalanced or doubled journal entry, a verified expense or capital entry that never reached the ledger,
 *                            a malformed SB number, or records that are not what the owner's decisions were made about.
 *   FINANCIAL RECONCILIATION COMPLETE | PENDING   the historical financial questions are answered with evidence. They NEVER block the cutover: the two erroneous receipts
 *                            (#8, #13) awaiting the Payment Void workflow, the unreconciled cash difference, account statements, receipts and lots still to be verified.
 *
 * What --apply may change: the DERIVED invoice totals (amount paid / status, recalculated by the invoice's own method), the SB-number counter (raised, never lowered), and it REGISTERS
 * the open historical items in the existing reconciliation-exceptions register (status "legacy review", visible on Finance > Historical Reconciliation, resolvable only by a second person
 * with evidence). It never touches a payment, journal, invoice total, loan, statement, receipt, expense or supplier record, never creates or approves a void, never marks anything
 * verified, and never posts a balancing entry. A before/after hash of every financial amount must be identical or the run rolls back.
 *
 * The permanent marker in the audit log certifies the TECHNICAL cutover only. It says in so many words that financial reconciliation is not certified.
 */
class ProductionFix
{
    public const VERSION = 'production-fix-2026-11';

    public const MARKER = 'production_fix.applied';

    public const BASELINE_MARKER = 'production_fix.baseline_applied';

    public const CONFIRMATION = 'I AUTHORIZE THE IRREVERSIBLE LEGACY BASELINE CUTOVER';

    /** The journal source of the one-time Legacy Opening Balance Adjustment (unique per source, so it can be posted only once). */
    public const BASELINE_SOURCE = 'legacy_cash_baseline';

    private const LOCK = 'sellbees:production-fix';

    /** payment id => [project, invoice, amount in minor units]: the exact records the owner's decision is about. */
    private const PAYMENTS = [7 => [2, null, 1_000_000], 8 => [2, 6, 1_000_000], 9 => [2, 6, 920_000], 13 => [2, null, 1_920_000], 14 => [2, 10, 2_904_000]];

    private const VALID = [7, 9, 14];

    private const ERRONEOUS = [8, 13];

    /** invoice id => [project, total]: unchanged by this cutover. */
    private const INVOICES = [4 => [2, 1_000_000], 6 => [2, 1_920_000], 10 => [2, 2_904_000]];

    /** @return object|null the marker of a successful technical cutover */
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
        $check('Every journal entry balances and none is posted twice', IntegrityGate::ledgerBlockers() === [], implode('; ', IntegrityGate::ledgerBlockers()));
        $check('Every verified expense and capital entry reached the ledger', IntegrityGate::sourceBlockers() === [], implode('; ', IntegrityGate::sourceBlockers()));

        $voids = [];
        $invoices = [];
        $drift = [];
        if ($schemaReady) {
            $identity = self::identityProblems();
            $check('Suchona Mart records are exactly as backed up (payments #7 #8 #9 #13 #14, invoices #4 #6 #10)', $identity === [], implode('; ', $identity));
            foreach (self::ERRONEOUS as $id) {
                $voids[$id] = self::voidState($id);
            }
            $improper = self::improperVoids();
            $check('No payment is voided outside the approved workflow', $improper === [], implode('; ', $improper));
            $drift = self::invoiceDrift();
            foreach (self::INVOICES as $id => $_) {
                $invoice = Invoice::find($id);
                $invoices[$id] = $invoice ? ['number' => $invoice->number, 'total' => (int) $invoice->total, 'paid' => (int) $invoice->amount_paid, 'balance' => (int) $invoice->total - (int) $invoice->amount_paid, 'status' => $invoice->status->value] : null;
            }
        }

        $sb = self::sbProblems();
        $check('Client SB numbers are present, unique and well formed', $sb['blockers'] === [], implode('; ', $sb['blockers']));

        $cash = $schemaReady ? CashReconciliation::snapshot() : null;
        $marker = self::marker();
        $technical = $marker !== null ? 'COMPLETE' : ($blockers === [] ? 'READY' : 'BLOCKED');
        $outstanding = self::outstanding($voids, $cash, $schemaReady);
        // Financial reconciliation is complete when the duplicate receipts are corrected, the invoices read as confirmed, and the cash balances at the cutoff. The evidence behind the
        // actual balance (owner-confirmed or statement-verified) is reported separately and is never upgraded.
        $financial = self::fullyCorrected($voids, $invoices) && $cash !== null && $cash['status'] === 'reconciled' && IntegrityGate::statementBlockers() === [];

        return [
            'database' => DB::connection()->getDatabaseName(), 'applied' => $marker !== null, 'checks' => $checks, 'blockers' => $blockers, 'voids' => $voids, 'invoices' => $invoices,
            'drift' => $drift, 'sb_drift' => $sb['drift'], 'cash' => $cash, 'loans' => self::loanTotals(), 'outstanding' => $outstanding, 'technical' => $technical,
            'financial' => $financial ? 'COMPLETE' : 'PENDING', 'evidence' => $cash['evidence'] ?? null, 'baseline_applied' => self::baselineMarker() !== null,
            'fingerprint' => $schemaReady ? self::fingerprint() : null,
        ];
    }

    /** @return object|null the marker of the one-time financial baseline */
    public static function baselineMarker(): ?object
    {
        return DB::table('audit_logs')->where('action', self::BASELINE_MARKER)->orderBy('id')->first();
    }

    private static function corrected(string $state): bool
    {
        return in_array($state, ['APPROVED', 'AUTHORIZED'], true);
    }

    /**
     * Complete the technical cutover. Throws (changing nothing) unless every guard holds. Historical financial exceptions never prevent it.
     *
     * @return array{technical: string, financial: string, report: array<string, mixed>, repaired: list<string>, registered: int}
     */
    public static function apply(string $expectDatabase, string $fingerprint, string $operatorEmail): array
    {
        $operator = self::guardApply($expectDatabase, $fingerprint, $operatorEmail);

        return self::withLock(function () use ($fingerprint, $operator): array {
            if (self::marker() !== null) {
                throw new RuntimeException('This one-time cutover has already been completed. It will not run again.');
            }
            $report = self::report();
            if ($report['blockers'] !== []) {
                throw new RuntimeException("Refused: technical preconditions do not hold.\n- ".implode("\n- ", $report['blockers']));
            }
            if ($report['fingerprint'] !== $fingerprint) {
                throw new RuntimeException('Refused: the database changed since the preview (fingerprint mismatch). Run the preview again and review it.');
            }

            $repaired = [];
            $registered = 0;
            Auth::setUser($operator);   // every audit line below carries the person who ran it
            DB::transaction(function () use (&$repaired, &$registered, $report, $operator): void {
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
                $registered = self::registerLegacyReview($operator, $report);
                if (self::financialHash() !== $before) {
                    throw new RuntimeException('A financial amount changed during the cutover: rolled back.');
                }
                if (self::invoiceDrift() !== []) {
                    throw new RuntimeException('Invoice totals still differ from their payments after the rebuild: rolled back.');
                }
                $cutoff = today()->toDateString();
                Audit::log('production_fix.owner_confirmed', 'Suchona Mart receipts', 'OWNER-CONFIRMED, NOT BANK-VERIFIED. Cutoff '.$cutoff.'; recorded by '.$operator->email.'. Valid receipts: #7 '.self::money(1_000_000).' onboarding, #9 '.self::money(920_000).' product and marketing, #14 '.self::money(2_904_000).' another product lot. Erroneous receipts: #8 '.self::money(1_000_000).' and #13 '.self::money(1_920_000).', kept and counted until voided through the Payment Void workflow (Finance request, different Board member approval).');
                Audit::log(self::MARKER, self::VERSION, 'TECHNICAL CUTOVER COMPLETE at cutoff '.$cutoff.' by '.$operator->email.'. Financial reconciliation is NOT certified by this marker and remains pending: '.$registered.' historical item(s) registered for legacy review. Fingerprint '.$report['fingerprint'].'. Repaired: '.($repaired === [] ? 'nothing to rebuild' : implode(' | ', $repaired)));
            });

            $after = self::report();

            return ['technical' => 'COMPLETE', 'financial' => $after['financial'], 'report' => $after, 'repaired' => $repaired, 'registered' => $registered];
        });
    }

    /** The checks every --apply shares: the right database, the reviewed state, and a named Finance or Board operator to attribute every action to. */
    private static function guardApply(string $expectDatabase, string $fingerprint, string $operatorEmail): User
    {
        if ($expectDatabase === '' || $expectDatabase !== DB::connection()->getDatabaseName()) {
            throw new RuntimeException('The database name does not match: pass --expect-database=<the database the preview printed>.');
        }
        if ($fingerprint === '') {
            throw new RuntimeException('Pass --fingerprint=<the value the preview printed>: it proves the state being repaired is the state that was reviewed.');
        }
        $operator = User::where('email', $operatorEmail)->first();
        if (! $operator || ! $operator->hasPermission('reconcile.review')) {
            throw new RuntimeException('Pass --as=<email of the Finance or Board user running this>: every action is attributed to that person.');
        }

        return $operator;
    }

    /**
     * The exact plan of the one-time financial baseline for the given inputs, and every reason it cannot be applied. Read-only. In preview the optional inputs may be missing;
     * with $strict (--apply) every input is required.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function baselinePlan(array $in, bool $strict = false): array
    {
        $problems = [];
        $currency = (string) config('finance.currency');
        $plan = ['cutoff_at' => null, 'cutoff_date' => null, 'owner_cash' => null, 'supplied_expected' => null, 'expected_before' => null, 'voids' => [], 'void_total' => 0, 'expected_after' => null,
            'adjustment' => null, 'backup' => null, 'ledger_cash_before' => null];

        if (DB::table('journal_entries')->where('source_type', self::BASELINE_SOURCE)->exists()) {
            $problems[] = 'the Legacy Opening Balance Adjustment has already been posted; it can be posted only once';
        }
        $cutoffText = trim((string) ($in['cutoff'] ?? ''));
        $cutoff = null;
        if ($cutoffText !== '') {
            try {
                $cutoff = Carbon::createFromFormat('Y-m-d H:i', $cutoffText);
                $cutoff = $cutoff && $cutoff->format('Y-m-d H:i') === $cutoffText ? $cutoff : null;
            } catch (\Throwable) {
                $cutoff = null;
            }
            if ($cutoff === null) {
                $problems[] = '--cutoff must look like 2026-11-08 12:00 (the date and time the owner\'s balance is true)';
            } elseif ($cutoff->isFuture()) {
                $problems[] = 'the cutoff is in the future';
            }
        } elseif ($strict) {
            $problems[] = '--cutoff is required';
        }
        foreach (['owner_cash' => '--owner-cash', 'expected_cash' => '--expected-cash'] as $key => $flag) {
            $raw = trim((string) ($in[$key] ?? ''));
            if ($raw === '') {
                $strict ? $problems[] = $flag.' is required' : null;
            } elseif (! preg_match(Money::AMOUNT_REGEX, $raw)) {
                $problems[] = $flag.' must be an amount such as 49000 or 49000.00';
            } else {
                $plan[$key === 'owner_cash' ? 'owner_cash' : 'supplied_expected'] = Money::toMinor($raw);
            }
        }

        if ($cutoff !== null && ! $cutoff->isFuture() && Schema::hasColumn('payments', 'voided_at')) {
            $date = $cutoff->toDateString();
            $plan['cutoff_at'] = $cutoff->format('Y-m-d H:i');
            $plan['cutoff_date'] = $date;
            try {
                AccountingService::assertOpen($date, 'the baseline adjustment');
            } catch (RuntimeException $e) {
                $problems[] = $e->getMessage();
            }
            $lastCash = DB::table('journal_entries as e')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')->where('l.account', 'cash')->max('e.entry_date');
            $lastPayment = DB::table('payments')->whereNull('voided_at')->max('paid_on');
            foreach (['a journal cash entry' => $lastCash, 'a customer payment' => $lastPayment] as $what => $latest) {
                if ($latest !== null && substr((string) $latest, 0, 10) > $date) {
                    $problems[] = 'the ERP has '.$what.' dated '.substr((string) $latest, 0, 10).', after the cutoff date: the owner\'s balance cannot be compared with it';
                }
            }
            $plan['ledger_cash_before'] = (int) Ledger::debitBalance('cash');
            $plan['expected_before'] = CashReconciliation::expectedCash($currency, $date);
            foreach (self::ERRONEOUS as $id) {
                $state = self::voidState($id)['state'];
                $payment = DB::table('payments')->where('id', $id)->first();
                if (self::corrected($state)) {
                    continue;
                }
                if (! $payment || in_array($state, ['REJECTED', 'INVALID'], true)) {
                    $problems[] = 'payment #'.$id.' is '.$state.': the Board decided otherwise or the record is not as expected; the correction stops';

                    continue;
                }
                if (substr((string) $payment->paid_on, 0, 10) > $date) {
                    $problems[] = 'payment #'.$id.' is dated after the cutoff';
                }
                $plan['voids'][$id] = (int) $payment->amount;
            }
            $plan['void_total'] = (int) array_sum($plan['voids']);
            $plan['expected_after'] = $plan['expected_before'] - $plan['void_total'];
            if ($plan['owner_cash'] !== null) {
                $plan['adjustment'] = $plan['owner_cash'] - $plan['expected_after'];
            }
            if ($plan['supplied_expected'] !== null && $plan['supplied_expected'] !== $plan['expected_after']) {
                $problems[] = 'the expected cash after the corrections is '.self::money($plan['expected_after']).', not the '.self::money($plan['supplied_expected']).' you supplied: the correction stops';
            }
        }

        $backup = self::checkBackup(isset($in['backup']) && $in['backup'] !== '' ? (string) $in['backup'] : null);
        $plan['backup'] = $backup['info'];
        if ($backup['problem'] !== null && ($strict || ! empty($in['backup']))) {
            $problems[] = $backup['problem'];
        }
        if ($strict) {
            if (mb_strlen(trim((string) ($in['authorization'] ?? ''))) < 20) {
                $problems[] = '--owner-authorization must say who authorized this, when and how (at least 20 characters)';
            }
            if (($in['confirm'] ?? null) !== self::CONFIRMATION) {
                $problems[] = '--confirm must be exactly: '.self::CONFIRMATION;
            }
        }
        $plan['problems'] = $problems;

        return $plan;
    }

    /**
     * Apply the one-time financial baseline: (1) the two duplicate receipts are voided by an EXPLICIT, attributed administrative correction (never presented as a Board approval),
     * (2) every invoice, segment and total is recalculated by the application's own methods, (3) the difference between the reconstructed historical cash and the owner-confirmed
     * actual cash is posted ONCE as a balanced Legacy Opening Balance Adjustment (Dr cash / Cr opening equity: never revenue, profit, expense, loan, supplier payment or partner
     * capital), and (4) the original figures, the bridge and the authorization are recorded permanently. Everything is one transaction; any failed check rolls it all back.
     *
     * @param  array<string, mixed>  $in
     * @return array{technical: string, financial: string, report: array<string, mixed>, plan: array<string, mixed>, repaired: list<string>}
     */
    public static function applyBaseline(array $in): array
    {
        $operator = self::guardApply((string) ($in['database'] ?? ''), (string) ($in['fingerprint'] ?? ''), (string) ($in['operator'] ?? ''));

        return self::withLock(function () use ($in, $operator): array {
            if (self::baselineMarker() !== null) {
                throw new RuntimeException('The one-time financial baseline has already been applied. It will not run again.');
            }
            $report = self::report();
            if ($report['blockers'] !== []) {
                throw new RuntimeException("Refused: technical preconditions do not hold.\n- ".implode("\n- ", $report['blockers']));
            }
            if ($report['fingerprint'] !== (string) $in['fingerprint']) {
                throw new RuntimeException('Refused: the database changed since the preview (fingerprint mismatch). Run the preview again and review it.');
            }
            $plan = self::baselinePlan($in, strict: true);
            if ($plan['problems'] !== []) {
                throw new RuntimeException("Refused: the baseline cannot be applied.\n- ".implode("\n- ", $plan['problems']));
            }

            $repaired = [];
            Auth::setUser($operator);
            DB::transaction(function () use (&$repaired, $report, $plan, $in, $operator): void {
                $skip = ['journal_entries', 'journal_lines', 'payment_void_requests'];
                $maxEntry = (int) DB::table('journal_entries')->max('id');
                $journalBefore = self::journalHash($maxEntry);
                $before = self::financialHash($skip);
                $loansBefore = self::loanTotals();

                foreach (array_keys($plan['voids']) as $id) {
                    $repaired[] = self::administrativeVoid($id, $operator, trim((string) $in['authorization']));
                }
                foreach (self::invoiceDrift() as $d) {
                    Invoice::whereKey($d['id'])->lockForUpdate()->firstOrFail()->refreshPaymentStatus();
                    $repaired[] = 'invoice '.$d['number'].': paid '.$d['stored_paid'].' -> '.$d['computed_paid'].', '.$d['stored_status'].' -> '.$d['computed_status'];
                    Audit::log('production_fix.invoice_rebuilt', 'invoice '.$d['number'], end($repaired));
                }
                if ($report['sb_drift'] !== null) {
                    DB::table('sb_number_sequence')->where('id', 1)->update(['last_value' => $report['sb_drift']['max']]);
                    $repaired[] = 'SB counter '.$report['sb_drift']['last_value'].' -> '.$report['sb_drift']['max'];
                    Audit::log('production_fix.sb_counter_raised', 'sb_number_sequence', end($repaired));
                }

                $adjustment = (int) $plan['adjustment'];
                $entryId = null;
                if ($adjustment !== 0) {
                    $abs = abs($adjustment);
                    $memo = 'Legacy Opening Balance Adjustment / Historical Cash Difference (cutoff '.$plan['cutoff_at'].'; owner-confirmed, not bank-verified; not revenue, profit or loss)';
                    $lines = $adjustment > 0 ? [['cash', null, $abs, 0, null], ['equity.opening', null, 0, $abs]] : [['equity.opening', null, $abs, 0], ['cash', null, 0, $abs, null]];
                    $entryId = Ledger::post($plan['cutoff_date'], $memo, self::BASELINE_SOURCE, 1, $lines, $operator->id)->id;
                    $repaired[] = 'Legacy Opening Balance Adjustment '.($adjustment > 0 ? '+' : '-').self::money($abs).' posted as journal entry #'.$entryId.' (Dr/Cr cash and opening equity)';
                }
                Audit::log('cash.baseline_set', 'legacy cash baseline', json_encode([
                    'version' => self::VERSION, 'cutoff_at' => $plan['cutoff_at'], 'cutoff_date' => $plan['cutoff_date'], 'evidence' => 'owner_confirmed', 'owner_cash' => $plan['owner_cash'],
                    'reconstructed_expected_before_corrections' => $plan['expected_before'], 'duplicate_receipts_removed' => $plan['void_total'], 'reconstructed_expected' => $plan['expected_after'],
                    'adjustment' => $adjustment, 'journal_entry_id' => $entryId, 'authorization' => trim((string) $in['authorization']), 'operator' => $operator->email,
                    'backup' => ['file' => basename((string) $plan['backup']['path']), 'sha256' => $plan['backup']['sha256'], 'bytes' => $plan['backup']['size'], 'modified' => $plan['backup']['modified']],
                ]));
                self::supersedeLegacyReview($operator);

                // Nothing may differ from what the owner authorized, or the whole correction is rolled back.
                if (self::financialHash($skip) !== $before) {
                    throw new RuntimeException('A financial amount, link or verification state changed beyond the authorized correction: rolled back.');
                }
                if (self::journalHash($maxEntry) !== $journalBefore || DB::table('journal_entries')->where('id', '>', $maxEntry)->count() !== ($adjustment === 0 ? 0 : 1)) {
                    throw new RuntimeException('The journal changed beyond the single baseline entry: rolled back.');
                }
                if (self::loanTotals() !== $loansBefore || $loansBefore['outstanding'] !== 850_000) {
                    throw new RuntimeException('Loans receivable are not as expected (BDT 8,500): rolled back.');
                }
                if (CashReconciliation::expectedCash((string) config('finance.currency'), $plan['cutoff_date']) !== $plan['owner_cash']) {
                    throw new RuntimeException('Expected cash does not equal the owner-confirmed balance after the adjustment: rolled back.');
                }
                if ((int) DB::table('payments')->where('project_id', 2)->whereNull('voided_at')->sum('amount') !== array_sum(array_map(fn ($id) => self::PAYMENTS[$id][2], self::VALID))) {
                    throw new RuntimeException('Suchona Mart valid receipts are not the owner-confirmed total: rolled back.');
                }
                $after = self::report();
                if (! self::fullyCorrected($after['voids'], $after['invoices']) || $after['drift'] !== []) {
                    throw new RuntimeException('Invoices #4, #6 and #10 are not in the confirmed state after the correction: rolled back.');
                }
                $details = 'cutoff '.$plan['cutoff_at'].' by '.$operator->email.'. Reconstructed historical cash '.self::money((int) $plan['expected_after']).' + adjustment '.self::money($adjustment).' = owner-confirmed '.self::money((int) $plan['owner_cash']).' (NOT independently bank-verified).';
                if (self::marker() === null) {
                    Audit::log(self::MARKER, self::VERSION, 'TECHNICAL CUTOVER COMPLETE together with the financial baseline, '.$details);
                }
                Audit::log(self::BASELINE_MARKER, self::VERSION, 'FINANCIAL BASELINE APPLIED, '.$details.' Duplicate receipts voided by administrative correction: '.implode(', ', array_map(fn ($id) => '#'.$id, array_keys($plan['voids']))).'.');
            });

            $after = self::report();

            return ['technical' => $after['technical'], 'financial' => $after['financial'], 'report' => $after, 'plan' => $plan, 'repaired' => $repaired];
        });
    }

    /**
     * The explicit one-time administrative correction of one erroneous receipt: the same guards, recalculation and non-destructive stamp as the normal void, but recorded as what it
     * is (status "authorized", decided by the named operator, "not a Board approval"). A pending request is converted; a rejected one stops the run. The payment and its
     * allocations are kept. Raw writes are used on purpose so no notification claims a Board decision is awaited.
     */
    private static function administrativeVoid(int $paymentId, User $operator, string $authorization): string
    {
        $payment = Payment::withVoided()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
        if ($blockers = PaymentVoids::blockers($payment)) {
            throw new RuntimeException('Payment #'.$paymentId.' cannot be voided: '.implode(' ', $blockers));
        }
        $invoice = $payment->invoice_id ? Invoice::whereKey($payment->invoice_id)->lockForUpdate()->first() : null;
        $segmentIds = $payment->allocations()->pluck('segment_id')->all();
        $note = 'ONE-TIME OWNER-AUTHORIZED ADMINISTRATIVE CORRECTION recorded by '.$operator->email.'; this is NOT a Board member approval.';
        $row = [
            'amount' => (int) $payment->amount, 'reason' => 'Owner-confirmed erroneous duplicate receipt (never received)', 'evidence' => mb_substr('Owner authorization: '.$authorization, 0, 255), 'status' => 'authorized',
            'decided_by' => $operator->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 255), 'active_payment_id' => $paymentId, 'updated_at' => now(),
        ];
        $pending = DB::table('payment_void_requests')->where('payment_id', $paymentId)->where('status', 'pending')->orderByDesc('id')->first();
        $pending ? DB::table('payment_void_requests')->where('id', $pending->id)->update($row) : DB::table('payment_void_requests')->insert($row + ['payment_id' => $paymentId, 'requested_by' => $operator->id, 'created_at' => now()]);

        DB::table('payments')->where('id', $paymentId)->whereNull('voided_at')->update(['voided_at' => now()]);
        $invoice?->refreshPaymentStatus();
        Invoice::refreshBillingSegments($segmentIds, $invoice?->id);
        $line = 'payment #'.$paymentId.' ('.self::money((int) $payment->amount).') voided by administrative correction; the payment and its allocations are kept';
        Audit::log('payment.void_authorized', 'payment '.$paymentId, $line.'. '.$note);

        return $line;
    }

    /** The legacy-review items opened by the earlier cutover mode for exactly these matters are closed, attributed, and say they were superseded: not independently verified. */
    private static function supersedeLegacyReview(User $operator): void
    {
        $items = ReconciliationException::where('status', 'open')->whereIn('key', ['cutover:payment_void:8', 'cutover:payment_void:13', 'cutover:cash_difference'])->get();
        foreach ($items as $item) {
            $item->update(['status' => 'resolved', 'resolved_by' => $operator->id, 'resolved_at' => now(),
                'resolution_note' => 'Superseded by the owner-authorized one-time baseline correction run by '.$operator->email.'. Owner-confirmed, NOT independently bank-verified.']);
            Audit::log('reconciliation.exception_resolved', $item->key, 'superseded by the one-time baseline correction');
        }
    }

    private static function journalHash(int $upTo): string
    {
        $rows = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_entries.id', '<=', $upTo)
            ->orderBy('journal_lines.id')->get(['journal_entries.id as e', 'journal_entries.entry_date', 'journal_entries.source_type', 'journal_entries.source_id', 'journal_lines.account', 'journal_lines.partner_id',
                'journal_lines.debit', 'journal_lines.credit', 'journal_lines.financial_account_id'])->map(fn ($r) => array_map(fn ($v) => $v === null ? null : (string) $v, (array) $r))->all();

        return hash('sha256', json_encode($rows));
    }

    /** A fresh, readable database dump: modified within the last two hours, not trivially small, and containing the payments table. @return array{problem: ?string, info: ?array<string, mixed>} */
    private static function checkBackup(?string $path): array
    {
        if ($path === null) {
            return ['problem' => '--backup-file is required: take a fresh database dump first and pass its path', 'info' => null];
        }
        if (! is_file($path) || ! is_readable($path)) {
            return ['problem' => 'the backup file "'.$path.'" does not exist or cannot be read', 'info' => null];
        }
        $age = time() - (int) filemtime($path);
        $size = (int) filesize($path);
        $info = ['path' => $path, 'size' => $size, 'modified' => date('Y-m-d H:i:s', (int) filemtime($path)), 'sha256' => hash_file('sha256', $path)];
        if ($age > 7200 || $age < -300) {
            return ['problem' => 'the backup file was last modified '.$info['modified'].': it must be taken within the last two hours (a fresh backup)', 'info' => $info];
        }
        if ($size < 10_000) {
            return ['problem' => 'the backup file is only '.$size.' bytes: it does not look like a database dump', 'info' => $info];
        }
        $gz = str_ends_with(strtolower($path), '.gz');
        $h = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        $found = false;
        $read = 0;
        while ($h && ! ($gz ? gzeof($h) : feof($h)) && $read < 512 * 1024 * 1024) {
            $line = $gz ? gzgets($h, 65536) : fgets($h, 65536);
            $read += strlen((string) $line);
            if ($line !== false && stripos($line, 'CREATE TABLE') !== false && preg_match('/CREATE TABLE (IF NOT EXISTS )?[`"]?payments[`"]?\s/i', $line)) {
                $found = true;

                break;
            }
        }
        $h ? ($gz ? gzclose($h) : fclose($h)) : null;

        return ['problem' => $found ? null : 'the backup file does not contain the payments table: it is not a dump of this database', 'info' => $info];
    }

    /**
     * Put every historical open item in the existing reconciliation-exceptions register so it stays visible until a second person resolves it with evidence. Registering
     * only records that the item exists. Safe to repeat.
     *
     * @param  array<string, mixed>  $report
     */
    private static function registerLegacyReview(User $operator, array $report): int
    {
        $new = ReconciliationExceptions::register($operator);
        $open = function (string $key, string $subject, ?int $amount, string $detail, string $evidence) use ($operator, &$new): void {
            $row = ReconciliationException::firstOrCreate(['key' => $key], ['type' => 'legacy_review', 'subject' => $subject, 'amount' => $amount,
                'detail' => 'LEGACY REVIEW (operational cutover '.today()->toDateString().'; not verified, never counted as verified): '.$detail, 'evidence_required' => $evidence, 'opened_by' => $operator->id]);
            if ($row->wasRecentlyCreated) {
                $new++;
                Audit::log('reconciliation.exception_opened', $key, $subject);
            }
        };
        foreach ($report['voids'] as $id => $v) {
            if (! self::corrected($v['state'])) {
                $amount = self::PAYMENTS[$id][2];
                $open('cutover:payment_void:'.$id, 'Payment #'.$id.' ('.self::money($amount).') confirmed by the owner as never received', $amount,
                    'The owner confirmed this receipt does not exist. The payment is kept and still counted until its void is approved. Current state: '.$v['state'].' ('.$v['next'].').',
                    'A Finance void request on the payment citing the owner\'s written confirmation (and a bank or bKash statement if one exists), approved by a different Board member.');
            }
        }
        $cash = $report['cash'];
        if ($cash === null || $cash['status'] !== 'reconciled' || $cash['evidence'] !== 'statement_verified') {
            $open('cutover:cash_difference', 'Unreconciled cash difference (actual cash vs ERP expected cash)', $cash['difference'] ?? null,
                'The owner-confirmed actual balance is kept separate from the ERP expected cash; the signed difference is shown live on the Business Dashboard. It is an unresolved reconciliation difference, never an expense, loss, revenue, profit, loan, supplier payment or capital.',
                'Verified bank and bKash statements and a cash count for the same cutoff, with every difference explained item by item. No balancing entry is made.');
        }

        return $new;
    }

    /**
     * What is still unresolved financially. Never a technical blocker.
     *
     * @param  array<int, array{state: string, next: string}>  $voids
     * @param  array<string, mixed>|null  $cash
     * @return list<string>
     */
    private static function outstanding(array $voids, ?array $cash, bool $schemaReady): array
    {
        $items = [];
        foreach ($voids as $id => $v) {
            if (! self::corrected($v['state'])) {
                $items[] = 'Payment #'.$id.' void: '.$v['state'].' ('.$v['next'].')';
            }
        }
        if ($cash !== null && $cash['status'] === 'not_verified') {
            $items[] = 'Actual cash balance: Not Verified ('.$cash['reason'].')';
        } elseif ($cash !== null) {
            if ($cash['difference'] !== 0) {
                $items[] = 'Unreconciled cash difference of '.$cash['sign'].self::money(abs((int) $cash['difference'])).' ('.$cash['direction'].')';
            }
        }
        foreach (IntegrityGate::statementBlockers() as $b) {
            $items[] = 'Statement difference (blocks period close and profit allocation until explained): '.$b;
        }
        if ($schemaReady || Schema::hasTable('reconciliation_exceptions')) {
            $open = ReconciliationException::where('status', 'open')->count();
            if ($open > 0) {
                $items[] = 'Informational: '.$open.' historical review item(s) are registered and open (Finance > Historical Reconciliation); they do not block anything';
            }
        }

        return $items;
    }

    private static function money(int $minor): string
    {
        return Money::format($minor, config('finance.currency'));
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

        return $problems;
    }

    /**
     * A payment voided without an approved request. A payment voided properly through the workflow (a later, ordinary piece of business) is not a problem.
     *
     * @return list<string>
     */
    private static function improperVoids(): array
    {
        $found = [];
        foreach (DB::table('payments')->whereNotNull('voided_at')->pluck('id') as $id) {
            if (! DB::table('payment_void_requests')->where('payment_id', $id)->whereIn('status', ['approved', 'authorized'])->exists()) {
                $found[] = 'payment #'.$id.' is voided without an approved request';
            }
        }

        return $found;
    }

    /** @return array{state: string, next: string} */
    private static function voidState(int $paymentId): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $latest = DB::table('payment_void_requests')->where('payment_id', $paymentId)->orderByDesc('id')->first();
        if ($payment && $payment->voided_at !== null) {
            return match (true) {
                $latest && $latest->status === 'approved' => ['state' => 'APPROVED', 'next' => 'voided through the approved workflow; the payment is kept'],
                $latest && $latest->status === 'authorized' => ['state' => 'AUTHORIZED', 'next' => 'voided by the one-time owner-authorized administrative correction (not a Board approval); the payment is kept'],
                default => ['state' => 'INVALID', 'next' => 'voided without an approved request'],
            };
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
        if (count($voids) !== 2 || collect($voids)->contains(fn ($v) => ! self::corrected($v['state']))) {
            return false;
        }
        $four = $invoices[4] ?? null;
        $six = $invoices[6] ?? null;
        $ten = $invoices[10] ?? null;

        return $four && $six && $ten && $four['status'] === 'paid' && $four['balance'] === 0 && $ten['status'] === 'paid' && $ten['balance'] === 0
            && $six['status'] === 'partial' && $six['paid'] === 920_000 && $six['balance'] === 1_000_000;
    }

    /** Every financial amount and link, excluding only what this cutover may change (invoice amount paid / status) and a void's stamp. Identical before and after, or the run is rolled back. */
    private static function financialHash(array $except = []): string
    {
        $parts = [];
        foreach ([
            'payments' => ['id', 'project_id', 'invoice_id', 'amount', 'paid_on', 'method', 'financial_account_id'], 'payment_allocations' => ['id', 'payment_id', 'segment_id', 'amount'],
            'invoices' => ['id', 'project_id', 'number', 'total'], 'invoice_items' => ['id', 'invoice_id', 'segment_id', 'amount'], 'segments' => ['id', 'project_id', 'amount'],
            'journal_entries' => ['id', 'entry_date', 'source_type', 'source_id'], 'journal_lines' => ['id', 'journal_entry_id', 'account', 'debit', 'credit', 'financial_account_id'],
            'expenses' => ['id', 'amount', 'status', 'category', 'spent_on'], 'capital_entries' => ['id', 'partner_id', 'amount', 'status'],
            'loan_receivables' => ['id', 'principal', 'status', 'expense_id'], 'loan_receivable_repayments' => ['id', 'amount', 'status'],
            'account_statements' => ['id', 'financial_account_id', 'statement_balance', 'status', 'verified_by', 'evidence'], 'supplier_payments' => ['id', 'amount', 'expense_id'],
            'sourcing_lots' => ['id', 'status', 'ownership', 'project_id'], 'receipt_classifications' => ['id', 'payment_id', 'nature'], 'receipt_reviews' => ['id', 'payment_id', 'status'],
            'payment_void_requests' => ['id', 'payment_id', 'status'],
        ] as $table => $columns) {
            if (! in_array($table, $except, true) && Schema::hasTable($table)) {
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
