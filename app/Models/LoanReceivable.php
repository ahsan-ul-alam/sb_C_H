<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanReceivable extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['lent_on' => 'date', 'verified_at' => 'datetime', 'principal' => 'integer'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'borrower_partner_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanReceivableRepayment::class);
    }

    /** Principal still to be paid back: zero until the loan is verified, then principal minus verified repayments. */
    public function outstanding(): int
    {
        if ($this->status !== 'verified') {
            return 0;
        }

        return $this->principal - (int) $this->repayments()->where('status', 'verified')->sum('amount');
    }

    /** Repayments recorded but not yet verified; they reserve part of the outstanding amount so none can be recorded twice. */
    public function pendingRepayments(): int
    {
        return (int) $this->repayments()->where('status', 'pending')->sum('amount');
    }
}
