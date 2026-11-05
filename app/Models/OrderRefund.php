<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRefund extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'decided_at' => 'datetime', 'paid_on' => 'date'];
    }

    /** @return BelongsTo<ClientOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ClientOrder::class, 'client_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
