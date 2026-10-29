<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_on' => 'date', 'exited_on' => 'date'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
