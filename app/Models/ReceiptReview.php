<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['owner_confirmed_on' => 'date', 'account_verified' => 'boolean', 'revenue_recognition_verified' => 'boolean', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
