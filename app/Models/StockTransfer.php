<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransfer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<SourcingLotLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SourcingLotLine::class, 'sourcing_lot_line_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }
}
