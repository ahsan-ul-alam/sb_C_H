<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyFundRequest extends Model
{
    /** Requests that still count against the lot's uncovered loss. */
    public const LIVE = ['pending', 'approved', 'utilised'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', 'loss_at_request' => 'integer',
            'decided_at' => 'datetime', 'utilised_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SourcingLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SourcingLot::class, 'sourcing_lot_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
