<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'date', 'voided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<SourcingLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SourcingLot::class, 'sourcing_lot_id');
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * Correcting expenses that reverse this payment's expense.
     *
     * @return HasMany<Expense, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(Expense::class, 'reverses_id', 'expense_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * What this payment still counts as paid toward the supplier: nothing if voided or its expense was rejected, and
     * less the amount of verified corrections for a recording error or a refund. A credit note does not change it.
     */
    public function effectiveAmount(): int
    {
        if ($this->isVoided() || $this->expense?->status === 'rejected') {
            return 0;
        }
        $reversed = (int) $this->corrections->where('status', 'verified')->whereIn('correction_type', ['recording_error', 'refund_received'])->sum('amount');

        return max(0, $this->amount - $reversed);
    }

    public function isEffective(): bool
    {
        return $this->effectiveAmount() > 0;
    }
}
