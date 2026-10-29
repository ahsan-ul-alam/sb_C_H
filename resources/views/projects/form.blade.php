@use('App\Support\ListFilters')
@php($editing = $project->exists)
<x-layout :title="$editing ? 'Edit project' : 'New project'">
    <x-page-header :title="$editing ? 'Edit project' : 'New project'"
        :crumbs="$editing
            ? [['Projects', ListFilters::url('projects')], [$client->name, route('clients.show', $client)], [$project->name, route('projects.show', $project)], ['Edit', null]]
            : [['Clients', ListFilters::url('clients')], [$client->name, route('clients.show', $client)], ['New project', null]]">
        <x-slot:meta>Client: <a href="{{ route('clients.show', $client) }}">{{ $client->name }}</a></x-slot:meta>
    </x-page-header>

    <form method="POST" action="{{ $editing ? route('projects.update', $project) : route('clients.projects.store', $client) }}" class="panel">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <div class="form-section">
            <h2>Project details</h2>
            <p class="section-hint">After saving, add segments for each agreed charge (e.g. onboarding, sourcing, ad budget).</p>
            <div class="form-grid">
                <div class="field full">
                    <label for="name">Project name<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $project->name) }}" required maxlength="255" placeholder="e.g. Business Development" @class(['is-invalid' => $errors->has('name')])>
                    @error('name') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="status">Status<span class="req" aria-hidden="true">*</span></label>
                    <select id="status" name="status" required>
                        @foreach (\App\Enums\ProjectStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected(old('status', $project->status?->value) === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="currency">Currency<span class="req" aria-hidden="true">*</span></label>
                    <input type="text" id="currency" name="currency" value="{{ old('currency', $project->currency) }}" required maxlength="3" placeholder="BDT" style="text-transform:uppercase" @class(['is-invalid' => $errors->has('currency')])>
                    <span class="hint">3-letter code. Locked once invoices or payments exist.</span>
                    @error('currency') <span class="error">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="started_on">Creation date<span class="req" aria-hidden="true">*</span></label>
                    <input type="date" id="started_on" name="started_on" value="{{ old('started_on', $project->started_on?->format('Y-m-d')) }}" required>
                </div>
                <div class="field full">
                    <label for="description">Description / notes <span class="opt">(optional)</span></label>
                    <textarea id="description" name="description" placeholder="Scope, agreements, anything to remember">{{ old('description', $project->description) }}</textarea>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="check" /> {{ $editing ? 'Save changes' : 'Create project' }}</button>
            <a href="{{ $editing ? route('projects.show', $project) : route('clients.show', $client) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layout>
