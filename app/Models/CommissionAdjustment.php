<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['commission_before' => 'integer', 'commission_after' => 'integer'];
    }
}
