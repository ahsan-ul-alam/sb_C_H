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
        return ['ordered_on' => 'date', 'net_value' => 'integer', 'commission' => 'integer', 'rate_bps' => 'integer', 'billed_at' => 'datetime', 'delivery_charge' => 'integer',
            'confirmed_at' => 'datetime', 'packed_at' => 'datetime', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /**
     * What the screens call the order's fulfilment state. The stored delivery_status stays one of the workflow steps; partial delivery and partial or
     * full return are read from the lines, so no second status can drift from the quantities.
     */
    public function fulfilmentLabel(): string
    {
        if ($this->delivery_status !== 'delivered') {
            return in_array($this->delivery_status, [null, 'pending'], true) ? 'Draft' : ucfirst((string) $this->delivery_status);
        }
        $items = $this->items;
        $returned = $items->contains(fn (ClientOrderItem $i) => bccomp((string) $i->returned_quantity, '0', 2) > 0);
        if ($returned && $items->every(fn (ClientOrderItem $i) => bccomp((string) $i->returned_quantity, (string) $i->delivered_quantity, 2) >= 0)) {
            return 'Returned';
        }
        if ($returned) {
            return 'Partially returned';
        }

        return $items->contains(fn (ClientOrderItem $i) => bccomp((string) $i->delivered_quantity, (string) $i->quantity, 2) < 0) ? 'Partially delivered' : 'Delivered';
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
