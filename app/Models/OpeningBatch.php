<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['as_of' => 'date', 'approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningLine::class);
    }
}
