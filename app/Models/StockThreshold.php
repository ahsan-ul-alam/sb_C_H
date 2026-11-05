<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockThreshold extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['minimum_quantity' => 'decimal:2'];
    }

    /** @return BelongsTo<SupplierProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class, 'supplier_product_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }
}
