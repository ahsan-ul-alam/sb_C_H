<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maker-checker request to void one customer payment. The payment itself is never deleted or rewritten.
 */
class PaymentVoidRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'decided_at' => 'datetime'];
    }

    /** The payment, whether or not it has been voided (history must stay visible). */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScope(Payment::ACTIVE_SCOPE);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
