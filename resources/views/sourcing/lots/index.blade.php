@use('App\Support\Money')
@use('App\Models\SourcingLot')
<x-layout title="Client Lots">
    <x-page-header title="Client Lots">
        <x-slot:meta>Each lot moves Processing → In order → Completed. Profit is the client budget minus the lot's real costs; money received and paid out is tracked separately on each lot.</x-slot:meta>
        @can('sourcing.manage')
        <x-slot:actions><a href="{{ route('sourcing.lots.create') }}" class="btn btn-primary"><x-icon name="plus" /> New lot</a></x-slot:actions>
        @endcan
    </x-page-header>

    <nav class="tabs" aria-label="Filter lots by stage">
        <a href="{{ route('sourcing.lots.index', request()->except(['stage', 'status', 'page'])) }}" @class(['active' => ! request('stage')])>All</a>
        @foreach (SourcingLot::STAGES as $key => $label)
            <a href="{{ route('sourcing.lots.index', ['stage' => $key] + request()->except(['stage', 'status', 'page'])) }}" @class(['active' => request('stage') === $key]) @if (request('stage') === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <section class="panel">
        <form method="GET" class="filters" role="search">
            @if (request('stage'))<input type="hidden" name="stage" value="{{ request('stage') }}">@endif
            <div class="field grow"><label for="q">Search</label><input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Lot number or title"></div>
            <div class="field"><label for="supplier">Supplier</label><select id="supplier" name="supplier"><option value="">All</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <button class="btn">Filter</button>
        </form>
        <div class="table-wrap"><table class="table table-min-760 lot-table">
            <thead><tr><th>Lot</th><th>Project</th><th>Supplier</th><th>Product</th><th class="num">Qty</th><th class="num">Total cost</th><th class="num">Client budget</th><th class="num">Profit / loss</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($lots as $lot)
                @php
                    $f = $figures[$lot->id];
                    $lines = $lot->lines;
                    $suppliersOf = $lines->pluck('supplier.name')->filter()->unique();
                    $realised = $lot->stage() === 'completed';
                    $action = match (true) {
                        $lot->hasClientBudget() && in_array($lot->status, ['priced', 'approved'], true) => 'Mark In order',
                        $lot->hasClientBudget() && $lot->status === 'in_progress' => 'Mark Completed',
                        default => 'View',
                    };
                @endphp
                <tr>
                    <td class="primary-cell nowrap"><a href="{{ route('sourcing.lots.show', $lot) }}">{{ $lot->number }}</a>
                        @if (\App\Services\ClientLot::reviewFlags($lot))<span class="secondary text-danger">Needs review</span>@endif</td>
                    <td data-label="Project">{{ $lot->project?->name ?? '—' }}@if ($lot->project?->client)<span class="secondary">{{ $lot->project->client->name }}</span>@endif</td>
                    <td data-label="Supplier">{{ $suppliersOf->count() > 1 ? $suppliersOf->count().' suppliers' : ($suppliersOf->first() ?? '—') }}</td>
                    <td data-label="Product">{{ $lines->count() > 1 ? $lines->count().' products' : ($lines->first()?->description ?? '—') }}</td>
                    <td data-label="Quantity" class="num">{{ $lines->count() === 1 ? rtrim(rtrim((string) $lines->first()->quantity, '0'), '.') : '—' }}</td>
                    <td data-label="Total cost" class="num">{{ Money::format($f['landed']) }}</td>
                    <td data-label="Client budget" class="num">{{ Money::format($lot->client_budget ?? $f['revenue']) }}</td>
                    <td data-label="Profit" class="num {{ $f['profit'] < 0 ? 'text-danger' : 'text-success' }}">{{ Money::format($f['profit']) }}<span class="secondary">{{ $realised ? 'realised' : 'expected' }}</span></td>
                    <td data-label="Status"><x-lot-stage :lot="$lot" /></td>
                    <td class="lot-action"><a class="btn btn-sm {{ $action === 'View' ? '' : 'btn-primary' }}" href="{{ route('sourcing.lots.show', $lot) }}">{{ $action }}</a></td>
                </tr>
            @empty
                <tr><td colspan="10" class="muted">No lots here yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $lots])
    </section>
</x-layout>
