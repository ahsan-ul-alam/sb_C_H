<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBatch::class, 'opening_batch_id');
    }
}
