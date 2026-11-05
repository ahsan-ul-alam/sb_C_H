<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourcingArrivalEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_quantity' => 'decimal:2', 'accepted_quantity' => 'decimal:2', 'rejected_quantity' => 'decimal:2', 'damaged_quantity' => 'decimal:2', 'arrived_on' => 'date'];
    }

    /** @return BelongsTo<SourcingLotLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SourcingLotLine::class, 'sourcing_lot_line_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
