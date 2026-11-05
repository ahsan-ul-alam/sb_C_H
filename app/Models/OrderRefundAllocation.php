<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRefundAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /** @return BelongsTo<OrderRefund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class, 'order_refund_id');
    }

    /** @return BelongsTo<OrderCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(OrderCollection::class, 'order_collection_id');
    }
}
