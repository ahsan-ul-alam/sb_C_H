<x-portal-auth title="Enter your code">
    <h1>Enter your code</h1>
    <p>If the SB ID is right, a six-digit code is on its way. It works once and expires in 5 minutes.</p>
    <div class="pt-sent"><x-icon name="mail" /><span>Sent to <b>{{ $masked }}</b></span></div>
    <form method="POST" action="{{ route('portal.verify.submit') }}" data-loading novalidate>@csrf
        <div class="pt-field">
            <label for="code">Six-digit code</label>
            <input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" autocomplete="one-time-code" data-digits autofocus required placeholder="••••••" @class(['pt-input', 'pt-otp']) aria-describedby="code-help">
            <span class="muted" id="code-help" style="font-size:.8rem">Check your inbox and spam folder. The newest code is the only one that works.</span>
        </div>
        <button type="submit" class="pt-btn pt-btn-primary pt-btn-block" style="margin-top:1.1rem">Open my portal</button>
    </form>
    <form method="POST" action="{{ route('portal.resend') }}" data-loading style="margin-top:1rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">@csrf
        <button type="submit" class="pt-btn pt-btn-sm" data-cooldown="{{ (int) $cooldown }}" @if ($cooldown > 0) disabled aria-disabled="true" @endif>Send a new code<span data-count>@if ($cooldown > 0) in {{ $cooldown }}s @endif</span></button>
        <a href="{{ route('portal.login') }}" class="pt-link" style="font-size:.88rem">Use a different SB ID</a>
    </form>
    <div class="pt-assure"><x-icon name="shield" /><span>Never share this code. Sellbees staff will never ask you for it.</span></div>
</x-portal-auth>
