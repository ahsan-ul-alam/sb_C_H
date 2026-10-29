<x-layout title="Edit {{ $partner->code }}">
    <x-page-header title="Edit partner {{ $partner->code }}" :crumbs="[['Partners', route('finance.partners')], [$partner->code, route('finance.partners.show', $partner)], ['Edit', null]]">
        <x-slot:meta>Profile details only. Ratios come from signed policies, capital from the verified ledger, and status from the exit workflow.</x-slot:meta>
    </x-page-header>

    <section class="panel form-card">
        <form method="POST" action="{{ route('finance.partners.update', $partner) }}" novalidate>
            @csrf @method('PUT')
            <div class="form-section">
                <h2>Partner information</h2>
                <p class="section-hint">Fields marked * are required.</p>
                <div class="form-grid">
                    <div class="field full">
                        <label for="name">Full name</label>
                        <input id="name" name="name" value="{{ old('name', $partner->name) }}" required maxlength="120" autocomplete="name">
                    </div>
                    <div class="field">
                        <label for="joined_on">Joining date <span class="opt">(optional)</span></label>
                        <input type="date" id="joined_on" name="joined_on" value="{{ old('joined_on', $partner->joined_on?->toDateString()) }}">
                    </div>
                </div>
            </div>
            <div class="form-section">
                <h2>Fixed details</h2>
                <p class="section-hint">These identify the founder in policies and the ledger, so they are read-only here.</p>
                <div class="form-grid">
                    <div class="field">
                        <label for="code">Partner code</label>
                        <input id="code" value="{{ $partner->code }}" readonly aria-describedby="code-hint">
                        <span class="hint" id="code-hint">Cannot be changed.</span>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <input id="status" value="{{ ucfirst($partner->status) }}" readonly>
                    </div>
                    <div class="field full">
                        <label for="linked">Linked user account</label>
                        <input id="linked" value="{{ $linkedUser ? $linkedUser->name.' ('.$linkedUser->email.')' : 'Not linked yet' }}" readonly>
                        @can('users.manage')<span class="hint">Change the link in <a href="{{ route('users.index') }}">Users &amp; Roles</a>.</span>@endcan
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('finance.partners.show', $partner) }}" class="btn">Cancel</a>
            </div>
        </form>
    </section>
</x-layout>
