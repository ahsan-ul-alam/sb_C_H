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
        return ['amount' => 'integer', 'incurred_on' => 'date', 'service_completed_on' => 'date', 'is_unexpected' => 'boolean'];
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
     * Paid once an expense exists that Finance has not rejected and that is not still an unpaid or part-paid payable; a rejected
     * expense frees the cost to be paid again.
     */
    public function isPaid(): bool
    {
        return $this->expense_id !== null && $this->expense?->status !== 'rejected' && ! in_array($this->expense?->payment_status, ['unpaid', 'partial'], true);
    }

    /**
     * What is still owed on this cost: all of it until an expense that has not been rejected exists, then only what is left unpaid on a
     * payable (a part-paid cost owes the remainder, never its original amount). Zero once it is paid.
     */
    public function remainingUnpaid(): int
    {
        if ($this->expense_id === null || $this->expense?->status === 'rejected') {
            return (int) $this->amount;
        }
        if (in_array($this->expense->payment_status, ['unpaid', 'partial'], true)) {
            return max(0, (int) $this->amount - (int) $this->expense->paid_amount);
        }

        return 0;
    }

    /**
     * What has been paid toward this cost: the whole amount for a cost paid in one go (even while the expense awaits verification, the money
     * has left), the live payments of a part-paid payable, and nothing for an unpaid or refused one.
     */
    public function paidAmount(): int
    {
        if ($this->expense_id === null || $this->expense?->status === 'rejected') {
            return 0;
        }

        return match ($this->expense->payment_status) {
            'unpaid' => 0,
            'partial' => min((int) $this->amount, (int) $this->expense->paid_amount),
            default => (int) $this->amount,
        };
    }

    /** Recorded (capitalised) as a payable that has not been fully paid yet. */
    public function isPayable(): bool
    {
        return $this->expense_id !== null && $this->expense?->status !== 'rejected' && in_array($this->expense?->payment_status, ['unpaid', 'partial'], true);
    }
}
