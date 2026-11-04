<?php

namespace App\Services;

use App\Models\CapitalEntry;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owner money in and out of the business, kept apart from income and profit.
 * - Contribution: Dr cash account / Cr partner.capital   (never income)
 * - Withdrawal:   Dr partner.capital / Cr cash account   (a return of capital, never an expense)
 * Both are recorded by Finance, verified by a different Board user, and corrected only by a reversing entry.
 */
class InvestmentService
{
    /** Paid-in capital net of loss charges and verified withdrawals. */
    public static function capitalBalance(int $partnerId, bool $lock = false): int
    {
        return Ledger::creditBalance('partner.capital', $partnerId, $lock);
    }

    /** Gross paid-in capital: every credit to the partner's capital except the credits created by reversals. */
    public static function contributedTotal(int $partnerId): int
    {
        $credits = (int) JournalLine::where('account', 'partner.capital')->where('partner_id', $partnerId)->sum('credit');

        return $credits - (int) CapitalEntry::where('partner_id', $partnerId)->where('status', 'reversed')->sum('amount');
    }

    /** Verified, not reversed, capital withdrawals of a partner. */
    public static function withdrawnTotal(int $partnerId): int
    {
        return (int) CapitalEntry::where('partner_id', $partnerId)->where('kind', 'withdrawal')->where('status', 'verified')->sum('amount');
    }

    /** Journal a verified capital entry. Called inside the verification transaction. */
    public static function post(CapitalEntry $entry, int $userId): void
    {
        $accountId = AccountingService::activeAccountId($entry->financial_account_id);
        $amount = (int) $entry->amount;
        AccountingService::assertOpen($entry->paid_on->toDateString(), 'this capital entry');

        if ($entry->kind === 'withdrawal') {
            // Serialise withdrawals of the same partner and read their capital with a locking read.
            Partner::whereKey($entry->partner_id)->lockForUpdate()->first();
            $held = self::capitalBalance($entry->partner_id, lock: true);
            if ($amount > $held) {
                throw new RuntimeException('A withdrawal cannot exceed the partner\'s capital balance of '.Money::format(max(0, $held)).'.');
            }
            AccountingService::assertCanSpend($accountId, $amount);
            Ledger::post($entry->paid_on->toDateString(), 'Capital withdrawn by partner '.$entry->partner_id, 'capital', $entry->id, [
                ['partner.capital', $entry->partner_id, $amount, 0],
                ['cash', null, 0, $amount, $accountId],
            ], $userId);

            return;
        }

        Ledger::post($entry->paid_on->toDateString(), 'Capital paid by partner '.$entry->partner_id, 'capital', $entry->id, [
            ['cash', null, $amount, 0, $accountId],
            ['partner.capital', $entry->partner_id, 0, $amount],
        ], $userId);
    }

    /**
     * Undo a verified capital entry with a reversing entry (the original posting is never edited). Reversing a contribution
     * returns the money, so the partner must still hold that capital and the account must have it.
     */
    public static function reverse(CapitalEntry $entry, string $reason, User $user): void
    {
        DB::transaction(function () use ($entry, $reason, $user) {
            $entry = CapitalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'verified') {
                throw new RuntimeException('Only a verified entry can be reversed.');
            }
            if ($entry->created_by === $user->id) {
                throw new RuntimeException('You cannot reverse an entry you recorded.');
            }
            $original = JournalEntry::where('source_type', 'capital')->where('source_id', $entry->id)->firstOrFail();
            if ($entry->kind === 'contribution') {
                // The same lock a withdrawal takes (partner row first, then the account), so a reversal and a withdrawal of the same partner
                // are serialised and cannot both be allowed against the one capital balance.
                Partner::whereKey($entry->partner_id)->lockForUpdate()->first();
                if ($entry->amount > self::capitalBalance($entry->partner_id, lock: true)) {
                    throw new RuntimeException('The partner no longer holds this much capital (losses or withdrawals were charged since).');
                }
                AccountingService::assertCanSpend($original->lines()->where('account', 'cash')->value('financial_account_id'), (int) $entry->amount);
            }
            Ledger::reverse($original, AccountingService::reversalDate($original->entry_date->toDateString()), 'Reversal of capital entry #'.$entry->id.': '.$reason, 'capital_reversal', $entry->id, $user->id);
            $entry->update(['status' => 'reversed']);
            Audit::log('capital.reversed', 'capital '.$entry->id, $reason);
        });
    }
}
