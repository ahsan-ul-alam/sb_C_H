<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Services\Audit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * One row per real-world transaction. It always belongs to a project, may belong to an
 * invoice, and may be allocated to one or more segments. Totals sum this table once.
 */
#[Fillable(['amount', 'paid_on', 'method', 'reference', 'notes'])]
class Payment extends Model
{
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
            'method' => PaymentMethod::class,
        ];
    }

    /**
     * A payment whose month has been closed in the finance ledger is frozen: changing it would silently
     * disagree with the frozen net profit. Corrections are recorded in an open month instead.
     */
    protected static function booted(): void
    {
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
        static::deleting($guard);
        // A payment that already carries advance/refund records is financial evidence and cannot be voided.
        static::deleting(function (self $payment): void {
            if (RevenueAdjustment::where('payment_id', $payment->id)->exists()) {
                throw ValidationException::withMessages(['paid_on' => 'This payment has advance/refund records in the finance ledger and cannot be deleted. Record a refund instead.']);
            }
        });
        static::deleted(fn (self $payment) => Audit::log('payment.voided', 'payment '.$payment->id, 'amount '.$payment->amount.' paid '.$payment->paid_on->toDateString()));
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
