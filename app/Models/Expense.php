<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Expense extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'paid_on' => 'date', 'verified_at' => 'datetime', 'is_asset' => 'boolean', 'amount' => 'integer', 'paid_amount' => 'integer'];
    }

    /**
     * Leave out an expense the Board has verified as really being a loan (see LoanReceivableService). The expense row and its journal
     * entry are never touched; the reclassification is a separate verified record, so undoing it brings the expense back.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeNotReclassifiedAsLoan(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('loan_receivables')
            ->whereColumn('loan_receivables.expense_id', 'expenses.id')->where('loan_receivables.status', 'verified'));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ExpenseAllocation::class);
    }

    /** What is still owed on a recorded, verified expense (zero for corrections and rejected rows). */
    public function outstanding(): int
    {
        if ($this->reverses_id || $this->status !== 'verified' || in_array($this->payment_status, ['paid', 'reversed'], true)) {
            return 0;
        }

        return max(0, $this->amount - $this->paid_amount);
    }
}
