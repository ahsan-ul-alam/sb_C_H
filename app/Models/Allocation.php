<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Allocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'policy_version_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AllocationLine::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $allocation): void {
            if ($allocation->getOriginal('status') === 'posted') {
                throw new \LogicException('A posted allocation is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Allocations cannot be deleted.'));
    }
}
