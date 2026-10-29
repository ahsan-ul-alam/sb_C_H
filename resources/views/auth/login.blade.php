@php
    $brand = \App\Models\BusinessSetting::current();
    $brandName = $brand->name ?: config('app.name');
    $logo = $brand->logoImage(200, 48);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · {{ $brandName }}</title>
    @include('partials.theme-head')
    <link rel="icon" href="{{ $brand->faviconUrl() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
    <div class="login-wrap">
        <div class="login-card">
            <div class="brand-block">
                @if ($logo)
                    <img src="{{ $logo['src'] }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $brandName }}">
                @else
                    <span class="brand-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($brandName, 0, 1)) }}</span>
                    <span class="strong" style="font-size:1.15rem">{{ $brandName }}</span>
                @endif
            </div>
            <form method="POST" action="{{ route('login') }}" class="panel">
                @csrf
                <div class="form-section" style="display:grid; gap:.9rem">
                    <h1 style="font-size:1.15rem">Sign in to continue</h1>
                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" @class(['is-invalid' => $errors->has('email')])>
                        @error('email') <span class="error" role="alert">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                    </div>
                    <label class="checkbox"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-block">Log in</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
