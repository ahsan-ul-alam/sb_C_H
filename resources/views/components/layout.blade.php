@props(['title' => null])
@php
    $logo = $brand->logoImage(168, 32);
    $brandName = $brand->name ?: config('app.name');
    $nav = [
        ['Dashboard', 'dashboard', 'dashboard', ['dashboard']],
        ['Clients', 'clients.index', 'users', ['clients.*']],
        ['Projects', 'projects.index', 'folder', ['projects.*', 'segments.*']],
        ['Invoices', 'invoices.index', 'invoice', ['invoices.*']],
        ['Payments', 'payments.index', 'wallet', ['payments.*']],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ $brandName }}</title>
    <link rel="icon" href="{{ $brand->faviconUrl() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<div class="app">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <a href="{{ route('dashboard') }}" class="brand">
            @if ($logo)
                <span class="brand-logo"><img src="{{ $logo['src'] }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $brandName }}"></span>
            @else
                <span class="brand-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($brandName, 0, 1)) }}</span>
                <span class="brand-name">{{ $brandName }}</span>
            @endif
        </a>
        <nav class="nav">
            @foreach ($nav as [$label, $route, $icon, $patterns])
                <a href="{{ route($route) }}" @class(['active' => request()->routeIs(...$patterns)]) @if (request()->routeIs(...$patterns)) aria-current="page" @endif>
                    <x-icon :name="$icon" /> {{ $label }}
                </a>
            @endforeach
            <div class="nav-label">Admin</div>
            <a href="{{ route('settings.edit') }}" @class(['active' => request()->routeIs('settings.*')])>
                <x-icon name="settings" /> Business Settings
            </a>
        </nav>
        <div class="sidebar-foot">
            <div class="user">{{ auth()->user()?->email }}</div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"><x-icon name="logout" /> Log out</button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button type="button" id="nav-toggle" aria-controls="sidebar" aria-expanded="false"><x-icon name="menu" /><span class="sr-only">Menu</span></button>
            <span class="brand-name">{{ $brandName }}</span>
        </header>

        <main class="content">
            @if (session('status'))
                <div class="flash flash-success" role="status"><x-icon name="check-circle" /> <div>{{ session('status') }}</div></div>
            @endif
            @if (session('error'))
                <div class="flash flash-error" role="alert"><x-icon name="alert" /> <div>{{ session('error') }}</div></div>
            @endif
            @if ($errors->any())
                <div class="flash flash-error" role="alert">
                    <x-icon name="alert" />
                    <div>
                        Please correct the highlighted fields.
                        <ul>
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>

<script>
    // Confirm destructive actions and block repeated submits on every form.
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }
        if (form.dataset.submitted) {
            event.preventDefault();
            return;
        }
        if (form.method.toLowerCase() === 'get') {
            return;
        }
        form.dataset.submitted = '1';
        setTimeout(function () {
            form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (button) { button.disabled = true; });
        }, 0);
    });
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-submitted]').forEach(function (form) {
            delete form.dataset.submitted;
            form.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
        });
    });

    // Mobile navigation.
    (function () {
        const toggle = document.getElementById('nav-toggle');
        toggle.addEventListener('click', function () {
            const open = document.body.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (document.body.classList.contains('nav-open') && !event.target.closest('#sidebar') && !event.target.closest('#nav-toggle')) {
                document.body.classList.remove('nav-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    })();

    // Searchable selects: a filter box above long <select data-searchable> lists.
    document.querySelectorAll('select[data-searchable]').forEach(function (select) {
        const input = document.createElement('input');
        input.type = 'search';
        input.className = 'select-search';
        input.placeholder = select.dataset.searchable || 'Type to filter…';
        input.setAttribute('aria-label', input.placeholder);
        select.parentNode.insertBefore(input, select);
        input.addEventListener('input', function () {
            const term = input.value.trim().toLowerCase();
            let firstMatch = null;
            Array.from(select.options).forEach(function (option) {
                const match = !option.value || option.text.toLowerCase().includes(term);
                option.hidden = !match;
                if (match && option.value && !firstMatch) firstMatch = option;
            });
            if (firstMatch && (select.selectedOptions[0]?.hidden || !select.value)) {
                select.value = firstMatch.value;
                select.dispatchEvent(new Event('change'));
            }
        });
    });
</script>
@stack('scripts')
</body>
</html>
