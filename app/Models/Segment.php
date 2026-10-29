<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'amount'])]
class Segment extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Amount received against this segment in minor units, optionally ignoring one payment (for edits).
     */
    public function receivedAmount(?int $exceptPaymentId = null): int
    {
        return (int) $this->allocations()
            ->when($exceptPaymentId, fn ($query) => $query->where('payment_id', '!=', $exceptPaymentId))
            ->sum('amount');
    }

    public function balance(?int $exceptPaymentId = null): int
    {
        return $this->amount - $this->receivedAmount($exceptPaymentId);
    }

    public function canBeDeleted(): bool
    {
        return ! $this->allocations()->exists() && ! $this->invoiceItems()->exists();
    }
}
