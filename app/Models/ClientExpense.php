<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cost borne by a client, recorded for client profit reporting only. It is never posted to the Sellbees ledger.
 */
class ClientExpense extends Model
{
    public const CATEGORIES = [
        'delivery' => 'Delivery to end customers', 'packaging' => 'Packaging', 'advertising' => 'Advertising', 'handling' => 'Handling',
        'platform_fees' => 'Platform fees', 'client_paid_services' => 'Client-paid services', 'other_operating' => 'Other verified operating expense',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'spent_on' => 'date', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<ClientExpenseAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(ClientExpenseAllocation::class);
    }

    /** @return HasMany<Model, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ClientExpenseEvent::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
