<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Control of a quantity of Sellbees-owned goods passing to a client project. Only an approved handover has an accounting effect.
 */
class InventoryHandover extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'returned_quantity' => 'decimal:2', 'damaged_returned_quantity' => 'decimal:2', 'proposed_on' => 'date', 'transferred_on' => 'date', 'approved_at' => 'datetime',
            'purchase_value' => 'integer', 'landed_value' => 'integer', 'cogs_value' => 'integer'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<SourcingAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(SourcingAllocation::class, 'sourcing_allocation_id');
    }

    /**
     * @return BelongsTo<SourcingLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SourcingLot::class, 'sourcing_lot_id');
    }

    /**
     * @return BelongsTo<SourcingLotLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SourcingLotLine::class, 'sourcing_lot_line_id');
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<InventoryHandoverReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(InventoryHandoverReturn::class);
    }

    /** Quantity still with the client: handed over, less what came back (resalable or damaged). */
    public function netQuantity(): string
    {
        return bcsub(bcsub((string) $this->quantity, (string) $this->returned_quantity, 2), (string) $this->damaged_returned_quantity, 2);
    }
}
