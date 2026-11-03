<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostRecognition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'recognised_on' => 'date', 'verified_at' => 'datetime'];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(SourcingLot::class, 'sourcing_lot_id');
    }
}
