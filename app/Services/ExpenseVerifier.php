<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseRefundAllocation;
use App\Models\Resolution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only place an expense becomes a journal entry. Used by the Board's manual verification and by automatic
 * verification under a standing Board policy. An automatic verification has no human verifier: it records the
 * authorising resolution (auto_policy_id) and writes the same journal entry, once (the ledger is idempotent per source).
 */
class ExpenseVerifier
{
    /**
     * Journal lines for a verified expense. A capital purchase goes to an asset account, not to expenses. A reversing
     * row follows its stated economic event: cash really came back (Dr cash), the entry never reflected real money
     * (Dr cash, restoring the true balance), or the supplier issued a credit note (Dr supplier credit — no cash moves).
     *
     * @return list<array{0: string, 1: int|null, 2: int, 3: int}>
     */
    public static function lines(Expense $e): array
    {
        $amount = (int) $e->amount;
        $accountId = AccountingService::resolveAccountId($e->financial_account_id);
        if ($e->reverses_id) {
            $expenseAccount = self::debitAccount(self::original($e));
            // An expense that was never paid is cancelled against its payable; nothing moves in cash.
            $debits = match ($e->correction_type) {
                'liability_cancel' => [['liabilities', null, $amount, 0]],
                'credit_note' => [['supplier.credit', null, $amount, 0]],
                default => self::refundLines($e, $amount, $accountId),
            };

            return [...$debits, [$expenseAccount, null, 0, $amount]];
        }

        $expenseAccount = self::debitAccount($e);

        // Recorded but not yet paid: the cost is real, but it is a payable until the money actually leaves an account.
        return $e->payment_status === 'unpaid'
            ? [[$expenseAccount, null, $amount, 0], ['liabilities', null, 0, $amount]]
            : [[$expenseAccount, null, $amount, 0], ['cash', null, 0, $amount, $accountId]];
    }

    /**
     * The cash side of a reversal. When the reversal states where each part comes back (one row per original payment, see
     * ExpenseService::reverse) every part goes to its own account; a reversal without rows (every earlier one, and a supplier-payment
     * correction) returns the whole amount to the single account on its own row.
     *
     * @return list<array{0: string, 1: int|null, 2: int, 3: int, 4: int|null}>
     */
    private static function refundLines(Expense $e, int $amount, ?int $accountId): array
    {
        $parts = ExpenseRefundAllocation::where('reversal_expense_id', $e->id)->orderBy('id')->get();
        if ($parts->isEmpty()) {
            return [['cash', null, $amount, 0, $accountId]];
        }
        if ((int) $parts->sum('amount') !== $amount) {
            throw new RuntimeException('The refund parts of this reversal do not add up to its amount.');
        }

        return $parts->map(fn (ExpenseRefundAllocation $p) => ['cash', null, (int) $p->amount, 0, $p->financial_account_id])->all();
    }

    /**
     * Equipment and other lasting assets, prepaid costs, supplier advances and capitalised product acquisition costs are assets when
     * paid; everything else is an expense. (The last two are set only by the sourcing module under finance.sourcing_basis=accrual.)
     */
    private static function debitAccount(Expense $e): string
    {
        return match (true) {
            $e->classification === 'supplier_advance' => 'assets.supplier_advances',
            $e->classification === 'supplier_payable' => 'liabilities', // paying a supplier payable already in the books: Dr payable / Cr cash
            $e->classification === 'acquisition' => 'assets.inventory',
            $e->classification === 'transport_prepayment' => 'assets.transport_prepaid', // paid before the service: a prepayment until the service is confirmed
            $e->classification === 'prepaid' => 'assets.prepaid',
            (bool) $e->is_asset => 'assets.equipment',
            default => 'expense',
        };
    }

    private static function original(Expense $e): Expense
    {
        return Expense::findOrFail($e->reverses_id);
    }

    public static function post(Expense $e, ?int $userId): void
    {
        if ($e->reverses_id) {
            // What was true when the reversal was requested may not be now (a payment voided, a prepaid release made): decide on the facts
            // as they are at the moment of posting. Money coming back may only land in an active account; a credit note or the cancellation
            // of an unpaid expense moves no cash.
            ExpenseService::assertReversalEligible($e);
            $parts = ExpenseRefundAllocation::where('reversal_expense_id', $e->id)->get();
            foreach ($parts as $part) {
                AccountingService::assertActive($part->financial_account_id);
            }
            if ($parts->isEmpty() && ! in_array($e->correction_type, ['liability_cancel', 'credit_note'], true)) {
                AccountingService::assertActive($e->financial_account_id);
            }
        } elseif ($e->payment_status !== 'unpaid') {
            AccountingService::assertActive($e->financial_account_id);
            AccountingService::assertCanSpend(AccountingService::resolveAccountId($e->financial_account_id), (int) $e->amount);
        }
        Ledger::post($e->spent_on->toDateString(), $e->category.': '.$e->description, 'expense', $e->id, self::lines($e), $userId);
    }

    /**
     * The standing policy that covers this expense, or null when it needs a human. Conditions: valid source, inside the
     * Board's per-item limit and category list, correctly typed (never capital purchases or corrections), not a duplicate.
     *
     * @param  array<string, mixed>  $attrs
     * @param  array{sourcing?: bool, lot_ok?: bool, unexpected?: bool}  $context
     */
    public static function autoPolicy(array $attrs, array $context = []): ?Resolution
    {
        if (! empty($attrs['is_asset']) || ! empty($attrs['reverses_id']) || ($attrs['amount'] ?? 0) <= 0) {
            return null;
        }
        if (Expense::where('category', $attrs['category'])->where('description', $attrs['description'])->where('amount', $attrs['amount'])
            ->whereDate('spent_on', $attrs['spent_on'])->where('status', '!=', 'rejected')->exists()) {
            return null;
        }

        if (! empty($context['sourcing'])) {
            $policy = StandingPolicies::active(StandingPolicies::SUPPLIER_PAYMENTS);
            if (! $policy || empty($context['lot_ok']) || ! empty($context['unexpected'])) {
                return null;
            }
        } else {
            $policy = StandingPolicies::active(StandingPolicies::EXPENSES);
            if (! $policy || ! in_array(mb_strtolower(trim($attrs['category'])), StandingPolicies::categories($policy), true)) {
                return null;
            }
        }

        return $policy->amount > 0 && $attrs['amount'] <= $policy->amount ? $policy : null;
    }

    /**
     * Keep the capital-purchase flag and classification in step, and give a paid expense its paid amount and date.
     * An expense is "paid" unless the caller says it is not (everything recorded before payment tracking existed was).
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private static function normalise(array $attrs): array
    {
        if (! empty($attrs['is_asset'])) {
            $attrs['classification'] = 'asset';
        }
        if (($attrs['classification'] ?? null) === 'asset') {
            $attrs['is_asset'] = true;
        }
        $attrs['payment_status'] ??= 'paid';
        if ($attrs['payment_status'] === 'paid') {
            $attrs['paid_amount'] ??= $attrs['amount'];
            $attrs['paid_on'] ??= $attrs['spent_on'];
        }

        return $attrs;
    }

    /**
     * Create an expense. Verified and posted at once when a standing policy covers it, otherwise pending for the Board.
     *
     * @param  array<string, mixed>  $attrs  category, description, amount, spent_on, [is_asset, reverses_id, correction_type]
     * @param  array{sourcing?: bool, lot_ok?: bool, unexpected?: bool}  $context
     */
    public static function record(array $attrs, User $creator, array $context = []): Expense
    {
        AccountingService::assertActive(isset($attrs['financial_account_id']) ? (int) $attrs['financial_account_id'] : null);
        $attrs = self::normalise($attrs);

        return DB::transaction(function () use ($attrs, $creator, $context) {
            $policy = self::autoPolicy($attrs, $context);
            $expense = Expense::create([...$attrs, 'created_by' => $creator->id, ...($policy ? ['status' => 'verified', 'verified_at' => now(), 'auto_policy_id' => $policy->id] : [])]);
            if ($policy) {
                self::post($expense, null);
                Audit::log('expense.auto_verified', 'expense '.$expense->id, 'standing policy #'.$policy->id.' — '.$expense->category.' '.$expense->amount);
            }

            return $expense->refresh();
        });
    }
}
