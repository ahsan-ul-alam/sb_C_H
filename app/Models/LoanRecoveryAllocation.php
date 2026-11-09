<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRecoveryAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'verified_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    /** An allocation is an audit record: it is reversed, never deleted. */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('A loan recovery allocation cannot be deleted; reverse it instead.'));
    }

    /** @return BelongsTo<LoanReceivableRepayment, $this> */
    public function repayment(): BelongsTo
    {
        return $this->belongsTo(LoanReceivableRepayment::class, 'loan_receivable_repayment_id');
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
