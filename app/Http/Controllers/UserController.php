<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Models\User;
use App\Services\Audit;
use App\Services\Notify;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::orderBy('name')->get(), 'partners' => Partner::orderBy('sort')->get()]);
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
