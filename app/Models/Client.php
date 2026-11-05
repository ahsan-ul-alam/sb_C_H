<?php

namespace App\Models;

use App\Enums\IdentificationType;
use App\Services\SbNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['name', 'address', 'country', 'mobile', 'mobile_alt', 'email', 'id_type', 'id_number', 'notes', 'commission_bps'])]
class Client extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'id_type' => IdentificationType::class,
            'archived_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * The SB number is assigned once, from a counter that only goes up, and can never be changed afterwards. A changed email is
     * no longer a verified one.
     */
    protected static function booted(): void
    {
        static::creating(function (self $client): void {
            $client->sb_number ??= SbNumber::allocate();
        });
        static::updating(function (self $client): void {
            if ($client->isDirty('sb_number') && $client->getOriginal('sb_number') !== null) {
                throw new LogicException('The SB number of a client cannot be changed.');
            }
            if ($client->isDirty('email') && ! $client->isDirty('email_verified_at')) {
                $client->email_verified_at = null;
                $client->email_verified_by = null;
            }
        });
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email !== null && $this->email !== '' && $this->email_verified_at !== null;
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Search by name, phone numbers, email or identification number.
     *
     * @param  Builder<Client>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)
                ->orWhere('sb_number', 'like', $like)
                ->orWhere('mobile', 'like', $like)
                ->orWhere('mobile_alt', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('id_number', 'like', $like);
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * A client with any project is kept for history and can only be archived.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->projects()->exists();
    }
}
