<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'status', 'currency', 'started_on'])]
class Project extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'started_on' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<Segment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Projects with invoices or payments are kept for history and can only be archived.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->invoices()->exists() && ! $this->payments()->exists();
    }

    /**
     * Project-level totals in minor units. Charges come from segments only;
     * received counts every payment recorded on the project exactly once.
     *
     * @return array{charges: int, received: int, outstanding: int, invoiced: int, invoice_balance: int}
     */
    public function totals(): array
    {
        $charges = (int) $this->segments()->sum('amount');
        $received = (int) $this->payments()->sum('amount');
        $invoiced = (int) $this->invoices()->sum('total');
        $invoicePaid = (int) $this->invoices()->sum('amount_paid');

        return [
            'charges' => $charges,
            'received' => $received,
            'outstanding' => $charges - $received,
            'invoiced' => $invoiced,
            'invoice_balance' => $invoiced - $invoicePaid,
        ];
    }
}
