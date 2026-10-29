<x-layout title="Clients">
    <x-page-header :title="$showArchived ? 'Archived clients' : 'Clients'">
        <x-slot:meta>{{ $clients->total() }} {{ Str::plural('client', $clients->total()) }}</x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('clients.create') }}" class="btn btn-primary"><x-icon name="plus" /> New client</a>
        </x-slot:actions>
    </x-page-header>

    <section class="panel">
        <form method="GET" action="{{ route('clients.index') }}" class="filters" role="search">
            <div class="field grow">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Name, mobile or email">
            </div>
            <label class="check"><input type="checkbox" name="archived" value="1" @checked($showArchived)> Archived only</label>
            <button type="submit" class="btn"><x-icon name="search" class="icon-sm" /> Search</button>
            @if (request()->hasAny(['q', 'archived']))
                <a href="{{ route('clients.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </form>

        @if ($clients->isEmpty())
            @if (request()->hasAny(['q', 'archived']))
                <x-empty-state icon="search" message="No clients match these filters." />
            @else
                <x-empty-state icon="users" message="No clients yet. Add your first client to start tracking projects and invoices.">
                    <a href="{{ route('clients.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="icon-sm" /> New client</a>
                </x-empty-state>
            @endif
        @else
            <div class="table-wrap">
                <table class="table table-min-640">
                    <thead>
                        <tr><th>Client</th><th>Mobile</th><th>Email</th><th>Country</th><th class="num">Projects</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $client)
                            <tr>
                                <td class="primary-cell">
                                    <a href="{{ route('clients.show', $client) }}">{{ $client->name }}</a>
                                    @if ($client->isArchived()) <span class="badge badge-archived">Archived</span> @endif
                                </td>
                                <td class="nowrap">{{ $client->mobile }}</td>
                                <td>{{ $client->email ?? '—' }}</td>
                                <td>{{ $client->country ?? '—' }}</td>
                                <td class="num">{{ $client->projects_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $clients])
        @endif
    </section>
</x-layout>
