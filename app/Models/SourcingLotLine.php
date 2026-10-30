<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourcingLotLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'usable_quantity' => 'decimal:2', 'unit_price' => 'integer', 'reference_price' => 'integer',
            'sell_price' => 'integer', 'approved_price' => 'integer',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<SupplierProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class, 'supplier_product_id');
    }

    public function purchaseCost(): int
    {
        return Money::multiply((string) $this->quantity, (int) $this->unit_price);
    }

    /** The client price in force: the approved snapshot, else the proposed price. */
    public function clientPrice(): ?int
    {
        return $this->approved_price ?? $this->sell_price;
    }
}
