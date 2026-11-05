<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LotOwnershipRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<SourcingLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SourcingLot::class, 'sourcing_lot_id');
    }
}
