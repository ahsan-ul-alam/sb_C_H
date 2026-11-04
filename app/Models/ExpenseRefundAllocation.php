<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part of an expense reversal: how much comes back, to which financial account, and which original payment it reverses
 * (no payment for an expense that was paid directly in one go).
 */
class ExpenseRefundAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function reversal(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'reversal_expense_id');
    }

    /**
     * @return BelongsTo<ExpensePayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(ExpensePayment::class, 'expense_payment_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }
}
