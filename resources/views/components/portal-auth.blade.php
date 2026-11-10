@props(['title' => null])
@php
    $brandName = $brand->name ?: config('app.name');
    $logo = $brand->logoImage(168, 36);
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
<div class="pt-auth">
    <section class="pt-auth-brand" aria-label="About the portal">
        <a class="pt-logo" href="{{ route('portal.login') }}">@if ($logo)<img src="{{ $logo['src'] }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $brandName }}" style="filter:brightness(0) invert(1)">@else<span class="pt-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($brandName, 0, 1)) }}</span>{{ $brandName }}@endif</a>
        <div>
            <h2>Your business, clearly in view.</h2>
            <p>One secure place to follow your products, orders, invoices and profit with {{ $brandName }}.</p>
            <ul class="pt-points">
                <li><x-icon name="package" /> See what has arrived, what is available and what has been delivered.</li>
                <li><x-icon name="truck" /> Follow every order from dispatch to delivery.</li>
                <li><x-icon name="trending-up" /> Understand your investment, sales and profit.</li>
                <li><x-icon name="shield" /> Read-only and protected by a code sent to your email.</li>
            </ul>
        </div>
        <div class="pt-auth-foot">&copy; {{ date('Y') }} {{ $brandName }}</div>
    </section>
    <section class="pt-auth-form">
        <div class="pt-auth-box">
            <a class="pt-logo pt-auth-mobile-logo" href="{{ route('portal.login') }}" style="padding-left:0">@if ($logo)<img src="{{ $logo['src'] }}" alt="{{ $brandName }}">@else<span class="pt-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($brandName, 0, 1)) }}</span>{{ $brandName }}@endif</a>
            @if (session('error'))<div class="pt-flash err" role="alert"><x-icon name="alert" /> {{ session('error') }}</div>@endif
            @if (session('status'))<div class="pt-flash ok" role="status"><x-icon name="check-circle" /> {{ session('status') }}</div>@endif
            {{ $slot }}
        </div>
    </section>
</div>
<script src="{{ asset('js/portal.js') }}?v={{ filemtime(public_path('js/portal.js')) }}" defer></script>
</body>
</html>
