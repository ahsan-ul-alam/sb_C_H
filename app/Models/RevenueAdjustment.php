<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'verified_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
