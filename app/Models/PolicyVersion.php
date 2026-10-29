<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An approved policy version is immutable apart from the separate profit-ratio confirmation.
 */
class PolicyVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'is_legacy' => 'boolean',
            'investment_bps' => 'array',
            'ownership_shares' => 'array',
            'loss_shares' => 'array',
            'profit_bps' => 'array',
            'approved_at' => 'datetime',
            'profit_confirmed_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null && filled($this->signed_reference);
    }

    public function isProfitConfirmed(): bool
    {
        return $this->profit_confirmed_at !== null && filled($this->profit_signed_reference);
    }

    /**
     * DRAFT (unsigned) -> APPROVED (signed, not yet usable) -> ACTIVE (signed, profit ratio confirmed, effective) -> SUPERSEDED.
     */
    public function status(): string
    {
        if (! $this->isApproved()) {
            return 'DRAFT';
        }
        if (! $this->isProfitConfirmed() || $this->effective_from->isFuture()) {
            return 'APPROVED';
        }
        $newer = static::whereDate('effective_from', '<=', today())->where('id', '!=', $this->id)
            ->whereNotNull('approved_at')->whereNotNull('profit_confirmed_at')
            ->where(fn ($q) => $q->whereDate('effective_from', '>', $this->effective_from)
                ->orWhere(fn ($q) => $q->whereDate('effective_from', $this->effective_from)->where('id', '>', $this->id)))
            ->exists();

        return $newer ? 'SUPERSEDED' : 'ACTIVE';
    }

    /**
     * Why this policy cannot be used for LIVE posting, or null when it can.
     */
    public function liveBlocker(): ?string
    {
        if (! $this->isApproved()) {
            return 'Policy "'.$this->name.'" has no signed Board approval and effective date yet.';
        }
        if (! $this->isProfitConfirmed()) {
            return 'The Partners\' Profit Fund ratio of policy "'.$this->name.'" has not been separately confirmed with a signed reference.';
        }

        return null;
    }
}
