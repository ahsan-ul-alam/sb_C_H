<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proposal to classify an invoice line. Approved/rejected rows are history and are never edited or deleted.
 */
class InvoiceLineClassification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('A classification record is history and cannot be deleted.'));
        static::updating(function (self $row): void {
            if ($row->getOriginal('status') !== 'proposed') {
                throw new \LogicException('A decided classification cannot be changed; propose a new one.');
            }
        });
    }

    /** @return BelongsTo<InvoiceItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }
}
