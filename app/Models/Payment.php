<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * One row per real-world transaction. It always belongs to a project, may belong to an
 * invoice, and may be allocated to one or more segments. Totals sum this table once.
 */
#[Fillable(['amount', 'paid_on', 'method', 'reference', 'notes', 'financial_account_id'])]
class Payment extends Model
{
    public const ACTIVE_SCOPE = 'active';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date',
            'voided_at' => 'datetime',
            'method' => PaymentMethod::class,
        ];
    }

    /**
     * A payment whose month has been closed in the finance ledger is frozen: changing it would silently
     * disagree with the frozen net profit. Corrections are recorded in an open month instead.
     */
    protected static function booted(): void
    {
        // Every ordinary query sees ACTIVE payments only, so a voided payment drops out of collections, invoice balances, project receipts, cash and
        // dashboards exactly once, with no per-query bookkeeping. History screens opt back in with Payment::withVoided().
        static::addGlobalScope(self::ACTIVE_SCOPE, fn (Builder $query) => $query->whereNull($query->getModel()->qualifyColumn('voided_at')));

        // A payment is financial evidence: it is voided through an approved PaymentVoidRequest and never deleted.
        static::deleting(function (): void {
            throw ValidationException::withMessages(['payment' => 'A payment is never deleted. Request a void with a reason and evidence; a different Board member approves it.']);
        });

        $guard = function (self $payment): void {
            $currency = Project::whereKey($payment->project_id)->value('currency');
            if ($currency !== config('finance.currency')) {
                return;
            }
            $dates = array_filter([$payment->getOriginal('paid_on'), $payment->paid_on]);
            foreach ($dates as $date) {
                if (AccountingPeriod::where('month', substr(Carbon::parse($date)->toDateString(), 0, 7))->exists()) {
                    throw ValidationException::withMessages(['paid_on' => 'That month is closed in the finance ledger. Record a correction in an open month instead.']);
                }
            }
        };
        static::saving($guard);
    }

    /** Every payment including voided ones: for history, audit and the void register only; never for a total. */
    public static function withVoided(): Builder
    {
        return static::withoutGlobalScope(self::ACTIVE_SCOPE);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
