@use('App\Support\Money')
<x-layout title="Inventory">
    <x-page-header title="Inventory">
        <x-slot:meta>Stock enters only when an arrival is recorded on the lot. Orders reserve it, delivery uses it, and every change is a recorded movement.</x-slot:meta>
    </x-page-header>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Units available</div><div class="value">{{ rtrim(rtrim(number_format($cards['available'], 2), '0'), '.') }}</div></div>
        <div class="metric"><div class="label">Orders pending</div><div class="value">{{ $cards['pending'] }}</div></div>
        <div class="metric"><div class="label">Deliveries awaiting confirmation</div><div class="value">{{ $cards['confirmed'] }}</div></div>
        <div class="metric"><div class="label">Sales awaiting collection</div><div class="value">{{ Money::format($cards['awaiting_collection']) }}</div></div>
        <div class="metric"><div class="label">Client money held</div><div class="value">{{ Money::format($cards['settlement_due']) }}</div></div>
        <div class="metric"><div class="label">Storage charges unpaid</div><div class="value">{{ Money::format($cards['storage_due']) }}</div></div>
    </div>
    @if ($cards['unowned'] > 0 || $cards['needs_measurement'] > 0)
        <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div><strong>Needs action:</strong>
            @if ($cards['unowned'] > 0) {{ $cards['unowned'] }} lot(s) with received stock have no owner stated yet (open the product to choose). @endif
            @if ($cards['needs_measurement'] > 0) {{ $cards['needs_measurement'] }} storage charge(s) wait for the occupied area to be measured. @endif</div></div>
    @endif

    <section class="panel">
        <form method="GET" class="filters" role="search">
            <div class="field"><label>Client</label><select name="client"><option value="">All</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="field"><label>Supplier</label><select name="supplier"><option value="">All</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="field grow"><label>Product</label><input type="search" name="product" value="{{ request('product') }}" placeholder="Product name"></div>
            <div class="field"><label>Lot</label><input name="lot" size="6" value="{{ request('lot') }}" placeholder="lot id"></div>
            <div class="field"><label>Availability</label><select name="availability"><option value="">All</option><option value="in_stock" @selected(request('availability') === 'in_stock')>In stock</option><option value="out" @selected(request('availability') === 'out')>Out of stock</option></select></div>
            <button class="btn">Filter</button>
        </form>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Client</th><th>Product</th><th>Lot</th><th class="num">Received</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Delivered</th><th class="num">Damaged</th><th class="num">Returned</th><th>Area</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @php($l = $r['line'])
                @php($b = $r['b'])
                <tr><td>{{ $l->lot->project?->client?->name ?? '—' }}</td>
                    <td class="primary-cell"><a href="{{ route('product.inventory.show', $l) }}">{{ $l->description }}</a><span class="secondary">{{ $l->supplier->name }}</span></td>
                    <td>{{ $l->lot->number }}</td><td class="num">{{ $b['received'] }}</td><td class="num strong">{{ $b['available'] }}</td><td class="num">{{ $b['reserved'] }}</td>
                    <td class="num">{{ $b['delivered'] }}</td><td class="num">{{ bcadd($b['damaged'], bcsub((string) $l->received_quantity, (string) $l->usable_quantity, 2), 2) }}</td><td class="num">{{ $b['returned'] }}</td>
                    <td>{{ $l->lot->storage_sqft ? $l->lot->storage_sqft.' sq ft' : '—' }}</td>
                    <td>
                        @if ($l->lot->ownership === null)
                            <span class="badge badge-partial">Owner not stated</span>
                        @elseif ($l->lot->ownership === 'company')
                            <span class="badge badge-archived">SellBees-owned</span>
                        @elseif (bccomp($b['available'], '0', 2) > 0)
                            <span class="badge badge-paid">In stock</span>
                        @else
                            <span class="badge badge-unpaid">Out of stock</span>
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="11" class="muted">No stock yet. Record the arrival on a sourcing lot (open the lot, then Mark received) to put it into stock.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
