@php
    $u = auth()->user();
    $finance = $u->can('finance.view');
    $groups = [
        'Overview' => [
            ['Dashboard', 'dashboard', 'clienthub', 'dashboard', ['dashboard']],
            ['Finance Dashboard', 'finance.dashboard', 'finance.view', 'chart', ['finance.dashboard']],
            ['My Account', 'finance.me', 'own.view', 'users', ['finance.me']],
        ],
        'Client Management' => [
            ['Clients', 'clients.index', 'clienthub', 'users', ['clients.*']],
            ['Projects', 'projects.index', 'clienthub', 'folder', ['projects.*', 'segments.*']],
            ['Invoices', 'invoices.index', 'clienthub', 'invoice', ['invoices.*']],
            ['Payments', 'payments.index', 'clienthub', 'wallet', ['payments.*']],
        ],
        'Finance' => [
            ['Investments', 'finance.capital', 'finance.view', 'cash', ['finance.capital*']],
            ['Expenses', 'finance.expenses', 'finance.view', 'wallet', ['finance.expenses*']],
            ['Periods & Profit', 'finance.periods', 'finance.view', 'invoice', ['finance.periods*', 'finance.allocations*']],
            ['Advances & Refunds', 'finance.revenue', 'finance.view', 'wallet', ['finance.revenue*']],
            ['Opening Balances', 'finance.opening', 'finance.view', 'layers', ['finance.opening*']],
        ],
        'Governance' => [
            ['Partners', 'finance.partners', 'finance.view', 'users', ['finance.partners*']],
            ['Policies', 'finance.policies', 'finance.view', 'layers', ['finance.policies*']],
            ['Board Resolutions', 'finance.resolutions', null, 'check-circle', ['finance.resolutions*']],
            ['Partner Payouts', 'finance.payouts', null, 'cash', ['finance.payouts*']],
            ['Partner Exits', 'finance.exits', 'finance.view', 'logout', ['finance.exits*']],
        ],
        'Reports' => [
            ['Financial Reports', 'finance.reports', 'finance.view', 'chart', ['finance.reports*']],
            ['Journal', 'finance.journal', 'finance.view', 'folder', ['finance.journal*']],
            ['Audit Log', 'finance.audit', 'finance.view', 'eye', ['finance.audit*']],
        ],
        'Administration' => [
            ['Users & Roles', 'users.index', 'users.manage', 'users', ['users.*']],
            ['Business Settings', 'settings.edit', 'clienthub', 'settings', ['settings.*']],
        ],
    ];
    $shared = $u->can('finance.view') || $u->can('own.view') || $u->can('resolution.create');
@endphp
@foreach ($groups as $label => $items)
    @php
        $visible = collect($items)->filter(fn ($i) => $i[2] === null ? $shared : $u->can($i[2]));
        // Finance Dashboard lives with Overview only for users who can see it; Dashboard link is ClientHub home.
    @endphp
    @if ($visible->isNotEmpty())
        <details class="nav-group" data-group="{{ $label }}" open>
            <summary>{{ $label }}</summary>
            @foreach ($visible as [$text, $route, $perm, $icon, $patterns])
                @php($active = request()->routeIs(...$patterns))
                <a href="{{ route($route) }}" @class(['active' => $active]) @if ($active) aria-current="page" @endif>
                    <x-icon :name="$icon" /> <span>{{ $text }}</span>
                </a>
            @endforeach
        </details>
    @endif
@endforeach
