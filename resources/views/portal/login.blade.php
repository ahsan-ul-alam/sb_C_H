<x-portal-layout title="Sign in" :bare="true">
    <div class="cp-card">
        <h1>Client Portal</h1>
        <p class="muted">Enter your SB ID. We will email a six-digit code to the address we hold for you.</p>
        <form method="POST" action="{{ route('portal.code') }}">@csrf
            <div class="field"><label for="sb">Your SB ID</label><input id="sb" name="sb_number" value="{{ old('sb_number') }}" placeholder="SB-000123" autocomplete="off" autofocus required>
                @error('sb_number')<span class="error">{{ $message }}</span>@enderror</div>
            <button class="btn btn-primary" style="width:100%;margin-top:12px">Send my code</button>
        </form>
        <p class="cp-note" style="margin-top:16px">For your safety the screen looks the same whether or not an SB ID is found. Nobody can see your information without the code.</p>
    </div>
</x-portal-layout>