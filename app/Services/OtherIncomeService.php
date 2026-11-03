<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\OtherIncome;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Non-trading income (bank interest, a grant, a one-off sale of an old asset's scrap…): Dr cash account / Cr income.other.
 * It is accounting income, reported on its own line, and it is NOT customer revenue. Whether it also enters the profit that
 * is distributed to partners is a Board policy decision (finance.distributable_basis), never automatic.
 */
class OtherIncomeService
{
    /**
     * @param  array{description: string, amount: int, received_on: string, financial_account_id?: ?int, idempotency_key?: ?string}  $data
     * @return OtherIncome|null null when this submission was already recorded
     */
    public static function record(array $data, User $user): ?OtherIncome
    {
        $key = ($data['idempotency_key'] ?? null) ?: (string) str()->uuid();
        if (OtherIncome::where('idempotency_key', $key)->exists()) {
            return null;
        }
        if ($data['amount'] <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
        if (AccountingService::isClosed($data['received_on'])) {
            throw new RuntimeException('That month is already closed; record it in an open month.');
        }

        try {
            return OtherIncome::create([
                'description' => $data['description'], 'amount' => $data['amount'], 'received_on' => $data['received_on'],
                'financial_account_id' => $data['financial_account_id'] ?? null, 'created_by' => $user->id, 'idempotency_key' => $key,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public static function decide(OtherIncome $income, bool $approve, User $user): void
    {
        DB::transaction(function () use ($income, $approve, $user) {
            $income = OtherIncome::whereKey($income->id)->lockForUpdate()->firstOrFail();
            if ($income->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($income->created_by === $user->id) {
                throw new RuntimeException('You cannot verify income you recorded.');
            }
            if ($approve && AccountingService::isClosed($income->received_on->toDateString())) {
                throw new RuntimeException('That month has been closed meanwhile; reject this and record it again in an open month.');
            }
            $income->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now()]);
            if ($approve) {
                Ledger::post($income->received_on->toDateString(), 'Other income: '.$income->description, 'other_income', $income->id, [
                    ['cash', null, (int) $income->amount, 0, AccountingService::resolveAccountId($income->financial_account_id)],
                    ['income.other', null, 0, (int) $income->amount],
                ], $user->id);
            }
            Audit::log('other_income.'.($approve ? 'verified' : 'rejected'), 'other income '.$income->id);
        });
    }

    public static function reverse(OtherIncome $income, string $reason, User $user): void
    {
        DB::transaction(function () use ($income, $reason, $user) {
            $income = OtherIncome::whereKey($income->id)->lockForUpdate()->firstOrFail();
            if ($income->status !== 'verified' || $income->created_by === $user->id) {
                throw new RuntimeException('Only a verified entry recorded by someone else can be reversed.');
            }
            if (AccountingService::isClosed($income->received_on->toDateString())) {
                throw new RuntimeException('That month is closed; correct it with a dated adjustment in an open month.');
            }
            $original = JournalEntry::where('source_type', 'other_income')->where('source_id', $income->id)->firstOrFail();
            AccountingService::assertCanSpend($original->lines()->where('account', 'cash')->value('financial_account_id'), (int) $income->amount);
            Ledger::reverse($original, $original->entry_date->toDateString(), 'Reversal of other income #'.$income->id.': '.$reason, 'other_income_rev', $income->id, $user->id);
            $income->update(['status' => 'reversed']);
            Audit::log('other_income.reversed', 'other income '.$income->id, $reason);
        });
    }
}
