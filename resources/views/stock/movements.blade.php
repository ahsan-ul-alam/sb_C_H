<x-layout title="Stock movements">
    <x-page-header title="Stock movements">
        <x-slot:meta>The one ledger every balance is calculated from. History is never edited; corrections are new movements with a reason.</x-slot:meta>
        <x-slot:actions><a class="btn" href="{{ route('stock.report', ['type' => 'movements', 'export' => 'csv']) }}">Export CSV</a></x-slot:actions>
    </x-page-header>
    <section class="panel">
        <form method="GET" class="filters" role="search">
            <div class="field"><label>Type</label><select name="type"><option value="">All</option>@foreach ($types as $t)<option @selected(request('type') === $t)>{{ $t }}</option>@endforeach</select></div>
            <div class="field"><label>Lot</label><input name="lot" size="5" value="{{ request('lot') }}" placeholder="lot id"></div>
            <div class="field"><label>From</label><input type="date" name="from" value="{{ request('from') }}"></div>
            <div class="field"><label>To</label><input type="date" name="to" value="{{ request('to') }}"></div>
            <button class="btn">Filter</button>
        </form>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Date</th><th>Product / lot</th><th>Type</th><th>Location</th><th class="num">Avail.</th><th class="num">Resvd</th><th class="num">Disp.</th><th class="num">Deliv.</th><th class="num">Ret.</th><th class="num">Dmg</th><th>Reason</th><th>By</th></tr></thead>
            <tbody>
            @forelse ($movements as $m)
                <tr><td>{{ $m->occurred_on->format('j M Y') }}</td><td class="primary-cell"><a href="{{ route('product.inventory.show', $m->line) }}">{{ $m->line->description }}</a><span class="secondary">{{ $m->line->lot->number }}</span></td>
                    <td>{{ $m->type }}</td><td>{{ $m->location?->name ?? '—' }}</td>
                    @foreach (['d_available', 'd_reserved', 'd_dispatched', 'd_delivered', 'd_returned', 'd_damaged'] as $c)<td class="num">{{ bccomp((string) $m->$c, '0', 2) === 0 ? '' : $m->$c }}</td>@endforeach
                    <td>{{ $m->reason }}</td><td>{{ $m->creator?->name ?? 'system' }}</td></tr>
            @empty
                <tr><td colspan="12" class="muted">No movements match.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $movements])
    </section>
</x-layout>
