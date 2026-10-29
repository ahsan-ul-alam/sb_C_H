@use('App\Support\ListFilters')
<x-layout title="New invoice">
    <x-page-header title="New invoice" :crumbs="[['Invoices', ListFilters::url('invoices')], ['New invoice', null]]">
        @if ($client)
            <x-slot:meta>Client: <a href="{{ route('clients.show', $client) }}">{{ $client->name }}</a></x-slot:meta>
        @endif
    </x-page-header>

    @if ($projects->isEmpty())
        <section class="panel">
            <x-empty-state icon="folder" message="Invoices are created for a project. {{ $client ? 'This client has no active projects yet.' : 'There are no active projects yet.' }}">
                <a href="{{ $client ? route('clients.projects.create', $client) : route('projects.start') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="icon-sm" /> New project</a>
            </x-empty-state>
        </section>
    @else
        <form method="GET" action="{{ route('invoices.start') }}" class="panel" style="max-width:620px">
            <div class="form-section">
                <h2>Which project is this invoice for?</h2>
                <p class="section-hint">The invoice uses the project's client and currency. You can add segment charges or manual items next.</p>
                <div class="field">
                    <label for="project_id">Project<span class="req" aria-hidden="true">*</span></label>
                    <select id="project_id" name="project_id" required size="{{ min(8, max(3, $projects->count())) }}" data-searchable="Search by project or client">
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected($projects->count() === 1)>{{ $project->name }} — {{ $project->client->name }} ({{ $project->currency }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Continue <x-icon name="arrow-right" class="icon-sm" /></button>
                @if ($client)
                    <a href="{{ route('invoices.start') }}" class="btn btn-ghost">Show all clients' projects</a>
                @endif
            </div>
        </form>
    @endif
</x-layout>
