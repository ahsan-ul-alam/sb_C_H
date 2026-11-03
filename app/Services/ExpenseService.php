<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\ExpensePayment;
use App\Models\JournalEntry;
use App\Models\LoanReceivable;
use App\Models\PrepaidRelease;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What happens to an expense after it is recorded: paying an unpaid (payable) expense in full or in parts, voiding such a
 * payment, reversing an expense, and the explicit split of a shared expense across projects.
 *
 * Recording and verifying stay in ExpenseVerifier (the only place an expense becomes a journal entry).
 * - Paid expense:   Dr expense / Cr cash account               (cash leaves exactly once, when verified)
 * - Unpaid expense: Dr expense / Cr liabilities                (a payable; cash is untouched)
 * - Payment of a payable: Dr liabilities / Cr cash account     (each part is its own posting)
 */
class ExpenseService
{
    /** @var array<string, string> */
    public const CLASSIFICATIONS = [
        'operating' => 'Operating / office expense',
        'direct' => 'Direct project cost',
        'asset' => 'Capital purchase (equipment, lasting asset) — not an expense',
        'prepaid' => 'Prepaid expense (insurance, annual subscription, advance rent) — expensed month by month',
        'other' => 'Other expense',
        'tax' => 'Tax',
    ];

    /**
     * Pay all or part of a verified, unpaid expense. Idempotent on the key; never pays more than is owed.
     *
     * @param  array{amount: int, paid_on: string, financial_account_id?: ?int, reference?: ?string, idempotency_key?: ?string}  $data
     * @return ExpensePayment|null null when this submission was already recorded
     */
    public static function pay(Expense $expense, array $data, User $user): ?ExpensePayment
    {
        $key = ($data['idempotency_key'] ?? null) ?: (string) str()->uuid();
        if (ExpensePayment::where('idempotency_key', $key)->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($expense, $data, $user, $key) {
                $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
                $amount = (int) $data['amount'];
                if ($expense->status !== 'verified' || $expense->reverses_id) {
                    throw new RuntimeException('Only a verified expense can be paid.');
                }
                if (Expense::where('reverses_id', $expense->id)->where('status', 'pending')->exists()) {
                    throw new RuntimeException('A reversal of this expense is awaiting verification.');
                }
                if ($amount <= 0) {
                    throw new RuntimeException('Amount must be greater than zero.');
                }
                if ($amount > $expense->outstanding()) {
                    throw new RuntimeException('Only '.Money::format($expense->outstanding()).' of this expense is still unpaid.');
                }
                if (AccountingService::isClosed($data['paid_on'])) {
                    throw new RuntimeException('That month is already closed; record the payment in an open month.');
                }
                $accountId = AccountingService::resolveAccountId($data['financial_account_id'] ?? $expense->financial_account_id);
                AccountingService::assertCanSpend($accountId, $amount);

                $payment = ExpensePayment::create([
                    'expense_id' => $expense->id, 'amount' => $amount, 'paid_on' => $data['paid_on'], 'financial_account_id' => $accountId,
                    'reference' => $data['reference'] ?? null, 'idempotency_key' => $key, 'created_by' => $user->id,
                ]);
                Ledger::post($data['paid_on'], 'Payment of expense #'.$expense->id.': '.$expense->description, 'expense_payment', $payment->id, [
                    ['liabilities', null, $amount, 0],
                    ['cash', null, 0, $amount, $accountId],
                ], $user->id);
                self::refreshPaid($expense);
                Audit::log('expense.paid', 'expense '.$expense->id, (string) $amount);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Void a payment of a payable: a reversing entry restores both the payable and the account. */
    public static function voidPayment(ExpensePayment $payment, User $user): void
    {
        DB::transaction(function () use ($payment, $user) {
            $payment = ExpensePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->isVoided()) {
                throw new RuntimeException('This payment is already voided.');
            }
            $expense = Expense::whereKey($payment->expense_id)->lockForUpdate()->firstOrFail();
            if ($expense->payment_status === 'reversed') {
                throw new RuntimeException('This expense has been reversed.');
            }
            $original = JournalEntry::where('source_type', 'expense_payment')->where('source_id', $payment->id)->firstOrFail();
            Ledger::reverse($original, $original->entry_date->toDateString(), 'Void of expense payment #'.$payment->id, 'expense_payment_rev', $payment->id, $user->id);
            $payment->update(['voided_at' => now(), 'voided_by' => $user->id]);
            self::refreshPaid($expense);
            Audit::log('expense.payment_voided', 'expense '.$expense->id, (string) $payment->amount);
        });
    }

    /** paid_amount and payment_status are derived from the live (non-voided) payments of a payable expense. */
    private static function refreshPaid(Expense $expense): void
    {
        $paid = (int) $expense->payments()->whereNull('voided_at')->sum('amount');
        $expense->update([
            'paid_amount' => $paid,
            'payment_status' => $paid >= $expense->amount ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            'paid_on' => $paid >= $expense->amount ? $expense->payments()->whereNull('voided_at')->max('paid_on') : null,
        ]);
    }

    /**
     * Request the reversal of a verified expense. Like every correction it needs Board verification and is never automatic.
     * - Never paid: cancelled against its payable (no cash moves).
     * - Fully paid: $type states what really happened (recording error / refund received / credit note).
     * - Partly paid: void the payments first.
     */
    public static function reverse(Expense $original, ?string $type, string $reason, User $user): Expense
    {
        return DB::transaction(function () use ($original, $type, $reason, $user) {
            $original = Expense::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($original->status !== 'verified' || $original->reverses_id) {
                throw new RuntimeException('Only a verified, original expense can be reversed.');
            }
            if ($original->payment_status === 'reversed' || Expense::where('reverses_id', $original->id)->whereIn('status', ['pending', 'verified'])->exists()) {
                throw new RuntimeException('This expense already has a reversal.');
            }
            if (LoanReceivable::where('expense_id', $original->id)->whereIn('status', ['pending', 'verified'])->exists()) {
                throw new RuntimeException('This expense is being reclassified as a loan receivable; reverse that reclassification first.');
            }
            if ($original->classification === 'prepaid' && PrepaidRelease::where('expense_id', $original->id)->exists()) {
                throw new RuntimeException('Part of this prepaid expense has already been expensed; it cannot be reversed. Record an adjustment instead.');
            }
            if ($original->payment_status === 'partial') {
                throw new RuntimeException('Void the payments of this expense before reversing it.');
            }
            if ($original->payment_status === 'unpaid') {
                $type = 'liability_cancel';
            } elseif (! in_array($type, ['recording_error', 'refund_received', 'credit_note'], true)) {
                throw new RuntimeException('Choose what actually happened: a recording error, a refund received or a credit note.');
            }
            if (AccountingService::isClosed(today()->toDateString())) {
                throw new RuntimeException('The current month is closed; record the reversal after a new month opens.');
            }

            $reversal = ExpenseVerifier::record([
                'category' => $original->category, 'description' => 'Reverses expense #'.$original->id.' ('.$type.') — '.$reason,
                'amount' => $original->amount, 'spent_on' => today()->toDateString(), 'reverses_id' => $original->id, 'correction_type' => $type,
                'is_asset' => $original->is_asset, 'classification' => $original->classification, 'project_id' => $original->project_id,
                'financial_account_id' => $original->financial_account_id, 'payment_status' => 'paid', 'vendor' => $original->vendor,
            ], $user);
            Audit::log('expense.reversal_requested', 'expense '.$original->id, $type.': '.$reason);

            return $reversal;
        });
    }

    /** Called when the Board decides any expense: a verified full reversal retires the original. */
    public static function onDecided(Expense $expense, bool $approved): void
    {
        if (! $approved || ! $expense->reverses_id) {
            return;
        }
        $original = Expense::find($expense->reverses_id);
        if ($original && (int) Expense::where('reverses_id', $original->id)->where('status', 'verified')->sum('amount') >= $original->amount) {
            $original->update(['payment_status' => 'reversed']);
        }
    }

    /**
     * Explicitly split a shared expense across projects (replaces any earlier split). The split is reporting only: it never
     * changes the ledger, and shared costs are never allocated unless someone does this.
     *
     * @param  array<int, int>  $amountsByProject  project_id => minor units
     */
    public static function allocate(Expense $expense, array $amountsByProject): void
    {
        $amountsByProject = array_filter($amountsByProject, fn ($a) => $a > 0);
        if ($expense->status !== 'verified' || $expense->reverses_id || in_array($expense->classification, ['direct', 'asset', 'prepaid'], true)) {
            throw new RuntimeException('Only a verified shared (operating, other or tax) expense can be split across projects.');
        }
        if (array_sum($amountsByProject) > $expense->amount) {
            throw new RuntimeException('The split cannot exceed the expense amount of '.Money::format($expense->amount).'.');
        }
        DB::transaction(function () use ($expense, $amountsByProject) {
            $expense->allocations()->delete();
            foreach ($amountsByProject as $projectId => $amount) {
                ExpenseAllocation::create(['expense_id' => $expense->id, 'project_id' => $projectId, 'amount' => $amount]);
            }
            Audit::log('expense.allocated', 'expense '.$expense->id, json_encode($amountsByProject));
        });
    }
}
