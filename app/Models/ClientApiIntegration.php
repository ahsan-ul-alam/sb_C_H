<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An external server allowed to ask for client information. It holds only a hash of its secret and may reach only the clients granted to it.
 */
class ClientApiIntegration extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'revoked_at' => 'datetime', 'rotated_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    /** @return BelongsToMany<Client, $this> */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_api_grants');
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->revoked_at === null;
    }

    public function mayAccess(Client $client): bool
    {
        return $this->clients()->whereKey($client->id)->exists();
    }
}
