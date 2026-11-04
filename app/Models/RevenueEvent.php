<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer-facing accounting event of a project (revenue earned, advance applied, contract asset invoiced, credit note, refund), as signed position deltas.
 */
class RevenueEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'posted_on' => 'date', 'approved_at' => 'datetime', 'prior_period' => 'boolean', 'amount' => 'integer', 'revenue_delta' => 'integer', 'receivable_delta' => 'integer',
            'contract_asset_delta' => 'integer', 'advance_delta' => 'integer', 'cash_delta' => 'integer'];
    }

    public function delete(): never
    {
        throw new \LogicException('A revenue event is part of the audit trail and is never deleted.');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
