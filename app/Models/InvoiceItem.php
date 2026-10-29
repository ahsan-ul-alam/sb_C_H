<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['segment_id', 'description', 'quantity', 'unit', 'unit_price', 'amount', 'position'])]
class InvoiceItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'integer',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * Quantity without trailing zeros for display ("1" instead of "1.00").
     */
    public function displayQuantity(): string
    {
        return rtrim(rtrim((string) $this->quantity, '0'), '.');
    }

    /**
     * Quantity with its optional unit, e.g. "15 pcs".
     */
    public function displayQuantityWithUnit(): string
    {
        return trim($this->displayQuantity().' '.$this->unit);
    }
}
