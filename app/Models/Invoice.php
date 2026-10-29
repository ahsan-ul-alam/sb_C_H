<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'issued_on', 'due_on', 'currency', 'purpose', 'notes'])]
class Invoice extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'status' => InvoiceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Payment method label from saved payments: one method, "Multiple methods", or "Not recorded".
     */
    public function paymentMethodLabel(): string
    {
        $methods = $this->payments->pluck('method')->unique(fn ($method) => $method->value);

        return match ($methods->count()) {
            0 => 'Not recorded',
            1 => $methods->first()->label(),
            default => 'Multiple methods',
        };
    }

    public function purposeLabel(): string
    {
        return $this->purpose ?: $this->project->name;
    }

    /**
     * Unpaid or partially paid invoices.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', InvoiceStatus::Paid);
    }

    /**
     * Open invoices whose due date is before today.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereNotNull('due_on')->whereDate('due_on', '<', today());
    }

    public function isOverdue(): bool
    {
        return $this->status !== InvoiceStatus::Paid && $this->due_on !== null && $this->due_on->lt(today());
    }

    public function balance(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    /**
     * Recalculate paid amount and status from saved payments. Called after any payment change.
     */
    public function refreshPaymentStatus(): void
    {
        $this->amount_paid = (int) $this->payments()->sum('amount');
        $this->status = match (true) {
            $this->total > 0 && $this->amount_paid >= $this->total => InvoiceStatus::Paid,
            $this->amount_paid > 0 => InvoiceStatus::Partial,
            default => InvoiceStatus::Unpaid,
        };
        $this->save();
    }

    /**
     * Next sequential number for the year, e.g. INV-2026-0007.
     */
    public static function nextNumber(): string
    {
        $prefix = 'INV-'.now()->format('Y').'-';
        $last = static::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
