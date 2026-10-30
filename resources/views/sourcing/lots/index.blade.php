@use('App\Support\Money')
<x-layout title="Sourcing Lots">
    <x-page-header title="Sourcing Lots">
        <x-slot:meta>Accrual view per lot: cost incurred vs. sales agreed. Cash movements reach Main Finance only through verified expenses and ClientHub payments.</x-slot:meta>
        @can('sourcing.manage')
        <x-slot:actions><a href="{{ route('sourcing.lots.create') }}" class="btn btn-primary"><x-icon name="plus" /> New lot</a></x-slot:actions>

        @endcan
    </x-page-header>
    <section class="panel">
        <form method="GET" class="filters" role="search">
            <div class="field grow"><label for="q">Search</label><input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Lot number or title"></div>
            <div class="field"><label>Status</label><select name="status"><option value="">All</option>@foreach (\App\Models\SourcingLot::STATUSES as $k => $label)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label>Supplier</label><select name="supplier"><option value="">All</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <button class="btn">Filter</button>
        </form>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Lot</th><th>Status</th><th class="num">Landed cost</th><th class="num">Sales value</th><th class="num">Profit / loss</th><th class="num">Supplier payable</th></tr></thead>
            <tbody>
            @forelse ($lots as $lot)
                @php($f = $figures[$lot->id])
                <tr><td class="primary-cell"><a href="{{ route('sourcing.lots.show', $lot) }}">{{ $lot->number }}</a><span class="secondary">{{ $lot->title }}</span></td>
                    <td><x-status :value="$lot->status" /></td>
                    <td class="num">{{ Money::format($f['landed']) }}</td><td class="num">{{ Money::format($f['revenue']) }}</td>
                    <td class="num {{ $f['profit'] < 0 ? 'text-danger' : 'text-success' }}">{{ Money::format($f['profit']) }}</td>
                    <td class="num">{{ Money::format($f['supplier_outstanding']) }}</td></tr>
            @empty
                <tr><td colspan="6" class="muted">No lots yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $lots])
    </section>
</x-layout>
