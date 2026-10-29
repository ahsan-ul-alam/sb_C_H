@use('App\Support\Permissions')
<x-layout title="Users & Roles">
    <x-page-header title="Users & Roles">
        <x-slot:meta>A person can hold several roles; permissions combine. Super Admin has full operational access, but Board approvals still need the Board Member role and nobody can approve their own work.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <div class="panel-head"><h2>Team members</h2><span class="sub">{{ $users->count() }} {{ Str::plural('user', $users->count()) }}</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th scope="col">User</th><th scope="col">Roles</th><th scope="col">Linked partner</th><th scope="col"><span class="sr-only">Save</span></th></tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr>
                    <td class="primary-cell"><form id="uf{{ $u->id }}" method="POST" action="{{ route('users.update', $u) }}">@csrf @method('PUT')</form>{{ $u->name }}<span class="secondary">{{ $u->email }}</span></td>
                    <td>
                        <fieldset class="role-set" style="border:0; margin:0; padding:0; min-width:0">
                            <legend class="sr-only">Roles for {{ $u->name }}</legend>
                            <div class="role-grid compact">
                                @foreach (Permissions::ROLES as $key => $label)
                                    <label class="role-option"><input type="checkbox" name="roles[]" value="{{ $key }}" form="uf{{ $u->id }}" @checked(in_array($key, $u->roleKeys()))> <span>{{ $label }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>
                    </td>
                    <td>
                        <label class="sr-only" for="partner-{{ $u->id }}">Linked partner for {{ $u->name }}</label>
                        <select id="partner-{{ $u->id }}" name="partner_id" form="uf{{ $u->id }}"><option value="">None</option>@foreach ($partners as $p)<option value="{{ $p->id }}" @selected($u->partner_id === $p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach</select>
                    </td>
                    <td class="num"><button class="btn btn-sm btn-primary" form="uf{{ $u->id }}">Save Changes<span class="sr-only"> for {{ $u->name }}</span></button></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>

    <section class="panel form-card wide">
        <div class="panel-head"><div><h2>Add user</h2><div class="sub">Create a sign-in for a team member and choose what they can do.</div></div></div>
        <form method="POST" action="{{ route('users.store') }}" novalidate>@csrf
            <div class="form-section">
                <h2>Account</h2>
                <p class="section-hint">The password is set by you and should be changed by the user after their first sign-in.</p>
                <div class="form-grid">
                    <div class="field"><label for="u-name">Full name</label><input id="u-name" name="name" value="{{ old('name') }}" required autocomplete="off"></div>
                    <div class="field"><label for="u-email">Email</label><input id="u-email" type="email" name="email" value="{{ old('email') }}" required autocomplete="off"></div>
                    <div class="field"><label for="u-password">Password</label><input id="u-password" type="password" name="password" required minlength="10" autocomplete="new-password" aria-describedby="u-password-hint"><span class="hint" id="u-password-hint">At least 10 characters.</span></div>
                    <div class="field">
                        <label for="u-partner">Linked partner <span class="opt">(required for the Partner role)</span></label>
                        <select id="u-partner" name="partner_id"><option value="">None</option>@foreach ($partners as $p)<option value="{{ $p->id }}" @selected((string) old('partner_id') === (string) $p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach</select>
                    </div>
                </div>
            </div>
            <div class="form-section">
                <h2>Roles</h2>
                <p class="section-hint">Select every role this person needs. Their permissions are combined.</p>
                <fieldset style="border:0; margin:0; padding:0; min-width:0">
                    <legend class="sr-only">Roles</legend>
                    <div class="role-grid">
                        @foreach (Permissions::ROLES as $key => $label)
                            <label class="role-option"><input type="checkbox" name="roles[]" value="{{ $key }}" @checked(in_array($key, old('roles', [])))> <span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Create User</button></div>
        </form>
    </section>
</x-layout>
