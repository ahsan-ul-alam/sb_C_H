<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientOrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'delivered_quantity' => 'decimal:2', 'returned_quantity' => 'decimal:2', 'unit_price' => 'integer'];
    }

    /** @return BelongsTo<ClientOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ClientOrder::class, 'client_order_id');
    }

    /** @return BelongsTo<SourcingLotLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SourcingLotLine::class, 'sourcing_lot_line_id');
    }

    public function amount(): int
    {
        return Money::multiply((string) $this->quantity, (int) $this->unit_price);
    }

    /** Value of what was really delivered and not returned: the only base commission and customer money can follow. */
    public function deliveredAmount(): int
    {
        return Money::multiply(bcsub((string) $this->delivered_quantity, (string) $this->returned_quantity, 2), (int) $this->unit_price);
    }
}
