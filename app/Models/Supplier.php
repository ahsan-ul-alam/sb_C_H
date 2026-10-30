<?php

namespace App\Models;

use App\Services\SourcingPayments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Suppliers are archived (is_active = false), never deleted, so historical lots and payments stay intact.
 */
class Supplier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<SupplierProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /**
     * @return HasMany<SourcingLotLine, $this>
     */
    public function lotLines(): HasMany
    {
        return $this->hasMany(SourcingLotLine::class);
    }

    /**
     * @return HasMany<SupplierPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /**
     * What SellBees owes this supplier across live lots: purchase value (cost, recognised regardless of payment),
     * payments made (voided ones excluded) and the outstanding payable.
     *
     * @return array{purchases: int, paid: int, outstanding: int}
     */
    public function payable(): array
    {
        $purchases = (int) $this->lotLines()->whereHas('lot', fn ($q) => $q->where('status', '!=', 'cancelled'))->get()
            ->sum(fn (SourcingLotLine $l) => $l->purchaseCost());
        $paid = (int) SourcingPayments::paidTotal($this->payments()->with(['expense', 'corrections'])->get());

        return ['purchases' => $purchases, 'paid' => $paid, 'outstanding' => max(0, $purchases - $paid)];
    }

    public function displayName(): string
    {
        return $this->company_name ? $this->name.' ('.$this->company_name.')' : $this->name;
    }
}
