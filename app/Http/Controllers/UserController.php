<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit;
use App\Services\Notify;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::orderBy('name')->get(), 'partners' => Partner::orderBy('sort')->get(), 'clients' => Client::orderBy('name')->get(['id', 'name']),
            'projects' => Project::with('client:id,name')->whereNull('archived_at')->orderBy('name')->get(),
            'assigned' => DB::table('project_user')->get()->groupBy('user_id')->map(fn ($rows) => $rows->pluck('project_id')->map(fn ($id) => (int) $id)->all())->all()]);
    }

    /**
     * Which client a user is bound to and which projects they are assigned to. Nobody changes their own access, and management roles are not narrowed
     * (they always see every project), so access limits apply only to the other roles.
     */
    public function access(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'exists:clients,id'], 'project_ids' => ['nullable', 'array'], 'project_ids.*' => ['exists:projects,id']]);
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot change your own project access.');
        }
        if ($user->hasRole(...User::CROSS_PROJECT_ROLES)) {
            return back()->with('error', 'Management, Finance and Auditor roles always see every project; remove the role first if this person should be limited.');
        }
        $projectIds = array_values(array_unique(array_map('intval', $data['project_ids'] ?? [])));
        if (! empty($data['client_id']) && Project::whereIn('id', $projectIds)->where('client_id', '!=', $data['client_id'])->exists()) {
            return back()->with('error', 'A user bound to a client can only be assigned that client\'s projects.');
        }
        DB::transaction(function () use ($user, $data, $projectIds, $request) {
            $user->forceFill(['client_id' => $data['client_id'] ?? null])->save();
            DB::table('project_user')->where('user_id', $user->id)->delete();
            foreach ($projectIds as $id) {
                DB::table('project_user')->insert(['user_id' => $user->id, 'project_id' => $id, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::log('user.access_updated', $user->email, 'client '.($data['client_id'] ?? '-').' projects '.implode(',', $projectIds));
        });

        return back()->with('status', 'Project access saved.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(array_keys(Permissions::ROLES))],
            'partner_id' => ['nullable', 'exists:partners,id', 'unique:users,partner_id'],
        ]);
        if (in_array('partner', $data['roles'], true) && empty($data['partner_id'])) {
            return back()->with('error', 'A user with the Partner role must be linked to a partner record.')->withInput();
        }

        $user = User::create(collect($data)->except('roles')->all());
        $user->setRoles($data['roles']);
        Audit::log('user.created', $user->email, implode(',', $user->roleKeys()).($user->partner_id ? ' partner '.$user->partner_id : ''));

        return back()->with('status', 'User created.');
    }

    /**
     * Roles (several allowed) and partner mapping. One user per partner identity; nobody can change their own
     * roles or partner link (no self-promotion), and the last Super Admin cannot be demoted.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*' => [Rule::in(array_keys(Permissions::ROLES))],
            'partner_id' => ['nullable', 'exists:partners,id', Rule::unique('users', 'partner_id')->ignore($user->id)],
        ]);
        $roles = array_values(array_unique($data['roles'] ?? []));

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot change your own roles or partner link.');
        }
        if (in_array('partner', $roles, true) && empty($data['partner_id'])) {
            return back()->with('error', 'A user with the Partner role must be linked to a partner record.');
        }
        if ($user->hasRole('super_admin') && ! in_array('super_admin', $roles, true)
            && User::all()->filter(fn ($u) => $u->hasRole('super_admin'))->count() <= 1) {
            return back()->with('error', 'The last Super Admin cannot be removed.');
        }

        $before = $user->roleKeys();
        $user->update(['partner_id' => $data['partner_id'] ?? null]);
        $user->setRoles($roles);
        if ($before !== $user->roleKeys()) {
            Notify::send([$user], 'Your access was updated', 'An administrator changed your roles.', 'dashboard', 'system', 'user:'.$user->id.':roles:'.md5(implode(',', $user->roleKeys())));
        }
        Audit::log('user.updated', $user->email, implode(',', $roles).($user->partner_id ? ' partner '.$user->partner_id : ''));

        return back()->with('status', 'User updated.');
    }
}
