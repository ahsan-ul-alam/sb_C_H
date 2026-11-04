<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One append-only movement of quantity and/or value (integer minor units) of a single lot line. Corrections are new rows that point back at what
 * they reverse; a row is never edited or deleted.
 */
class InventoryValueMovement extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory value movements are immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory value movements cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['value_delta' => 'integer', 'quantity_delta' => 'decimal:2', 'effective_on' => 'date', 'verified_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<SourcingLotLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SourcingLotLine::class, 'sourcing_lot_line_id');
    }

    /**
     * @return BelongsTo<SourcingLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SourcingLot::class, 'sourcing_lot_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
