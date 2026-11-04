<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Goods coming back to Sellbees after a handover, under evidence. Resalable returns restore the original cost; damaged returns restore no value.
 */
class InventoryHandoverReturn extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'returned_on' => 'date', 'approved_at' => 'datetime', 'purchase_value' => 'integer', 'landed_value' => 'integer', 'restored_value' => 'integer'];
    }

    /**
     * @return BelongsTo<InventoryHandover, $this>
     */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(InventoryHandover::class, 'inventory_handover_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
