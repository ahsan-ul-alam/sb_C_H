<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'verified_at' => 'datetime'];
    }
}
