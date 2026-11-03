<?php

namespace App\Services;

use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The checks that must be clean before a month is closed or a profit is allocated. These are INTEGRITY failures, not
 * business decisions: an unbalanced or doubled entry, a verified expense or capital entry that never reached the ledger, and an
 * account whose verified statement is not explained to the last paisa. Open classification questions (a loan booked as an expense,
 * receipts awaiting a costing decision) are a Board matter and are reported by the reconciliation, not enforced here.
 * Read-only: nothing is posted to make a blocker disappear.
 */
class IntegrityGate
{
    /**
     * @return list<string>
     */
    public static function blockers(): array
    {
        $blockers = [];
        $unbalanced = JournalLine::query()->groupBy('journal_entry_id')->havingRaw('SUM(debit) <> SUM(credit)')->selectRaw('journal_entry_id')->get()->count();
        if ($unbalanced > 0) {
            $blockers[] = $unbalanced.' journal entr'.($unbalanced === 1 ? 'y is' : 'ies are').' out of balance';
        }
        $duplicates = JournalEntry::query()->groupBy('source_type', 'source_id')->havingRaw('COUNT(*) > 1')->selectRaw('source_type')->get()->count();
        if ($duplicates > 0) {
            $blockers[] = $duplicates.' source record(s) are posted more than once';
        }
        $unjournaledExpenses = Expense::query()->where('status', 'verified')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries')
            ->whereColumn('journal_entries.source_id', 'expenses.id')->where('journal_entries.source_type', 'expense'))->count();
        if ($unjournaledExpenses > 0) {
            $blockers[] = $unjournaledExpenses.' verified expense(s) have no journal entry';
        }
        $unjournaledCapital = CapitalEntry::query()->where('status', 'verified')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries')
            ->whereColumn('journal_entries.source_id', 'capital_entries.id')->where('journal_entries.source_type', 'capital'))->count();
        if ($unjournaledCapital > 0) {
            $blockers[] = $unjournaledCapital.' verified capital entr'.($unjournaledCapital === 1 ? 'y has' : 'ies have').' no journal entry';
        }
        foreach (StatementReconciliationService::unexplained() as $row) {
            $blockers[] = $row['account'].' has an unexplained difference of '.StatementReconciliationService::describe($row['unexplained']).' against its verified statement of '.$row['date'];
        }

        return $blockers;
    }

    /** @throws RuntimeException when anything blocks the action */
    public static function assertClear(string $action): void
    {
        $blockers = self::blockers();
        if ($blockers !== []) {
            throw new RuntimeException('Cannot '.$action.' while accounting discrepancies are unresolved: '.implode('; ', $blockers).'.');
        }
    }
}
