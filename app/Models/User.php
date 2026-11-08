<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /** Roles that see every project (management, finance and audit); project assignment and client binding never narrow them. */
    public const CROSS_PROJECT_ROLES = ['super_admin', 'board_member', 'finance', 'auditor'];

    /** @return BelongsToMany<Project, $this> */
    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_user')->withTimestamps();
    }

    /**
     * The project ids this user may see, or null for all of them. Management roles, and staff an administrator has explicitly granted `all_projects`, see everything.
     * Otherwise a user bound to a client sees only that client's projects (narrowed to the assigned ones if any are assigned), and everyone else sees only the projects
     * they are assigned to: with no assignment they see none.
     *
     * @return list<int>|null
     */
    public function accessibleProjectIds(): ?array
    {
        if ($this->hasRole(...self::CROSS_PROJECT_ROLES) || (bool) $this->all_projects) {
            return null;
        }
        $assigned = DB::table('project_user')->where('user_id', $this->id)->pluck('project_id')->map(fn ($id) => (int) $id)->all();
        if ($this->client_id !== null) {
            return Project::where('client_id', $this->client_id)->when($assigned !== [], fn ($q) => $q->whereIn('id', $assigned))->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $assigned;
    }

    public function seesAllProjects(): bool
    {
        return $this->accessibleProjectIds() === null;
    }

    public function canAccessProject(Project|int|null $project): bool
    {
        $ids = $this->accessibleProjectIds();

        return $ids === null || ($project !== null && in_array($project instanceof Project ? $project->id : $project, $ids, true));
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
