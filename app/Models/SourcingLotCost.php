<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourcingLotCost extends Model
{
    public const CATEGORIES = [
        'Transportation', 'Delivery', 'Loading/unloading', 'Packaging', 'Handling', 'Processing', 'Customs',
        'Non-recoverable VAT/tax', 'Quality inspection', 'Other direct cost',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'incurred_on' => 'date', 'is_unexpected' => 'boolean'];
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

    /** Paid once an expense exists that Finance has not rejected; a rejected expense frees the cost to be paid again. */
    public function isPaid(): bool
    {
        return $this->expense_id !== null && $this->expense?->status !== 'rejected';
    }
}
