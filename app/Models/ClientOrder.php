<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'net_value' => 'integer', 'commission' => 'integer', 'rate_bps' => 'integer', 'billed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ClientOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ClientOrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderCollection, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(OrderCollection::class)->orderBy('id');
    }

    public function isStock(): bool
    {
        return $this->fulfilment === 'stock';
    }

    /** Value delivered and not returned (the commission base and the most that can be collected for the client). */
    public function deliveredValue(): int
    {
        return (int) $this->items->sum(fn (ClientOrderItem $i) => $i->deliveredAmount());
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'billed_invoice_id');
    }
}
