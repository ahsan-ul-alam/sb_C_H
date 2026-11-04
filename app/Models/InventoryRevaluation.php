<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryRevaluation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'effective_on' => 'date', 'posted_on' => 'date', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'prior_period' => 'boolean'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SourcingLotLine::class, 'sourcing_lot_line_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function delete(): never
    {
        throw new \LogicException('InventoryRevaluation is part of the audit trail and is never deleted.');
    }
}
