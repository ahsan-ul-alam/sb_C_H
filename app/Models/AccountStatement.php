<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountStatement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'verified_at' => 'datetime', 'cutoff_at' => 'datetime', 'statement_balance' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StatementReconcilingItem::class);
    }
}
