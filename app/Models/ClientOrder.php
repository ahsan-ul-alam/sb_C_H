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
        // Only a charge RECORDED at dispatch counts. An order from before this rule (or one whose charge was only typed in when it was created) has no recorded charge,
        // so no default status or stray figure can put a new deduction on it.
        if ((int) $this->delivery_charge <= 0 || $this->delivery_charge_recorded_at === null || $this->delivery_charge_bearer !== 'client' || $ordered <= 0 || $successValue <= 0) {
            return 0;
        }

        return min((int) $this->delivery_charge - (int) $this->courier_refund, (int) bcadd(bcdiv(bcmul((string) $this->delivery_charge, (string) min($successValue, $ordered), 0), (string) $ordered, 4), '0.5', 0));
    }

    /** What commission is worked out on: successful product sales (delivered and not returned) less the eligible client-borne delivery charge. */
    public function commissionBase(): int
    {
        $sales = $this->deliveredValue();

        return max(0, $sales - $this->eligibleDeliveryCharge($sales));
    }

    /** Courier charges of a failed delivery and of a return that the client bears. */
    public function unsuccessfulCharges(): int
    {
        return $this->delivery_charge_bearer === 'client' ? (int) $this->failed_delivery_charge + (int) $this->return_charge : 0;
    }

    /**
     * What reduces the money Sellbees owes the client for this order, given how the charge is settled. A charge the courier kept, one Sellbees paid or one still to be
     * paid is deducted from the client's entitlement ONCE; one the client paid the courier directly is never deducted again, and one Sellbees bears never is.
     * (Failed and return charges follow a charge Sellbees pays or that is still payable.) The client's profit deducts every client-borne charge once, whoever paid it.
     */
    public function settlementDeduction(int $eligible): int
    {
        if ($this->delivery_charge_bearer !== 'client') {
            return 0;
        }
        $deduction = in_array($this->delivery_charge_status, ['payable', 'paid_by_sellbees', 'courier_deducted'], true) ? $eligible : 0;
        if (in_array($this->delivery_charge_status, ['payable', 'paid_by_sellbees'], true)) {
            $deduction += $this->unsuccessfulCharges();
        }

        return $deduction;
    }

    /** The part of a charge Sellbees paid for the client that is still to be recovered (billed on an invoice and netted at settlement). */
    public function recoverableCharge(): int
    {
        if ($this->delivery_charge_bearer !== 'client' || $this->delivery_charge_status !== 'paid_by_sellbees') {
            return 0;
        }
        $sales = $this->delivery_status === 'delivered' ? $this->deliveredValue() : 0;

        return $this->eligibleDeliveryCharge($sales) + $this->unsuccessfulCharges();
    }

    /**
     * The courier charge actually incurred that is NOT passed to the client: what the courier charged less the part carried by the units that were delivered and kept
     * (the eligible share) less anything the courier genuinely refunded. It is Sellbees' own cost (a returned or undelivered share the courier did not refund, or a charge
     * Sellbees bears) and is never deducted from the client or refunded automatically. Only a charge recorded at dispatch on a delivered order counts.
     */
    public function unrecoveredCourierCost(): int
    {
        if ($this->delivery_status !== 'delivered' || $this->delivery_charge_recorded_at === null) {
            return 0;
        }

        return max(0, (int) $this->delivery_charge - (int) $this->courier_refund - $this->eligibleDeliveryCharge($this->deliveredValue()));
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
