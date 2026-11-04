<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods sent back to a supplier and the recovery claimed for them. Only an approved return has an accounting effect.
 */
class SupplierReturn extends Model
{
    /** Statuses in which the units and the return document are spoken for. */
    public const ACTIVE = ['requested', 'verified'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'physical_returned_on' => 'date', 'event_on' => 'date', 'posted_on' => 'date', 'acceptance_at' => 'datetime', 'verified_at' => 'datetime', 'approved_at' => 'datetime',
            'prior_period' => 'boolean', 'claimed_amount' => 'integer', 'accepted_amount' => 'integer', 'payable_reduction' => 'integer', 'credit_note_amount' => 'integer', 'receivable_amount' => 'integer',
            'purchase_value' => 'integer', 'landed_value' => 'integer', 'carrying_value' => 'integer', 'difference_value' => 'integer'];
    }

    public function delete(): never
    {
        throw new \LogicException('A supplier return is part of the audit trail and is never deleted.');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return HasMany<SupplierReturnSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(SupplierReturnSettlement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acceptance_by');
    }
}
