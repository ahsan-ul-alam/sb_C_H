<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $guarded = [];

    public function entries(): HasMany
    {
        return $this->hasMany(LoanEntry::class);
    }

    /** Principal still owed: verified receipts minus verified principal repayments. */
    public function outstanding(): int
    {
        $sum = fn (string $kind) => (int) $this->entries()->where('kind', $kind)->where('status', 'verified')->sum('amount');

        return $sum('receipt') - $sum('principal');
    }
}
