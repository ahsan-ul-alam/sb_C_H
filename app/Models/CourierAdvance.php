<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierAdvance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'applied' => 'integer', 'released' => 'integer', 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<ClientOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ClientOrder::class, 'client_order_id');
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** What is still owed by the client on this advance and not yet cleared or released. */
    public function open(): int
    {
        return max(0, (int) $this->amount - (int) $this->applied - (int) $this->released);
    }
}
