@use('App\Support\Money')
@use('App\Services\ProjectInventory')
<x-layout title="Project stock">
    <x-page-header title="Project stock">
        <x-slot:meta>Project → product → lot line → location. A lot shared by several projects shows its units once, on a shared-lot row.</x-slot:meta>
        <x-slot:actions><a class="btn" href="{{ route('stock.report', array_merge(['type' => 'project-stock'], request()->query(), ['export' => 'csv'])) }}">Export CSV</a></x-slot:actions>
    </x-page-header>

    <section class="panel">
        <form method="GET" class="filters" role="search">
            <div class="field"><label>Project</label><select name="project"><option value="">All</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected(request('project') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <div class="field"><label>Client</label><select name="client"><option value="">All</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="field grow"><label>Product</label><input type="search" name="product" value="{{ request('product') }}" placeholder="Product name"></div>
            <div class="field"><label>Supplier</label><select name="supplier"><option value="">All</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="field"><label>Lot</label><input name="lot" size="5" value="{{ request('lot') }}" placeholder="lot id"></div>
            <div class="field"><label>Location</label><select name="location"><option value="">All</option>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected(request('location') == $l->id)>{{ $l->name }}</option>@endforeach</select></div>
            <div class="field"><label>Ownership</label><select name="ownership"><option value="">All</option>@foreach (ProjectInventory::OWNERSHIP as $k => $v)<option value="{{ $k }}" @selected(request('ownership') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="field"><label>Stock status</label><select name="status"><option value="">All</option>@foreach (['in_stock' => 'In stock', 'out' => 'Out of stock', 'reserved' => 'Has reservations', 'quarantined' => 'Damaged / awaiting inspection'] as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="field"><label>Arrived from</label><input type="date" name="from" value="{{ request('from') }}"></div>
            <div class="field"><label>to</label><input type="date" name="to" value="{{ request('to') }}"></div>
            <button class="btn">Filter</button>
        </form>
    </section>

    @forelse ($rows as $key => $group)
        @php($first = $group->first())
        <section class="panel">
            <div class="panel-head"><h2>
                @if ($first['project']) <a href="{{ route('product.projects.show', $first['project']) }}">{{ $first['project']->name }}</a> <span class="sub">{{ $first['client']?->name }}</span>
                @elseif ($key === 'shared') Shared lots <span class="sub">physical units are counted once here; each project's allocation is shown on the line</span>
                @else Not attributed to any project @endif
            </h2></div>
            <div class="table-wrap"><table class="table table-min-760">
                <thead><tr><th>Product / lot</th><th>Ownership</th><th class="num">Accepted</th><th class="num">Rejected</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Dispatched</th><th class="num">Delivered</th><th class="num">Returned</th><th class="num">Damaged</th><th>Location</th><th>Valuation</th><th class="num">Unit cost</th><th class="num">Asset value</th></tr></thead>
                <tbody>
                @foreach ($group as $r)
                    @php($l = $r['line'])
                    <tr>
                        <td class="primary-cell">
                            @if ($l->product?->image_path)<img src="{{ route('stock.products.image', $l->product) }}" alt="" width="36" height="36" loading="lazy" style="object-fit:cover;border-radius:6px;vertical-align:middle;margin-right:.4rem">
                            @else<span aria-hidden="true" style="display:inline-flex;width:36px;height:36px;border-radius:6px;background:var(--surface-2,#eee);align-items:center;justify-content:center;margin-right:.4rem;vertical-align:middle">{{ mb_strtoupper(mb_substr($l->description, 0, 1)) }}</span>@endif
                            <a href="{{ route('product.inventory.show', $l) }}">{{ $l->description }}</a><span class="secondary">{{ $l->supplier->name }} · {{ $l->lot->number }}@if ($r['handover']) · handover #{{ $r['handover']->id }}@endif @if ($r['shared_projects']) · {{ implode(', ', $r['shared_projects']) }}@endif</span></td>
                        <td>{{ ProjectInventory::OWNERSHIP[$r['ownership']] }}</td>
                        <td class="num">{{ $r['accepted'] ?? '—' }}</td><td class="num">{{ $r['rejected'] ?? '—' }}</td>
                        <td class="num strong">{{ $r['b']['available'] }}</td><td class="num">{{ $r['b']['reserved'] }}</td><td class="num">{{ $r['b']['dispatched'] }}</td><td class="num">{{ $r['b']['delivered'] }}</td><td class="num">{{ $r['b']['returned'] }}</td><td class="num">{{ $r['b']['damaged'] }}</td>
                        <td>@forelse ($r['locations'] as $place => $q){{ $place }}: {{ $q }}<br>@empty — @endforelse</td>
                        <td>@if ($r['valuation'] === 'not_applicable') <span class="muted">client's stock</span> @else <span class="badge {{ $r['valuation'] === 'valued' ? 'badge-paid' : 'badge-partial' }}">{{ str_replace('_', ' ', $r['valuation']) }}</span> @endif</td>
                        <td class="num">{{ $r['unit_cost'] !== null ? Money::format((int) round((float) $r['unit_cost'])) : '—' }}</td>
                        <td class="num">{{ $r['asset_value'] !== null ? Money::format($r['asset_value']) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
    @empty
        <x-empty-state message="No stock matches. Record an arrival on the Receiving page, or clear the filters." />
    @endforelse
</x-layout>
