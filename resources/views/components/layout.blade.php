@props(['title' => null])
@php
    $logo = $brand->logoImage(168, 32);
    $brandName = $brand->name ?: config('app.name');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ $brandName }}</title>
    @include('partials.theme-head')
    <link rel="icon" href="{{ $brand->faviconUrl() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
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
        <nav class="nav" aria-label="Sections">
            @include('partials.nav')
        </nav>
        <div class="sidebar-foot">
            <div class="user" title="{{ auth()->user()?->email }}">{{ auth()->user()?->email }}</div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"><x-icon name="logout" /> Log out</button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button type="button" id="nav-toggle" aria-controls="sidebar" aria-expanded="false"><x-icon name="menu" /><span class="sr-only">Open navigation menu</span></button>
            <span class="brand-name">{{ $brandName }}</span>
            <span class="spacer"></span>
            <div class="notif">
                <button type="button" id="notif-bell" class="notif-bell" aria-haspopup="true" aria-expanded="false" aria-controls="notif-panel" aria-label="Notifications"
                        data-count-url="{{ route('notifications.count') }}" data-feed-url="{{ route('notifications.feed') }}"
                        data-read-url="{{ route('notifications.read', '__ID__') }}" data-read-all-url="{{ route('notifications.read-all') }}">
                    <x-icon name="bell" />
                    <span id="notif-badge" class="notif-badge" hidden>0</span>
                </button>
                <div id="notif-panel" class="notif-panel" role="region" aria-label="Notifications" hidden>
                    <div class="notif-head">
                        <h2>Notifications</h2>
                        <span class="notif-actions">
                            <button type="button" class="btn btn-sm btn-ghost" id="notif-read-all">Mark All as Read</button>
                            <button type="button" class="btn btn-sm btn-ghost" id="notif-refresh">Refresh</button>
                        </span>
                    </div>
                    <ul id="notif-list" class="notif-list" aria-live="polite"></ul>
                    <div class="notif-foot"><a href="{{ route('notifications.index') }}">View All Notifications</a></div>
                </div>
            </div>
            <div class="theme-switch" role="group" aria-label="Colour theme">
                <button type="button" data-theme-set="light" aria-pressed="false">Light</button>
                <button type="button" data-theme-set="dark" aria-pressed="false">Dark</button>
                <button type="button" data-theme-set="system" aria-pressed="false">System</button>
            </div>
        </header>

        <main class="content" id="main" tabindex="-1">
            <div aria-live="polite">
                @if (session('status'))
                    <div class="flash flash-success" role="status"><x-icon name="check-circle" /> <div>{{ session('status') }}</div></div>
                @endif
            </div>
            @if (session('error'))
                <div class="flash flash-error" role="alert"><x-icon name="alert" /> <div>{{ session('error') }}</div></div>
            @endif
            @if (session('warning'))
                <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div>{{ session('warning') }}</div></div>
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

<dialog class="confirm" id="confirm-dialog" aria-labelledby="confirm-title" aria-describedby="confirm-text">
    <div class="dlg-body">
        <h2 id="confirm-title">Please confirm</h2>
        <p id="confirm-text" class="muted"></p>
    </div>
    <div class="dlg-actions">
        <button type="button" class="btn" id="confirm-cancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirm-ok">Confirm</button>
    </div>
</dialog>

<div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="false"></div>

<script>
    window.__errors = @json($errors->messages());

    (function () {
        // ----- Theme: Light / Dark / System -----
        var root = document.documentElement;
        var media = window.matchMedia('(prefers-color-scheme: dark)');
        function apply(pref) {
            root.dataset.themePref = pref;
            root.dataset.theme = (pref === 'dark' || (pref === 'system' && media.matches)) ? 'dark' : 'light';
            document.querySelectorAll('[data-theme-set]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.themeSet === pref ? 'true' : 'false'); });
        }
        apply(root.dataset.themePref || 'system');
        document.querySelectorAll('[data-theme-set]').forEach(function (b) {
            b.addEventListener('click', function () {
                try { localStorage.setItem('sb-theme', b.dataset.themeSet); } catch (e) {}
                apply(b.dataset.themeSet);
            });
        });
        media.addEventListener('change', function () { if (root.dataset.themePref === 'system') apply('system'); });
    })();

    (function () {
        // ----- Mobile navigation drawer -----
        var toggle = document.getElementById('nav-toggle');
        function setOpen(open) {
            document.body.classList.toggle('nav-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        toggle.addEventListener('click', function () { setOpen(!document.body.classList.contains('nav-open')); });
        document.addEventListener('click', function (e) {
            if (document.body.classList.contains('nav-open') && !e.target.closest('#sidebar') && !e.target.closest('#nav-toggle')) setOpen(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('nav-open')) { setOpen(false); toggle.focus(); }
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
<script src="{{ asset('js/sellbees.js') }}?v={{ filemtime(public_path('js/sellbees.js')) }}" defer></script>
<div id="page-scripts">@stack('scripts')</div>
</body>
</html>
