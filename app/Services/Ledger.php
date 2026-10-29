<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use RuntimeException;

/**
 * Minimal balanced double-entry journal. Entries are immutable; the unique (source_type, source_id)
 * makes every posting idempotent. Call inside a DB transaction.
 */
class Ledger
{
    /**
     * @param  list<array{0: string, 1: int|null, 2: int, 3: int}>  $lines  [account, partner_id, debit, credit]
     */
    public static function post(string $date, string $memo, string $sourceType, int $sourceId, array $lines, ?int $userId, ?int $reversalOf = null): JournalEntry
    {
        $debit = array_sum(array_column($lines, 2));
        $credit = array_sum(array_column($lines, 3));
        if ($debit !== $credit || $debit <= 0) {
            throw new RuntimeException('Unbalanced journal entry.');
        }
        if (JournalEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
            throw new RuntimeException('This record has already been posted.');
        }

        $entry = JournalEntry::create([
            'entry_date' => $date, 'memo' => $memo, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'reversal_of' => $reversalOf, 'posted_by' => $userId,
        ]);
        foreach ($lines as [$account, $partnerId, $dr, $cr]) {
            if ($dr === 0 && $cr === 0) {
                continue;
            }
            JournalLine::create(['journal_entry_id' => $entry->id, 'account' => $account, 'partner_id' => $partnerId, 'debit' => $dr, 'credit' => $cr]);
        }

        return $entry;
    }

    /**
     * Credit-normal balance (credits minus debits) of an account, optionally for one partner.
     */
    public static function creditBalance(string $account, ?int $partnerId = null): int
    {
        $row = JournalLine::query()->where('account', $account)
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->selectRaw('COALESCE(SUM(credit),0) c, COALESCE(SUM(debit),0) d')->first();

        return (int) $row->c - (int) $row->d;
    }

    public static function debitBalance(string $account): int
    {
        return -self::creditBalance($account);
    }
}
