@props(['title' => null, 'bare' => false])
@php($brandName = $brand->name ?: config('app.name'))
@php($logo = $brand->logoImage(168, 32))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' · ' : '' }}{{ $brandName }} Client Portal</title>
    @include('partials.theme-head')
    <link rel="icon" href="{{ $brand->faviconUrl() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <style>
        .cp-wrap{max-width:1100px;margin:0 auto;padding:0 16px 40px}
        .cp-top{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 0}
        .cp-top .cp-brand{display:flex;align-items:center;gap:8px;font-weight:700;color:inherit;text-decoration:none}
        .cp-nav{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 18px;padding:6px;background:var(--surface);border:1px solid var(--border);border-radius:12px}
        .cp-nav a{padding:8px 12px;border-radius:8px;text-decoration:none;color:inherit;font-size:.92rem}
        .cp-nav a[aria-current="page"]{background:var(--bg);font-weight:600}
        .cp-banner{background:#b42318;color:#fff;padding:10px 16px;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;font-weight:600}
        .cp-banner button{background:#fff;color:#b42318;border:0;border-radius:6px;padding:4px 10px;font-weight:600;cursor:pointer}
        .cp-card{max-width:440px;margin:8vh auto 0;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px}
        .cp-card h1{margin:0 0 6px;font-size:1.4rem}
        .cp-card input[name=sb_number],.cp-card input[name=code]{width:100%;font-size:1.2rem;letter-spacing:.12em}
        .cp-note{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:.88rem}
        .cp-flash{padding:10px 12px;border-radius:10px;margin-bottom:14px}
        .cp-flash.err{background:#fde8e7;color:#7a271a}.cp-flash.ok{background:#e6f4ea;color:#14532d}
        .stat-value.sm{font-size:1.05rem}
    </style>
</head>
<body>
@if (! empty($portalPreview))
    <div class="cp-banner" role="alert">ADMIN TEST SESSION — read-only view of {{ $portalClient->name }} ({{ $portalClient->sb_number }}). Nothing here can be changed.
        <form method="POST" action="{{ route('portal.preview.exit') }}">@csrf<button type="submit">Exit Test Session</button></form></div>
@endif
<div class="cp-wrap">
    <header class="cp-top">
        <a class="cp-brand" href="{{ route('portal.login') }}">@if ($logo)<img src="{{ $logo['src'] }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $brandName }}">@else{{ $brandName }}@endif<span class="muted" style="font-weight:400">Client Portal</span></a>
        <span style="flex:1"></span>
        @isset($portalClient)<span class="muted">{{ $portalClient->name }} · {{ $portalClient->sb_number }}</span>@endisset
    </header>
    @if (! $bare)
        @php($nav = ['portal.dashboard' => 'Dashboard', 'portal.profile' => 'My Profile', 'portal.projects' => 'My Projects', 'portal.inventory' => 'Products & Inventory', 'portal.orders' => 'Orders & Delivery', 'portal.invoices' => 'Invoices & Payments', 'portal.sales' => 'Sales & Profit'])
        <nav class="cp-nav" aria-label="Client portal">
            @foreach ($nav as $route => $label)<a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>@endforeach
            @if (empty($portalPreview))<form method="POST" action="{{ route('portal.logout') }}" style="margin-left:auto">@csrf<button class="btn btn-sm" type="submit">Logout</button></form>@endif
        </nav>
    @endif
    @if (session('error'))<div class="cp-flash err" role="alert">{{ session('error') }}</div>@endif
    @if (session('status'))<div class="cp-flash ok" role="status">{{ session('status') }}</div>@endif
    <main id="main">{{ $slot }}</main>
</div>
</body>
</html>