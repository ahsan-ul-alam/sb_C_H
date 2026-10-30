<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourcingLot extends Model
{
    public const STATUSES = [
        'draft' => 'Draft', 'costing' => 'Costing', 'priced' => 'Priced', 'approved' => 'Approved',
        'in_progress' => 'In progress', 'completed' => 'Completed', 'settled' => 'Financially settled', 'cancelled' => 'Cancelled',
    ];

    /** Forward steps of the lifecycle (approval and settlement have their own guarded actions). */
    public const FLOW = ['draft', 'costing', 'priced', 'approved', 'in_progress', 'completed', 'settled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pricing_value' => 'integer', 'approved_cost' => 'integer', 'approved_revenue' => 'integer',
            'approved_at' => 'datetime', 'settled_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SourcingLotLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SourcingLotLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<SourcingLotCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(SourcingLotCost::class)->orderBy('incurred_on')->orderBy('id');
    }

    /**
     * @return HasMany<SourcingAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SourcingAllocation::class)->orderBy('id');
    }

    /**
     * @return HasMany<SupplierPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderByDesc('paid_on')->orderByDesc('id');
    }

    /**
     * @return HasMany<EmergencyFundRequest, $this>
     */
    public function fundRequests(): HasMany
    {
        return $this->hasMany(EmergencyFundRequest::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['settled', 'cancelled'], true);
    }

    /** Lines (quantities, supplier prices) may change until pricing is approved. */
    public function linesEditable(): bool
    {
        return in_array($this->status, ['draft', 'costing', 'priced'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function nextNumber(): string
    {
        $last = (int) static::query()->max('id');

        return 'SL-'.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }
}
