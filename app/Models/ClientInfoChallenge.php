<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One emailed one-time code. Only a keyed hash of the code is stored; the code itself exists in memory long enough to be emailed.
 */
class ClientInfoChallenge extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'consumed_at' => 'datetime', 'expires_at' => 'datetime', 'attempts' => 'integer'];
    }
}
