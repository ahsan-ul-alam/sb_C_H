<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpairedStockEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'event_on' => 'date', 'posted_on' => 'date', 'approved_at' => 'datetime', 'prior_period' => 'boolean', 'proceeds' => 'integer', 'carrying_value' => 'integer', 'original_cost' => 'integer', 'loss_delta' => 'integer'];
    }

    /** @return BelongsTo<InventoryLossCase, $this> */
    public function lossCase(): BelongsTo
    {
        return $this->belongsTo(InventoryLossCase::class, 'inventory_loss_case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function delete(): never
    {
        throw new \LogicException('ImpairedStockEvent is part of the audit trail and is never deleted.');
    }
}
