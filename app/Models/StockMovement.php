<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    /** A stock movement is history: it is never edited or deleted, a correction is always a new movement with a reason. */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('A stock movement cannot be changed; record a correcting movement instead.'));
        static::deleting(fn () => throw new \LogicException('A stock movement cannot be deleted; record a correcting movement instead.'));
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
