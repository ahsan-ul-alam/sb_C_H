<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reference_price' => 'integer', 'is_active' => 'boolean', 'min_order_qty' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<SupplierProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(SupplierProductPrice::class)->orderByDesc('id');
    }

    public function label(): string
    {
        return trim($this->name.($this->variant ? ' — '.$this->variant : ''));
    }
}
