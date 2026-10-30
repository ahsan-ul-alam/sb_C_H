<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Resolution;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        if ($e->reverses_id) {
            $debit = $e->correction_type === 'credit_note' ? 'supplier.credit' : 'cash';

            return [[$debit, null, $amount, 0], [self::original($e)->is_asset ? 'assets.equipment' : 'expense', null, 0, $amount]];
        }

        return [[$e->is_asset ? 'assets.equipment' : 'expense', null, $amount, 0], ['cash', null, 0, $amount]];
    }

    private static function original(Expense $e): Expense
    {
        return Expense::findOrFail($e->reverses_id);
    }

    public static function post(Expense $e, ?int $userId): void
    {
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
     * Create an expense. Verified and posted at once when a standing policy covers it, otherwise pending for the Board.
     *
     * @param  array<string, mixed>  $attrs  category, description, amount, spent_on, [is_asset, reverses_id, correction_type]
     * @param  array{sourcing?: bool, lot_ok?: bool, unexpected?: bool}  $context
     */
    public static function record(array $attrs, User $creator, array $context = []): Expense
    {
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
