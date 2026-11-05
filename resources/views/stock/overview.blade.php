@use('App\Support\Money')
<x-layout title="Inventory overview">
    <x-page-header title="Inventory overview">
        <x-slot:meta>Every figure is calculated from the stock movement ledger. Quantities are shown per unit of measure and never added across different units.</x-slot:meta>
    </x-page-header>

    @forelse ($totals['units'] as $unit => $t)
        <h2 class="muted" style="font-size:.9rem; margin:1rem 0 .25rem">Unit: {{ $unit }}</h2>
        <div class="metrics-auto">
            <div class="metric"><div class="label">Total received (accepted)</div><div class="value">{{ $t['received'] }}</div></div>
            <div class="metric"><div class="label">Available</div><div class="value">{{ $t['available'] }}</div></div>
            <div class="metric"><div class="label">Reserved</div><div class="value">{{ $t['reserved'] }}</div></div>
            <div class="metric"><div class="label">Dispatched / in transit</div><div class="value">{{ $t['dispatched'] }}</div></div>
            <div class="metric"><div class="label">Delivered</div><div class="value">{{ $t['delivered'] }}</div></div>
            <div class="metric"><div class="label">Returned (awaiting inspection)</div><div class="value">{{ $t['returned'] }}</div></div>
            <div class="metric"><div class="label">Damaged / quarantined</div><div class="value">{{ $t['damaged'] }}</div></div>
            <div class="metric"><div class="label">Rejected at receiving</div><div class="value">{{ $t['rejected'] }}</div></div>
        </div>
    @empty
        <x-empty-state message="No stock yet. Stock appears when an arrival is recorded and accepted on the Receiving page." />
    @endforelse

    <div class="metrics-auto" style="margin-top:1rem">
        <div class="metric"><div class="label">Stock valuation (Sellbees-owned only)</div><div class="value">{{ Money::format($totals['asset_value']) }}</div></div>
        <div class="metric"><div class="label">Lines pending valuation</div><div class="value">{{ $totals['pending_valuation'] }}</div></div>
        <div class="metric"><div class="label">Product lines still expecting goods</div><div class="value"><a href="{{ route('stock.receiving') }}">{{ $pendingReceiving }}</a></div></div>
        <div class="metric"><div class="label">Inventory exceptions</div><div class="value"><a href="{{ route('stock.exceptions') }}">{{ $exceptions->count() }}</a></div></div>
        <div class="metric"><div class="label">Low / out-of-stock products</div><div class="value">{{ $lowStock->count() }}</div></div>
    </div>

    @if ($lowStock->isNotEmpty())
    <section class="panel"><div class="panel-head"><h2>Low stock</h2><span class="sub">Warning only. Nothing is ordered automatically.</span></div><div class="table-wrap"><table class="table">
        <thead><tr><th>Product</th><th>Project</th><th>Location</th><th class="num">Quantity</th><th class="num">Minimum</th><th>State</th></tr></thead>
        <tbody>@foreach ($lowStock as $r)
            <tr><td>{{ $r['threshold']->product->name }}</td><td>{{ $r['threshold']->project?->name ?? 'All' }}</td><td>{{ $r['threshold']->location?->name ?? 'All' }}</td>
                <td class="num">{{ $r['quantity'] }}</td><td class="num">{{ $r['threshold']->minimum_quantity }}</td>
                <td><span class="badge {{ $r['state'] === 'out' ? 'badge-unpaid' : 'badge-partial' }}">{{ $r['state'] === 'out' ? 'Out of stock' : 'Low' }}</span></td></tr>
        @endforeach</tbody></table></div></section>
    @endif

    <section class="panel"><div class="panel-head"><h2>Sales in brief</h2><a href="{{ route('stock.sales') }}">Sales dashboard</a></div><div class="panel-body">
        <p>{{ $sales['counts']['received'] }} orders · gross order value {{ Money::format($sales['gross']) }} (client merchandise, not Sellbees revenue) · commission earned {{ Money::format($sales['commission']) }}</p>
    </div></section>
</x-layout>
