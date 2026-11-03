<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptClassification extends Model
{
    public const NATURES = [
        'service' => 'Service fee (onboarding, marketing, other services)',
        'product_sourcing' => 'Product sourcing (the client pays for products Sellbees buys for them)',
        'other' => 'Other',
    ];

    protected $guarded = [];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
