<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSettlement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'funds_before' => 'integer', 'commission_netted' => 'integer', 'storage_netted' => 'integer', 'paid' => 'integer'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
