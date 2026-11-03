<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountTransfer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transferred_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'from_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'to_account_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
