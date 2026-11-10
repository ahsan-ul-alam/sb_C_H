<x-portal-auth title="Sign in">
    <h1>Access Your Business Portal</h1>
    <p>Enter your SB ID and we will email a six-digit code to the address we hold for you.</p>
    <form method="POST" action="{{ route('portal.code') }}" data-loading novalidate>@csrf
        <div class="pt-field">
            <label for="sb">Your SB ID</label>
            <input id="sb" name="sb_number" value="{{ old('sb_number') }}" placeholder="SB-000123" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus required @class(['pt-input', 'pt-sb-input', 'is-invalid' => $errors->has('sb_number')]) @if ($errors->has('sb_number')) aria-invalid="true" aria-describedby="sb-err" @endif>
            @error('sb_number')<span class="pt-error" id="sb-err" role="alert">{{ $message }}</span>@enderror
            <span class="muted" style="font-size:.8rem">You can find it on your Sellbees invoices and messages.</span>
        </div>
        <button type="submit" class="pt-btn pt-btn-primary pt-btn-block" style="margin-top:1.1rem">Email me a code</button>
    </form>
    <div class="pt-assure"><x-icon name="shield" /><span>For your safety this screen looks the same whether or not an SB ID is found. Nobody can see your information without the code.</span></div>
</x-portal-auth>
