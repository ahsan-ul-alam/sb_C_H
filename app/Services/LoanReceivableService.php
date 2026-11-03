<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Models\Partner;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money lent out that must be paid back. It enters the books by RECLASSIFYING an expense that was really a loan, never by a
 * second disbursement:
 *   Reclassification (non-cash):  Dr assets.loans_receivable (borrower) / Cr expense      the original Dr expense / Cr cash stays as it is
 *   Repayment of principal:       Dr cash account / Cr assets.loans_receivable            never revenue or profit
 * Both are proposed by one user and verified by a different Board user; the borrower can never verify their own loan or repayment.
 * Closed months are never touched, and every undo is a reversing entry.
 */
class LoanReceivableService
{
    /**
     * Propose that a verified, fully paid expense was really a loan. Repeating the proposal returns the one that already exists.
     *
     * @param  array{borrower_partner_id?: ?int, borrower_name?: ?string, notes?: ?string}  $data
     */
    public static function propose(Expense $expense, array $data, User $user): LoanReceivable
    {
        return DB::transaction(function () use ($expense, $data, $user) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $existing = LoanReceivable::where('expense_id', $expense->id)->whereIn('status', ['pending', 'verified'])->first();
            if ($existing) {
                return $existing;
            }
            self::assertReclassifiable($expense);

            $partner = ! empty($data['borrower_partner_id']) ? Partner::findOrFail($data['borrower_partner_id']) : null;
            $name = $partner?->name ?? trim((string) ($data['borrower_name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Name the borrower.');
            }

            $loan = LoanReceivable::create([
                'borrower_name' => $name, 'borrower_partner_id' => $partner?->id, 'expense_id' => $expense->id, 'principal' => $expense->amount,
                'lent_on' => $expense->spent_on->toDateString(), 'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
            ]);
            Audit::log('loanrec.proposed', 'expense '.$expense->id, $name.' '.$expense->amount);

            return $loan;
        });
    }

    public static function decide(LoanReceivable $loan, bool $approve, User $user, ?string $note = null): void
    {
        if (! $approve && trim((string) $note) === '') {
            throw new RuntimeException('Give a reason for rejecting.');
        }
        DB::transaction(function () use ($loan, $approve, $user, $note) {
            $expense = Expense::whereKey($loan->expense_id)->lockForUpdate()->firstOrFail();
            $loan = LoanReceivable::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($loan->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            self::assertIndependent($loan, $user, 'verify');
            if ($approve) {
                self::assertReclassifiable($expense);
                Ledger::post($loan->lent_on->toDateString(), 'Expense #'.$expense->id.' reclassified as a loan to '.$loan->borrower_name, 'loan_receivable', $loan->id, [
                    ['assets.loans_receivable', $loan->borrower_partner_id, (int) $loan->principal, 0],
                    ['expense', null, 0, (int) $loan->principal],
                ], $user->id);
            }
            $loan->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now(), 'decision_note' => trim((string) $note) ?: null]);
            Audit::log('loanrec.'.($approve ? 'verified' : 'rejected'), 'loan receivable '.$loan->id, 'expense '.$expense->id);
        });
    }

    /** Undo a verified reclassification: the expense counts as an expense again. Impossible once any repayment exists. */
    public static function reverse(LoanReceivable $loan, string $reason, User $user): void
    {
        DB::transaction(function () use ($loan, $reason, $user) {
            $loan = LoanReceivable::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($loan->status !== 'verified') {
                throw new RuntimeException('Only a verified loan can be reversed.');
            }
            self::assertIndependent($loan, $user, 'reverse');
            if ($loan->repayments()->whereIn('status', ['pending', 'verified'])->exists()) {
                throw new RuntimeException('Reverse or reject the repayments first.');
            }
            if (AccountingService::isClosed($loan->lent_on->toDateString())) {
                throw new RuntimeException('The month of this loan is closed; it cannot be reversed.');
            }
            $original = JournalEntry::where('source_type', 'loan_receivable')->where('source_id', $loan->id)->firstOrFail();
            Ledger::reverse($original, $original->entry_date->toDateString(), 'Reversal of loan receivable #'.$loan->id.': '.$reason, 'loan_receivable_rev', $loan->id, $user->id);
            $loan->update(['status' => 'reversed']);
            Audit::log('loanrec.reversed', 'loan receivable '.$loan->id, $reason);
        });
    }

    /**
     * Record that the borrower paid some principal back. Pending repayments reserve their share, so the same money cannot be
     * recorded twice, and the key makes a double submission a no-op. The date and account are the user's, never assumed.
     *
     * @param  array{amount: int, repaid_on: string, financial_account_id?: ?int, reference?: ?string, idempotency_key?: ?string}  $data
     * @return LoanReceivableRepayment|null null when this submission was already recorded
     */
    public static function recordRepayment(LoanReceivable $loan, array $data, User $user): ?LoanReceivableRepayment
    {
        $key = ($data['idempotency_key'] ?? null) ?: (string) str()->uuid();
        if (LoanReceivableRepayment::where('idempotency_key', $key)->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($loan, $data, $user, $key) {
                $loan = LoanReceivable::whereKey($loan->id)->lockForUpdate()->firstOrFail();
                $amount = (int) $data['amount'];
                if ($loan->status !== 'verified') {
                    throw new RuntimeException('Only a verified loan can be repaid.');
                }
                if ($amount <= 0) {
                    throw new RuntimeException('Amount must be greater than zero.');
                }
                $room = $loan->outstanding() - $loan->pendingRepayments();
                if ($amount > $room) {
                    throw new RuntimeException('A repayment cannot exceed the principal still owed ('.Money::format(max(0, $room)).').');
                }
                if ($data['repaid_on'] < $loan->lent_on->toDateString()) {
                    throw new RuntimeException('A repayment cannot be dated before the loan.');
                }
                if (AccountingService::isClosed($data['repaid_on'])) {
                    throw new RuntimeException('That month is already closed; record the repayment in an open month.');
                }

                $repayment = LoanReceivableRepayment::create([
                    'loan_receivable_id' => $loan->id, 'amount' => $amount, 'repaid_on' => $data['repaid_on'],
                    'financial_account_id' => $data['financial_account_id'] ?? null, 'reference' => $data['reference'] ?? null,
                    'created_by' => $user->id, 'idempotency_key' => $key,
                ]);
                Audit::log('loanrec.repayment_recorded', 'loan receivable '.$loan->id, (string) $amount);

                return $repayment;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public static function decideRepayment(LoanReceivableRepayment $repayment, bool $approve, User $user): void
    {
        DB::transaction(function () use ($repayment, $approve, $user) {
            $loan = LoanReceivable::whereKey($repayment->loan_receivable_id)->lockForUpdate()->firstOrFail();
            $repayment = LoanReceivableRepayment::whereKey($repayment->id)->lockForUpdate()->firstOrFail();
            if ($repayment->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($repayment->created_by === $user->id) {
                throw new RuntimeException('You cannot verify an entry you recorded.');
            }
            self::assertNotBorrower($loan, $user);
            if ($approve) {
                $amount = (int) $repayment->amount;
                if ($loan->status !== 'verified' || $amount > $loan->outstanding()) {
                    throw new RuntimeException('A repayment cannot exceed the principal still owed ('.Money::format(max(0, $loan->outstanding())).').');
                }
                if (AccountingService::isClosed($repayment->repaid_on->toDateString())) {
                    throw new RuntimeException('That month is already closed; record the repayment in an open month.');
                }
                $accountId = AccountingService::resolveAccountId($repayment->financial_account_id);
                Ledger::post($repayment->repaid_on->toDateString(), 'Loan repayment received from '.$loan->borrower_name, 'loan_receivable_repayment', $repayment->id, [
                    ['cash', null, $amount, 0, $accountId],
                    ['assets.loans_receivable', $loan->borrower_partner_id, 0, $amount],
                ], $user->id);
            }
            $repayment->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now()]);
            Audit::log('loanrec.repayment_'.($approve ? 'verified' : 'rejected'), 'loan receivable '.$loan->id, (string) $repayment->amount);
        });
    }

    /** Undo a verified repayment with a reversing entry; the money is owed again. */
    public static function reverseRepayment(LoanReceivableRepayment $repayment, string $reason, User $user): void
    {
        DB::transaction(function () use ($repayment, $reason, $user) {
            $loan = LoanReceivable::whereKey($repayment->loan_receivable_id)->lockForUpdate()->firstOrFail();
            $repayment = LoanReceivableRepayment::whereKey($repayment->id)->lockForUpdate()->firstOrFail();
            if ($repayment->status !== 'verified') {
                throw new RuntimeException('Only a verified repayment can be reversed.');
            }
            if ($repayment->created_by === $user->id) {
                throw new RuntimeException('You cannot reverse an entry you recorded.');
            }
            self::assertNotBorrower($loan, $user);
            $original = JournalEntry::where('source_type', 'loan_receivable_repayment')->where('source_id', $repayment->id)->firstOrFail();
            AccountingService::assertCanSpend($original->lines()->where('account', 'cash')->value('financial_account_id'), (int) $repayment->amount);
            Ledger::reverse($original, $original->entry_date->toDateString(), 'Reversal of loan repayment #'.$repayment->id.': '.$reason, 'loan_receivable_repayment_rev', $repayment->id, $user->id);
            $repayment->update(['status' => 'reversed']);
            Audit::log('loanrec.repayment_reversed', 'loan receivable '.$loan->id, $reason);
        });
    }

    /** Only a verified, fully paid, non-capital expense whose cash really left can be a loan; its month must still be open. */
    private static function assertReclassifiable(Expense $expense): void
    {
        if ($expense->status !== 'verified' || $expense->reverses_id || $expense->payment_status !== 'paid') {
            throw new RuntimeException('Only a verified, fully paid expense can be reclassified as a loan.');
        }
        if ($expense->is_asset || ! in_array($expense->classification, ['operating', 'other'], true)) {
            throw new RuntimeException('Only an operating or other expense can be reclassified as a loan.');
        }
        if (Expense::where('reverses_id', $expense->id)->whereIn('status', ['pending', 'verified'])->exists()) {
            throw new RuntimeException('This expense has a reversal; reclassify it only if the reversal is rejected.');
        }
        if (! JournalEntry::where('source_type', 'expense')->where('source_id', $expense->id)->exists()) {
            throw new RuntimeException('This expense has no journal entry, so no cash left through the books.');
        }
        if (AccountingService::isClosed($expense->spent_on->toDateString())) {
            throw new RuntimeException('The month of this expense is closed; closed periods are never changed.');
        }
    }

    private static function assertIndependent(LoanReceivable $loan, User $user, string $verb): void
    {
        if ($loan->created_by === $user->id) {
            throw new RuntimeException('You cannot '.$verb.' a loan you proposed.');
        }
        self::assertNotBorrower($loan, $user);
    }

    private static function assertNotBorrower(LoanReceivable $loan, User $user): void
    {
        if ($loan->borrower_partner_id && $user->partner_id === $loan->borrower_partner_id) {
            throw new RuntimeException('The borrower cannot approve their own loan or repayment.');
        }
    }
}
