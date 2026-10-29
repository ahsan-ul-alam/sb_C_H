@use('App\Support\ListFilters')
@php($editing = $client->exists)
<x-layout :title="$editing ? 'Edit client' : 'New client'">
    <x-page-header :title="$editing ? 'Edit client' : 'New client'"
        :crumbs="$editing
            ? [['Clients', ListFilters::url('clients')], [$client->name, route('clients.show', $client)], ['Edit', null]]
            : [['Clients', ListFilters::url('clients')], ['New client', null]]" />

    <form method="POST" action="{{ $editing ? route('clients.update', $client) : route('clients.store') }}" class="panel">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <div class="form-section">
            <h2>Contact</h2>
            <p class="section-hint">Only the name and primary mobile number are required. Numbers are stored as text, so leading zeros are kept.</p>
            <div class="form-grid">
                <div class="field full">
                    <label for="name">Full name<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $client->name) }}" required maxlength="255" autocomplete="off" @class(['is-invalid' => $errors->has('name')])>
                    @error('name') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="mobile">Mobile number<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="mobile" name="mobile" value="{{ old('mobile', $client->mobile) }}" required inputmode="tel" maxlength="32" placeholder="01XXXXXXXXX" @class(['is-invalid' => $errors->has('mobile')])>
                    @error('mobile') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="mobile_alt">Additional number <span class="opt">(optional)</span></label>
                    <input type="text" id="mobile_alt" name="mobile_alt" value="{{ old('mobile_alt', $client->mobile_alt) }}" inputmode="tel" maxlength="32" @class(['is-invalid' => $errors->has('mobile_alt')])>
                    @error('mobile_alt') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="email">Email <span class="opt">(optional)</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email', $client->email) }}" placeholder="name@example.com" @class(['is-invalid' => $errors->has('email')])>
                    @error('email') <span class="error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div class="form-section">
            <h2>Address</h2>
            <div class="form-grid">
                <div class="field full">
                    <label for="address">Address <span class="opt">(optional)</span></label>
                    <textarea id="address" name="address" rows="2" placeholder="House, road, area, city">{{ old('address', $client->address) }}</textarea>
                </div>
                <div class="field">
                    <label for="country">Country <span class="opt">(optional)</span></label>
                    <input type="text" id="country" name="country" value="{{ old('country', $client->country) }}" placeholder="Bangladesh">
                </div>
            </div>
        </div>

        <div class="form-section">
            <h2>Identification</h2>
            <p class="section-hint">Shown only on the client's detail page.</p>
            <div class="form-grid">
                <div class="field">
                    <label for="id_type">Identification type <span class="opt">(optional)</span></label>
                    <select id="id_type" name="id_type" @class(['is-invalid' => $errors->has('id_type')])>
                        <option value="">Not provided</option>
                        @foreach (\App\Enums\IdentificationType::cases() as $type)
                            <option value="{{ $type->value }}" @selected(old('id_type', $client->id_type?->value) === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('id_type') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="id_number">Identification number <span class="opt">(optional)</span></label>
                    <input type="text" id="id_number" name="id_number" value="{{ old('id_number', $client->id_number) }}" maxlength="64" autocomplete="off">
                </div>
                <div class="field full">
                    <label for="notes">Notes <span class="opt">(optional)</span></label>
                    <textarea id="notes" name="notes" placeholder="Anything useful to remember about this client">{{ old('notes', $client->notes) }}</textarea>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="check" /> {{ $editing ? 'Save changes' : 'Create client' }}</button>
            <a href="{{ $editing ? route('clients.show', $client) : ListFilters::url('clients') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layout>
