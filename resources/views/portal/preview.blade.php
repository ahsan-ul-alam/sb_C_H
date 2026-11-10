<x-portal-auth title="Admin test access">
    <h1>Client Portal preview</h1>
    <p>Staff only. Opens a read-only, short-lived view of one client. Every start and exit is audited.</p>
    @if (! $enabled)
        <div class="pt-flash info"><x-icon name="alert" /> Admin preview is not enabled in production.</div>
    @else
    <form method="POST" action="{{ route('portal.preview.start') }}" data-loading novalidate>@csrf
        <div class="pt-field" style="margin-bottom:.9rem"><label for="pv-sb">Client SB ID</label><input id="pv-sb" name="sb_number" value="{{ old('sb_number') }}" required class="pt-input pt-sb-input" autocomplete="off"></div>
        <div class="pt-field" style="margin-bottom:.9rem"><label for="pv-reason">Reason (at least 10 characters)</label><input id="pv-reason" name="reason" value="{{ old('reason') }}" required class="pt-input"></div>
        <div class="pt-field"><label for="pv-conf">{{ $staticCode ? 'Test confirmation code' : 'Your account password' }}</label><input id="pv-conf" name="confirmation" type="password" autocomplete="off" required class="pt-input"></div>
        <button type="submit" class="pt-btn pt-btn-primary pt-btn-block" style="margin-top:1.1rem">Start test session</button>
    </form>
    @endif
    <p style="margin-top:1.25rem"><a class="pt-link" href="{{ route('dashboard') }}">Back to the ERP</a></p>
</x-portal-auth>
