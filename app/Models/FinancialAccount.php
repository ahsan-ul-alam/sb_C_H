<?php

namespace App\Models;

use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A place money is held (office cash, a bank, mobile banking, petty cash). Its balance is never stored: it is the
 * debit-minus-credit of the journal's cash lines that carry this account.
 */
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    public const TYPES = ['cash' => 'Cash', 'bank' => 'Bank account', 'mobile' => 'Mobile banking', 'petty' => 'Petty cash'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'allow_negative' => 'boolean'];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
