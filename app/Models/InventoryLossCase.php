<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finding of damage, loss or impairment of a quantity of one lot line, and the single financial treatment decided for it.
 */
class InventoryLossCase extends Model
{
    /** Statuses in which the units are spoken for: they cannot be put into another case. */
    public const ACTIVE = ['pending_valuation', 'pending_review', 'reviewed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'event_on' => 'date', 'discovered_on' => 'date', 'posted_on' => 'date', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'prior_period' => 'boolean',
            'recoverable_value' => 'integer', 'carrying_value' => 'integer', 'loss_value' => 'integer', 'cogs_reversed' => 'integer'];
    }

    public function delete(): never
    {
        throw new \LogicException('An inventory loss case is part of the audit trail and is never deleted.');
    }

    /** @return BelongsTo<SourcingLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SourcingLot::class, 'sourcing_lot_id');
    }

    /** @return BelongsTo<SourcingLotLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SourcingLotLine::class, 'sourcing_lot_line_id');
    }

    /** @return BelongsTo<InventoryHandover, $this> */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(InventoryHandover::class, 'inventory_handover_id');
    }

    /** @return BelongsTo<InventoryHandoverReturn, $this> */
    public function handoverReturn(): BelongsTo
    {
        return $this->belongsTo(InventoryHandoverReturn::class, 'inventory_handover_return_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
