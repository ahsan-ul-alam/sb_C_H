<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatementReconcilingItem extends Model
{
    public const KINDS = [
        'payment_not_cleared' => 'Payment recorded in the books, not yet on the statement',
        'receipt_in_transit' => 'Receipt recorded in the books, not yet on the statement',
        'unrecorded_charge' => 'Bank charge or receipt on the statement, not yet in the books',
        'other' => 'Other (explain)',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(AccountStatement::class, 'account_statement_id');
    }
}
