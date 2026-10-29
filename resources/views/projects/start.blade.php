@use('App\Support\ListFilters')
<x-layout title="New project">
    <x-page-header title="New project" :crumbs="[['Projects', ListFilters::url('projects')], ['New project', null]]" />

    @if ($clients->isEmpty())
        <section class="panel">
            <x-empty-state icon="users" message="A project belongs to a client. Add a client first.">
                <a href="{{ route('clients.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="icon-sm" /> New client</a>
            </x-empty-state>
        </section>
    @else
        <form method="GET" action="{{ route('projects.start') }}" class="panel" style="max-width:560px">
            <div class="form-section">
                <h2>Which client is this project for?</h2>
                <p class="section-hint">Next you'll enter the project name, status and currency.</p>
                <div class="field">
                    <label for="client_id">Client<span class="req" aria-hidden="true">*</span></label>
                    <select id="client_id" name="client_id" required size="{{ min(8, max(3, $clients->count())) }}" data-searchable="Search clients by name or mobile">
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }} — {{ $client->mobile }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Continue <x-icon name="arrow-right" class="icon-sm" /></button>
                <a href="{{ route('clients.create') }}" class="btn btn-ghost">Client not listed? Add a client</a>
            </div>
        </form>
    @endif
</x-layout>
