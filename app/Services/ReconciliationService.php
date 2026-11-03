<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\RevenueAdjustment;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY. Recomputes what the books should say from the source records and compares it with what is recorded, one row
 * per check: recorded, recalculated, difference, the reason and a proposed correction. Nothing is changed here; every
 * correction is a separate, reviewed, reversible posting (see finance:backfill-realtime) taken after a backup.
 */
class ReconciliationService
{
    /** Tables whose full content is snapshotted before any correction. */
    public const BACKUP_TABLES = [
        'journal_entries', 'journal_lines', 'payments', 'payment_allocations', 'expenses', 'expense_payments', 'capital_entries',
        'revenue_adjustments', 'accounting_periods', 'financial_accounts', 'account_transfers', 'opening_batches', 'opening_lines',
    ];

    /**
     * @return list<array{area: string, check: string, unit: string, recorded: int, recalculated: int, difference: int, ok: bool, reason: string, proposal: string}>
     */
    public static function report(): array
    {
        $rows = [];
        $add = function (string $area, string $check, int $recorded, int $recalculated, string $reason, string $proposal, string $unit = 'money') use (&$rows): void {
            $rows[] = ['area' => $area, 'check' => $check, 'unit' => $unit, 'recorded' => $recorded, 'recalculated' => $recalculated,
                'difference' => $recorded - $recalculated, 'ok' => $recorded === $recalculated, 'reason' => $reason, 'proposal' => $proposal];
        };

        // 1. Ledger integrity.
        $unbalanced = JournalLine::query()->groupBy('journal_entry_id')->havingRaw('SUM(debit) <> SUM(credit)')->selectRaw('journal_entry_id')->get()->count();
        $add('Ledger', 'Journal entries whose debits differ from credits', $unbalanced, 0, 'A posted entry is out of balance.', 'Investigate the entry; post a reversing entry and a corrected one.', 'count');
        $duplicates = JournalEntry::query()->groupBy('source_type', 'source_id')->havingRaw('COUNT(*) > 1')->selectRaw('source_type')->get()->count();
        $add('Ledger', 'Source records posted more than once', $duplicates, 0, 'The same record has two journal entries (double-counted).', 'Reverse the later entry.', 'count');

        // 2. Cash accounts.
        $overdrawn = AccountingService::balances()->filter(fn ($b) => $b['account'] && $b['balance'] < 0 && ! $b['account']->allow_negative);
        $add('Cash & bank', 'Accounts below zero (where not allowed)', $overdrawn->count(), 0, $overdrawn->pluck('name')->implode(', ') ?: 'None.', 'Record the missing inflow or transfer money in.', 'count');
        $legacy = AccountingService::balance(null);
        $add('Cash & bank', 'Cash not yet assigned to a financial account (legacy)', $legacy, 0, 'Cash posted before accounts existed has no account, so no single account shows its true balance.', 'Move it with a documented transfer from "Unassigned" to the right account(s).');

        // 3. Real-time window: payments and adjustments that were never journaled.
        $unposted = LedgerPostingService::unpostedPayments();
        $add('Customer payments', 'Payments in the real-time window with no journal entry', $unposted->count(), 0, 'Recorded while real-time posting was off, or before the cutover was set; period close skips these months, so they would never be booked.', 'Run finance:backfill-realtime (dry run first, then --apply after a backup).', 'count');
        $add('Customer payments', 'Amount of those unposted payments', (int) $unposted->sum('amount'), 0, 'Cash collected but absent from the ledger.', 'As above.');
        $unpostedAdj = LedgerPostingService::unpostedAdjustments();
        $add('Customer payments', 'Verified advance / recognition / refund records with no journal entry', $unpostedAdj->count(), 0, 'Verified inside the real-time window but never journaled.', 'Run finance:backfill-realtime.', 'count');

        // 4. Frozen periods against the live recomputation.
        foreach (AccountingPeriod::orderBy('month')->get() as $period) {
            $f = FinancialStatements::monthFigures($period->month);
            if ($period->income !== $f['income'] || $period->expenses !== $f['expenses']) {
                $add('Closed periods', 'Period '.$period->month.' income/expenses against source records', (int) ($period->income - $period->expenses), $f['income'] - $f['expenses'], 'Source records changed after close (a direct database edit, or a record that bypassed the closed-month guard).', 'Do not edit the frozen period; post a dated adjustment in an open month.');
            }
        }

        // 5. Possible duplicate customer payments.
        $dupes = Payment::query()->groupBy('project_id', 'amount', 'paid_on', 'reference')->havingRaw('COUNT(*) > 1')
            ->selectRaw('project_id, amount, paid_on, reference, COUNT(*) n')->get();
        foreach ($dupes as $d) {
            $add('Customer payments', 'Possible duplicate payment on project #'.$d->project_id.' ('.substr((string) $d->paid_on, 0, 10).')', (int) $d->amount * (int) $d->n, (int) $d->amount, 'Same project, amount, date and reference recorded '.$d->n.' times.', 'Confirm with the bank record; void the duplicate (its entry is reversed, never deleted).');
        }

        // 6. Expenses.
        $unjournaled = Expense::query()->where('status', 'verified')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries')
            ->whereColumn('journal_entries.source_id', 'expenses.id')->where('journal_entries.source_type', 'expense'));
        $add('Expenses', 'Verified expenses with no journal entry (missing cash deduction)', (int) (clone $unjournaled)->sum('amount'), 0, (clone $unjournaled)->count().' verified expense(s) never reached the ledger.', 'Post them once through the verifier (idempotent), after review.');
        $unlinked = Expense::query()->where('status', 'verified')->whereNull('reverses_id')->whereNull('project_id')->where(fn ($q) => $q->where('classification', 'direct')->orWhere('category', 'like', '%project%'));
        $add('Expenses', 'Project-type expenses not linked to a project', (int) $unlinked->sum('amount'), 0, $unlinked->count().' expense(s) look like project costs but name no project, so project profit ignores them.', 'Open each expense and link its project (classification "Direct project cost").');
        $payMismatch = Expense::query()->where('status', 'verified')->whereNull('reverses_id')->whereNotIn('payment_status', ['reversed'])->get(['id', 'amount', 'paid_amount', 'payment_status'])
            ->filter(fn ($e) => $e->payment_status === 'paid' ? $e->paid_amount !== $e->amount : false);
        $add('Expenses', 'Expenses marked paid whose paid amount differs from the amount', $payMismatch->count(), 0, 'Payment status and paid amount disagree.', 'Review each; correct via a reversal and re-entry.', 'count');
        $payables = FinancialReportingService::accountsPayable();
        $add('Expenses', 'Accounts payable: ledger liabilities vs unpaid verified expenses', Ledger::creditBalance('liabilities'), $payables, 'The ledger "liabilities" account also carries opening balances and other payables; a difference is expected only if such entries exist.', 'Review the journal for non-expense liability entries.');

        // 7. Capital.
        $capitalMissing = CapitalEntry::query()->where('status', 'verified')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries')
            ->whereColumn('journal_entries.source_id', 'capital_entries.id')->where('journal_entries.source_type', 'capital'));
        $add('Capital', 'Verified capital entries with no journal entry', (int) $capitalMissing->sum('amount'), 0, 'Verified but never posted.', 'Post once through InvestmentService after review.');
        foreach (Partner::orderBy('sort')->get() as $p) {
            $recordedGross = InvestmentService::contributedTotal($p->id);
            $sources = (int) CapitalEntry::where('partner_id', $p->id)->where('kind', 'contribution')->where('status', 'verified')->sum('amount')
                + (int) DB::table('opening_lines as l')->join('opening_batches as b', 'b.id', '=', 'l.opening_batch_id')->where('b.status', 'posted')->where('l.kind', 'partner_capital')->where('l.partner_id', $p->id)->sum('l.amount');
            if ($recordedGross !== $sources) {
                $add('Capital', 'Partner '.$p->code.' paid-in capital: ledger vs capital records', $recordedGross, $sources, 'The ledger credits to partner capital differ from verified entries plus posted opening balances.', 'Review the partner\'s journal lines.');
            }
        }
        $misfiled = (int) JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', 'income')
            ->whereIn('journal_entries.source_type', ['capital', 'capital_reversal'])->sum('journal_lines.credit');
        $add('Capital', 'Investment recorded as income', $misfiled, 0, 'Owner money must never be revenue or profit.', 'Reverse the entry and re-post it as capital.');

        // 8. Customer advances.
        $verified = fn (string $kind, ?string $against = null) => (int) RevenueAdjustment::where('status', 'verified')->where('kind', $kind)->when($against, fn ($q) => $q->where('against', $against))->sum('amount');
        $advanceOutstanding = $verified('advance') - $verified('recognition') - $verified('refund', 'advance');
        $add('Customer payments', 'Customer advances: ledger vs verified adjustments', Ledger::creditBalance('customer.advances'), $advanceOutstanding, 'Verified adjustments in months that are neither closed nor in real-time posting are booked at close; until then the ledger is behind.', 'Close the month, or move the cutover earlier.');

        return $rows;
    }

    /** Snapshot every finance table as JSON before any correction is applied. Returns the file path. */
    public static function backup(): string
    {
        $dir = storage_path('app/finance-backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/finance-'.now()->format('Ymd-His').'.json';
        $out = fopen($path, 'w');
        fwrite($out, '{');
        foreach (self::BACKUP_TABLES as $i => $table) {
            fwrite($out, ($i ? ',' : '').json_encode($table).':[');
            $first = true;
            foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                fwrite($out, ($first ? '' : ',').json_encode($row));
                $first = false;
            }
            fwrite($out, ']');
        }
        fwrite($out, '}');
        fclose($out);

        return $path;
    }
}
