<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'role', 'partner_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string>|null */
    private ?array $roleCache = null;

    /**
     * Role keys held by this user (a person may hold several).
     *
     * @return list<string>
     */
    public function roleKeys(): array
    {
        if (! $this->exists) {
            return [];
        }

        return $this->roleCache ??= DB::table('role_user')->where('user_id', $this->id)->orderBy('role')->pluck('role')->all();
    }

    public function hasRole(string ...$roles): bool
    {
        return array_intersect($roles, $this->roleKeys()) !== [];
    }

    /**
     * Replace this user's roles. Unknown keys are ignored.
     *
     * @param  list<string>  $roles
     */
    public function setRoles(array $roles): void
    {
        $roles = array_values(array_unique(array_intersect($roles, array_keys(Permissions::ROLES))));
        DB::transaction(function () use ($roles) {
            DB::table('role_user')->where('user_id', $this->id)->delete();
            foreach ($roles as $role) {
                DB::table('role_user')->insert(['user_id' => $this->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->roleCache = null;
    }

    public function addRole(string $role): void
    {
        $this->setRoles([...$this->roleKeys(), $role]);
    }

    /** Effective permissions are the union of every role held. */
    public function hasPermission(string $p): bool
    {
        return Permissions::allowsAny($this->roleKeys(), $p);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
