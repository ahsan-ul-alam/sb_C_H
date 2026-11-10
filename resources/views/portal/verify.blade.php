<x-portal-layout title="Enter your code" :bare="true">
    <div class="cp-card">
        <h1>Check your email</h1>
        <p class="muted">If the SB ID is right, a six-digit code was sent to <strong>{{ $masked }}</strong>. It works once and expires in 5 minutes.</p>
        <form method="POST" action="{{ route('portal.verify.submit') }}">@csrf
            <div class="field"><label for="code">Six-digit code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus required></div>
            <button class="btn btn-primary" style="width:100%;margin-top:12px">Open my portal</button>
        </form>
        <form method="POST" action="{{ route('portal.resend') }}" style="margin-top:14px">@csrf
            <button class="btn" id="resend" type="submit" @if ($cooldown > 0) disabled @endif>Send a new code<span id="count">@if ($cooldown > 0) (in {{ $cooldown }}s)@endif</span></button>
            <a href="{{ route('portal.login') }}" class="muted" style="margin-left:10px">Use a different SB ID</a>
        </form>
    </div>
    <script>
        (function(){var s={{ (int) $cooldown }},b=document.getElementById('resend'),c=document.getElementById('count');if(s<=0)return;
        var t=setInterval(function(){s--;if(s<=0){clearInterval(t);b.disabled=false;c.textContent='';}else{c.textContent=' (in '+s+'s)';}},1000);})();
    </script>
</x-portal-layout>