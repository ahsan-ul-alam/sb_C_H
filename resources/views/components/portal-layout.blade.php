@props(['title' => null, 'subtitle' => null, 'crumbs' => []])
@php
    $brandName = $brand->name ?: config('app.name');
    $logo = $brand->logoImage(168, 36);
    $name = trim((string) ($portalClient->name ?? ''));
    $initials = collect(preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: 'C';
    $groups = [
        'Business' => [['portal.dashboard', 'Overview', 'dashboard'], ['portal.projects', 'My Projects', 'folder'], ['portal.inventory', 'Products & Stock', 'package'], ['portal.orders', 'Orders & Delivery', 'truck']],
        'Finance' => [['portal.invoices', 'Invoices & Payments', 'invoice'], ['portal.sales', 'Sales & Profit', 'trending-up']],
        'Account' => [['portal.profile', 'My Profile', 'user']],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ $brandName }} Client Portal</title>
    @include('partials.theme-head')
    <link rel="icon" href="{{ $brand->faviconUrl() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
</head>
<body class="pt">
<div class="pt-loadbar" id="pt-loadbar" aria-hidden="true"></div>
@if (! empty($portalPreview))
    <div class="pt-preview" role="alert"><strong>Admin Test Session</strong>
        <span>Read-only view of {{ $portalClient->name }} ({{ $portalClient->sb_number }}). Nothing can be changed.</span>
        <form method="POST" action="{{ route('portal.preview.exit') }}">@csrf<button type="submit">Exit Test Session</button></form></div>
@endif
<div class="pt-overlay" id="pt-overlay"></div>
<div class="pt-shell">
    <aside class="pt-side" id="pt-side" aria-label="Client portal navigation">
        <a class="pt-logo" href="{{ route('portal.dashboard') }}" aria-label="{{ $brandName }} home">
            @if ($logo)<img src="{{ $logo['src'] }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $brandName }}">@else<span class="pt-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($brandName, 0, 1)) }}</span>{{ $brandName }}@endif
        </a>
        <div class="pt-client"><span class="pt-avatar" aria-hidden="true">{{ $initials }}</span><div><strong title="{{ $name }}">{{ $name }}</strong><span>{{ $portalClient->sb_number }}</span></div></div>
        <nav class="pt-nav" aria-label="Sections">
            @foreach ($groups as $label => $items)
                <div class="pt-nav-label">{{ $label }}</div>
                @foreach ($items as [$route, $text, $icon])
                    <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif><x-icon :name="$icon" /> {{ $text }}</a>
                @endforeach
            @endforeach
        </nav>
        <div class="pt-side-foot">
            @if (empty($portalPreview))
                <form method="POST" action="{{ route('portal.logout') }}">@csrf<button type="submit"><x-icon name="logout" /> Log out</button></form>
            @endif
            <div class="pt-secure"><x-icon name="shield" /> Secure, read-only view of your records</div>
        </div>
    </aside>

    <div class="pt-main">
        <header class="pt-topbar">
            <button type="button" class="pt-menu-btn" id="pt-menu" aria-controls="pt-side" aria-expanded="false"><x-icon name="menu" /><span class="sr-only">Open menu</span></button>
            <span class="pt-logo" style="padding:0">@if ($logo)<img src="{{ $logo['src'] }}" alt="{{ $brandName }}" style="max-height:28px">@else{{ $brandName }}@endif</span>
        </header>
        <main class="pt-content" id="main">
            <div class="pt-head">
                <div>
                    @if ($crumbs)<nav class="pt-crumbs" aria-label="Breadcrumb">@foreach ($crumbs as [$crumbLabel, $crumbUrl])@if ($crumbUrl)<a href="{{ $crumbUrl }}">{{ $crumbLabel }}</a><x-icon name="chevron-right" />@else<span aria-current="page">{{ $crumbLabel }}</span>@endif @endforeach</nav>@endif
                    <h1>{{ $title }}</h1>
                    @if ($subtitle)<p>{{ $subtitle }}</p>@endif
                </div>
                @isset($actions)<div>{{ $actions }}</div>@endisset
            </div>
            @if (session('error'))<div class="pt-flash err" role="alert"><x-icon name="alert" /> {{ session('error') }}</div>@endif
            @if (session('status'))<div class="pt-flash ok" role="status"><x-icon name="check-circle" /> {{ session('status') }}</div>@endif
            {{ $slot }}
        </main>
    </div>
</div>
<script src="{{ asset('js/portal.js') }}?v={{ filemtime(public_path('js/portal.js')) }}" defer></script>
</body>
</html>
