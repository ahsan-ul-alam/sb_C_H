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

    /** Stable human-readable transaction reference, derived from the immutable id. */
    public function reference(): string
    {
        return 'JE-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** Posting status is derived, never stored: an entry is reversed once another entry points at it. */
    public function isReversed(): bool
    {
        return static::where('reversal_of', $this->id)->exists();
    }
}
