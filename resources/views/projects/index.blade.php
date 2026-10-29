@use('App\Support\Money')
<x-layout title="Projects">
    <x-page-header title="Projects">
        <x-slot:meta>
            {{ $projects->total() }} {{ Str::plural('project', $projects->total()) }}
            @if ($filterClient) · Client: <a href="{{ route('clients.show', $filterClient) }}">{{ $filterClient->name }}</a> @endif
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('projects.start', $filterClient ? ['client_id' => $filterClient->id] : []) }}" class="btn btn-primary"><x-icon name="plus" /> New project</a>
        </x-slot:actions>
    </x-page-header>

    <section class="panel">
        <form method="GET" action="{{ route('projects.index') }}" class="filters" role="search">
            @if ($filterClient) <input type="hidden" name="client" value="{{ $filterClient->id }}"> @endif
            <div class="field grow">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Project or client name">
            </div>
            <div class="field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach (\App\Enums\ProjectStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="currency">Currency</label>
                <select id="currency" name="currency">
                    <option value="">All</option>
                    @foreach ($currencies as $code)
                        <option value="{{ $code }}" @selected(request('currency') === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </div>
            <label class="check"><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))> Archived only</label>
            <button type="submit" class="btn"><x-icon name="search" class="icon-sm" /> Filter</button>
            @if (request()->hasAny(['q', 'status', 'currency', 'archived', 'client']))
                <a href="{{ route('projects.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </form>

        @if ($projects->isEmpty())
            <x-empty-state icon="folder" message="{{ request()->hasAny(['q', 'status', 'currency', 'archived', 'client']) ? 'No projects match these filters.' : 'No projects yet. Projects belong to a client and hold the agreed charges.' }}">
                <a href="{{ route('projects.start') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="icon-sm" /> New project</a>
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table table-min-760">
                    <thead>
                        <tr><th>Project / Client</th><th>Status</th><th>Started</th><th class="num">Agreed charges</th><th class="num">Received</th><th class="num">Project balance</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($projects as $project)
                            @php($charges = (int) $project->segments_sum_amount)
                            @php($received = (int) $project->payments_sum_amount)
                            <tr>
                                <td class="primary-cell">
                                    <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>
                                    <span class="secondary">{{ $project->client->name }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-{{ $project->status->value }}">{{ $project->status->label() }}</span>
                                    @if ($project->isArchived()) <span class="badge badge-archived">Archived</span> @endif
                                </td>
                                <td class="nowrap">{{ $project->started_on->format('j M Y') }}</td>
                                <td class="num">{{ Money::format($charges, $project->currency) }}</td>
                                <td class="num">{{ Money::format($received, $project->currency) }}</td>
                                <td class="num strong">{{ Money::format($charges - $received, $project->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $projects])
        @endif
    </section>
</x-layout>
