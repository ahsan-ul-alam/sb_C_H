<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
