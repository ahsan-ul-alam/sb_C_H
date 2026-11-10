<x-portal-layout title="Admin test access" :bare="true">
    <div class="cp-card">
        <h1>Admin Test Client Portal</h1>
        @if (! $enabled)
            <p class="cp-note">Admin preview is not enabled in production.</p>
        @else
        <p class="muted">Staff only. Opens a read-only, short-lived view of one client. Every start and exit is audited.</p>
        <form method="POST" action="{{ route('portal.preview.start') }}">@csrf
            <div class="field"><label>Client SB ID</label><input name="sb_number" value="{{ old('sb_number') }}" required></div>
            <div class="field"><label>Reason (min. 10 characters)</label><input name="reason" value="{{ old('reason') }}" required></div>
            <div class="field"><label>{{ $staticCode ? 'Test confirmation code' : 'Your account password' }}</label><input name="confirmation" type="password" autocomplete="off" required></div>
            <button class="btn btn-primary" style="width:100%;margin-top:12px">Start test session</button>
        </form>
        @endif
        <p style="margin-top:14px"><a href="{{ route('dashboard') }}">Back to the ERP</a></p>
    </div>
</x-portal-layout>