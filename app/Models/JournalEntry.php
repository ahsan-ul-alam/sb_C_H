<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Posted journal rows are immutable. Corrections are made with a reversing entry.
 */
class JournalEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted journal entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted journal entries cannot be deleted.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
