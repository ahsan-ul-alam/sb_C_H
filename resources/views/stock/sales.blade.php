@use('App\Support\Money')
<x-layout title="Sales dashboard">
    <x-page-header title="Sales dashboard">
        <x-slot:meta>Gross order value is the client's merchandise. Sellbees revenue is only the commission on what was delivered and not returned; cash collected is the client's money held.</x-slot:meta>
        <x-slot:actions><a class="btn" href="{{ route('stock.report', ['type' => 'orders-by-status', 'export' => 'csv']) }}">Export orders CSV</a></x-slot:actions>
    </x-page-header>
    <section class="panel"><form method="GET" class="filters"><div class="field"><label>Ordered from</label><input type="date" name="from" value="{{ request('from') }}"></div><div class="field"><label>to</label><input type="date" name="to" value="{{ request('to') }}"></div><button class="btn">Filter</button></form></section>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Orders received</div><div class="value">{{ $f['counts']['received'] }}</div></div>
        <div class="metric"><div class="label">Confirmed, awaiting dispatch</div><div class="value">{{ $f['counts']['awaiting_dispatch'] }}</div></div>
        <div class="metric"><div class="label">Dispatched</div><div class="value">{{ $f['counts']['dispatched'] }}</div></div>
        <div class="metric"><div class="label">Delivered</div><div class="value">{{ $f['counts']['delivered'] }}</div></div>
        <div class="metric"><div class="label">With returns</div><div class="value">{{ $f['counts']['returned'] }}</div></div>
        <div class="metric"><div class="label">Cancelled / failed</div><div class="value">{{ $f['counts']['cancelled'] }} / {{ $f['counts']['failed'] }}</div></div>
    </div>
    <div class="metrics-auto">
        <div class="metric"><div class="label">Gross order value (client merchandise)</div><div class="value">{{ Money::format($f['gross']) }}</div></div>
        <div class="metric"><div class="label">Delivered value</div><div class="value">{{ Money::format($f['delivered']) }}</div></div>
        <div class="metric"><div class="label">Cash collected (held for clients)</div><div class="value">{{ Money::format($f['collected']) }}</div></div>
        <div class="metric"><div class="label">Refunded to customers (paid)</div><div class="value">{{ Money::format($f['refunded']) }}</div></div>
        <div class="metric"><div class="label">Customer receivables</div><div class="value">{{ Money::format($f['receivable']) }}</div></div>
        <div class="metric"><div class="label">Commission earned (Sellbees revenue)</div><div class="value">{{ Money::format($f['commission']) }}</div></div>
        <div class="metric"><div class="label">Product COGS (on client handover)</div><div class="value">{{ Money::format($f['cogs']) }}</div></div>
    </div>

    <section class="panel"><div class="panel-head"><h2>Project sales performance</h2></div><div class="table-wrap"><table class="table">
        <thead><tr><th>Project</th><th class="num">Orders</th><th class="num">Delivered</th><th class="num">Gross order value</th><th class="num">Delivered value</th><th class="num">Commission</th></tr></thead>
        <tbody>@forelse ($f['by_project'] as $p)
            <tr><td>{{ $p['project']?->name }}</td><td class="num">{{ $p['orders'] }}</td><td class="num">{{ $p['delivered_orders'] }}</td><td class="num">{{ Money::format($p['gross']) }}</td><td class="num">{{ Money::format($p['delivered']) }}</td><td class="num">{{ Money::format($p['commission']) }}</td></tr>
        @empty <tr><td colspan="6" class="muted">No orders.</td></tr> @endforelse</tbody></table></div></section>

    <section class="panel"><div class="panel-head"><h2>Delivered value by month</h2></div><div class="table-wrap"><table class="table"><thead><tr><th>Month</th><th class="num">Delivered value</th></tr></thead>
        <tbody>@forelse ($f['trend'] as $month => $v)<tr><td>{{ $month }}</td><td class="num">{{ Money::format($v) }}</td></tr>@empty <tr><td colspan="2" class="muted">Nothing delivered yet.</td></tr> @endforelse</tbody></table></div></section>

    <section class="panel"><div class="panel-head"><h2>Recent orders</h2><a href="{{ route('product.orders') }}">Create and manage orders</a></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Order</th><th>Project</th><th>Order status</th><th>Fulfilment</th><th>Payment</th><th class="num">Gross value</th><th class="num">Collected</th></tr></thead>
        <tbody>@forelse ($orders as $r)
            <tr><td>{{ $r['order']->reference }}</td><td>{{ $r['order']->project?->name }}</td><td>{{ $r['s']['order'] }}</td><td>{{ $r['s']['fulfilment'] }}</td><td>{{ $r['s']['payment'] }}</td>
                <td class="num">{{ Money::format((int) $r['order']->items->sum(fn ($i) => $i->amount())) }}</td><td class="num">{{ Money::format((int) $r['order']->collections->sum('amount')) }}</td></tr>
        @empty <tr><td colspan="7" class="muted">No orders yet.</td></tr> @endforelse</tbody></table></div></section>
</x-layout>
