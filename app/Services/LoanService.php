<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Borrowed money is a liability, never income and never owner capital.
 *   Loan received:        Dr cash account / Cr loans.payable       (no profit effect)
 *   Principal repayment:  Dr loans.payable / Cr cash account       (no profit effect)
 *   Interest paid:        an ordinary "other" expense (it reduces profit), recorded through the expense workflow.
 * Receipts and repayments are recorded by Finance and verified by a different Board user, like capital entries.
 */
class LoanService
{
    /**
     * @param  array{kind: string, amount: int, entry_date: string, financial_account_id?: ?int, idempotency_key?: ?string}  $data
     * @return LoanEntry|null null when this submission was already recorded
     */
    public static function record(Loan $loan, array $data, User $user): ?LoanEntry
    {
        $key = ($data['idempotency_key'] ?? null) ?: (string) str()->uuid();
        if (LoanEntry::where('idempotency_key', $key)->exists()) {
            return null;
        }
        if (! in_array($data['kind'], ['receipt', 'principal'], true) || $data['amount'] <= 0) {
            throw new RuntimeException('Enter a positive amount for a loan receipt or a principal repayment.');
        }

        try {
            return DB::transaction(function () use ($loan, $data, $user, $key) {
                $loan = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
                if ($data['kind'] === 'principal') {
                    $pending = (int) $loan->entries()->where('kind', 'principal')->where('status', 'pending')->sum('amount');
                    if ($data['amount'] > $loan->outstanding() - $pending) {
                        throw new RuntimeException('A repayment cannot exceed the principal still owed ('.Money::format(max(0, $loan->outstanding() - $pending)).').');
                    }
                }

                return LoanEntry::create([
                    'loan_id' => $loan->id, 'kind' => $data['kind'], 'amount' => $data['amount'], 'entry_date' => $data['entry_date'],
                    'financial_account_id' => $data['financial_account_id'] ?? null, 'created_by' => $user->id, 'idempotency_key' => $key,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public static function decide(LoanEntry $entry, bool $approve, User $user): void
    {
        DB::transaction(function () use ($entry, $approve, $user) {
            $entry = LoanEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($entry->created_by === $user->id) {
                throw new RuntimeException('You cannot verify an entry you recorded.');
            }
            $entry->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now()]);
            if ($approve) {
                self::post($entry, $user->id);
            }
            Audit::log('loan.'.($approve ? 'verified' : 'rejected'), 'loan entry '.$entry->id, $entry->kind.' '.$entry->amount);
        });
    }

    private static function post(LoanEntry $entry, int $userId): void
    {
        $loan = Loan::whereKey($entry->loan_id)->lockForUpdate()->firstOrFail();
        $accountId = AccountingService::resolveAccountId($entry->financial_account_id);
        $amount = (int) $entry->amount;
        $date = $entry->entry_date->toDateString();

        if ($entry->kind === 'receipt') {
            Ledger::post($date, 'Loan received: '.$loan->lender, 'loan_entry', $entry->id, [
                ['cash', null, $amount, 0, $accountId], ['loans.payable', null, 0, $amount],
            ], $userId);

            return;
        }
        if ($amount > $loan->outstanding()) {
            throw new RuntimeException('A repayment cannot exceed the principal still owed ('.Money::format(max(0, $loan->outstanding())).').');
        }
        AccountingService::assertCanSpend($accountId, $amount);
        Ledger::post($date, 'Loan principal repaid: '.$loan->lender, 'loan_entry', $entry->id, [
            ['loans.payable', null, $amount, 0], ['cash', null, 0, $amount, $accountId],
        ], $userId);
    }

    /** Undo a verified entry with a reversing entry (never an edit or delete). */
    public static function reverse(LoanEntry $entry, string $reason, User $user): void
    {
        DB::transaction(function () use ($entry, $reason, $user) {
            $entry = LoanEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'verified') {
                throw new RuntimeException('Only a verified entry can be reversed.');
            }
            if ($entry->created_by === $user->id) {
                throw new RuntimeException('You cannot reverse an entry you recorded.');
            }
            $loan = Loan::whereKey($entry->loan_id)->lockForUpdate()->firstOrFail();
            $original = JournalEntry::where('source_type', 'loan_entry')->where('source_id', $entry->id)->firstOrFail();
            if ($entry->kind === 'receipt') {
                if ($loan->outstanding() - $entry->amount < 0) {
                    throw new RuntimeException('Repayments already exceed what would remain; reverse those first.');
                }
                AccountingService::assertCanSpend($original->lines()->where('account', 'cash')->value('financial_account_id'), (int) $entry->amount);
            }
            Ledger::reverse($original, $original->entry_date->toDateString(), 'Reversal of loan entry #'.$entry->id.': '.$reason, 'loan_entry_rev', $entry->id, $user->id);
            $entry->update(['status' => 'reversed']);
            Audit::log('loan.reversed', 'loan entry '.$entry->id, $reason);
        });
    }

    /**
     * Interest is a cost of borrowing, so it goes through the expense workflow (Board verification, profit effect) as "other".
     *
     * @param  array{amount: int, spent_on: string, financial_account_id?: ?int}  $data
     */
    public static function recordInterest(Loan $loan, array $data, User $user): Expense
    {
        if (AccountingService::isClosed($data['spent_on'])) {
            throw new RuntimeException('That month is already closed; record the interest in an open month.');
        }

        return ExpenseVerifier::record([
            'category' => 'Loan interest', 'description' => 'Interest on loan from '.$loan->lender.($loan->reference ? ' ('.$loan->reference.')' : ''),
            'amount' => $data['amount'], 'spent_on' => $data['spent_on'], 'classification' => 'other', 'vendor' => $loan->lender,
            'financial_account_id' => $data['financial_account_id'] ?? null,
        ], $user);
    }
}
