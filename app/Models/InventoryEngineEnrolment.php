<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approved decision to bring one lot into the inventory valuation engine: its ownership classification, the evidence, the approver and the
 * effective date. Forward-only: the engine never reaches back before the effective date.
 */
class InventoryEngineEnrolment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'approved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<SourcingLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SourcingLot::class, 'sourcing_lot_id');
    }

    public function isValued(): bool
    {
        return $this->status === 'approved' && $this->ownership === 'company';
    }
}
