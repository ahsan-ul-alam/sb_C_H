<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved (or pending) decision to spread one capitalised lot cost over named beneficiary lines under a documented cost policy.
 */
class LandedCostReallocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['beneficiary_line_ids' => 'array', 'approved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<SourcingLotCost, $this>
     */
    public function cost(): BelongsTo
    {
        return $this->belongsTo(SourcingLotCost::class, 'sourcing_lot_cost_id');
    }
}
