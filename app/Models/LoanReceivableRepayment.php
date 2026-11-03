<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanReceivableRepayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['repaid_on' => 'date', 'verified_at' => 'datetime', 'amount' => 'integer'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanReceivable::class, 'loan_receivable_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }
}
