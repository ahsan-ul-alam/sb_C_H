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

    /** Value of every unit ordered (the base the delivery charge is shared over). */
    public function orderedValue(): int
    {
        return (int) $this->items->sum(fn (ClientOrderItem $i) => $i->amount());
    }

    /**
     * The part of the delivery charge that is deducted from a successful sale of $successValue. The charge is recorded once for the whole order and shared over
     * the order's lines in proportion to value, so a partial delivery or a return carries exactly its share (half-up, never more than the charge). A charge
     * borne by Sellbees or paid by the customer on top is never deducted from the sale.
     */
    public function eligibleDeliveryCharge(int $successValue): int
    {
        $ordered = $this->orderedValue();
        if ((int) $this->delivery_charge <= 0 || $this->delivery_charge_bearer !== 'client' || $ordered <= 0 || $successValue <= 0) {
            return 0;
        }

        return min((int) $this->delivery_charge, (int) bcadd(bcdiv(bcmul((string) $this->delivery_charge, (string) min($successValue, $ordered), 0), (string) $ordered, 4), '0.5', 0));
    }

    /** What commission is worked out on: successful product sales (delivered and not returned) less the eligible client-borne delivery charge. */
    public function commissionBase(): int
    {
        $sales = $this->deliveredValue();

        return max(0, $sales - $this->eligibleDeliveryCharge($sales));
    }

    /** The customer money Sellbees can actually receive for the order: a charge the courier kept before remitting is never collected, so never owed. */
    public function collectibleValue(): int
    {
        $sales = $this->deliveredValue();

        return $this->delivery_charge_status === 'courier_deducted' ? max(0, $sales - $this->eligibleDeliveryCharge($sales)) : $sales;
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
