<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A later event on a recognised supplier recovery: a credit note applied to a payable, or cash received against the supplier receivable.
 */
class SupplierReturnSettlement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'settled_on' => 'date', 'approved_at' => 'datetime'];
    }

    public function delete(): never
    {
        throw new \LogicException('A supplier return settlement is part of the audit trail and is never deleted.');
    }

    /** @return BelongsTo<SupplierReturn, $this> */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
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
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
