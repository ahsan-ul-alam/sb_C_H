@use('App\Services\ProjectInventory')
<x-layout :title="$project->name.' — inventory & orders'">
    <x-page-header :title="$project->name">
        <x-slot:meta>{{ $project->client->name }} · this project's own stock and orders. Stock of another project is never shown or used here; quantities come from the stock ledger and orders from the order records.</x-slot:meta>
        <x-slot:actions>
            @can('orders.manage')<a class="btn btn-primary" href="{{ route('product.orders', ['project' => $project->id]) }}">New order</a>@endcan
            <a class="btn" href="{{ route('stock.projects', ['project' => $project->id]) }}">Full stock detail</a>
        </x-slot:actions>
    </x-page-header>

    <section class="panel">
        <div class="panel-head"><h2>Inventory</h2></div>
        <div class="panel-body">
            @forelse ($totals['units'] as $unit => $u)
                <div class="stat-grid" style="margin-bottom:.6rem">
                    @foreach (['received' => 'Received (accepted)', 'available' => 'Available', 'reserved' => 'Reserved', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'returned' => 'Returned, awaiting inspection', 'damaged' => 'Damaged', 'rejected' => 'Rejected at receiving'] as $k => $label)
                        <div class="stat"><span class="stat-label">{{ $label }} ({{ $unit }})</span><span class="stat-value">{{ $u[$k] }}</span></div>
                    @endforeach
                </div>
            @empty
                <p class="muted">No stock has been received for this project yet.</p>
            @endforelse
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Orders</h2></div>
        <div class="panel-body"><div class="stat-grid">
            <div class="stat"><span class="stat-label">Total orders</span><span class="stat-value">{{ $counts['total'] }}</span></div>
            <div class="stat"><span class="stat-label">Pending (not yet delivered)</span><span class="stat-value">{{ $counts['pending'] }}</span></div>
            <div class="stat"><span class="stat-label">Completed</span><span class="stat-value">{{ $counts['completed'] }}</span></div>
            <div class="stat"><span class="stat-label">Cancelled or failed</span><span class="stat-value">{{ $counts['cancelled'] }}</span></div>
        </div></div>
    </section>

    <section class="panel">
        <form method="GET" class="filters" role="search">
            <div class="field grow"><label>Product</label><input type="search" name="product" value="{{ request('product') }}" placeholder="Product name"></div>
            <div class="field grow"><label>Order reference, customer or tracking</label><input type="search" name="search" value="{{ request('search') }}"></div>
            <div class="field"><label>Order state</label><select name="state"><option value="">All</option>@foreach ($states as $k => $v)<option value="{{ $k }}" @selected(request('state') === $k)>{{ $v }}</option>@endforeach</select></div>
            <button class="btn">Filter</button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Stock by lot and product</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Product / lot</th><th>Ownership</th><th class="num">Accepted</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Dispatched</th><th class="num">Delivered</th><th class="num">Returned</th><th class="num">Damaged</th></tr></thead>
            <tbody>
            @forelse ($positions as $r)
                <tr>
                    <td class="primary-cell"><a href="{{ route('product.inventory.show', $r['line']) }}">{{ $r['line']->description }}</a><span class="secondary">{{ $r['line']->supplier->name }} · {{ $r['line']->lot->number }}@if ($r['handover']) · handover #{{ $r['handover']->id }}@endif</span></td>
                    <td>{{ ProjectInventory::OWNERSHIP[$r['ownership']] }}</td>
                    <td class="num">{{ $r['accepted'] ?? '—' }}</td><td class="num strong">{{ $r['b']['available'] }}</td><td class="num">{{ $r['b']['reserved'] }}</td><td class="num">{{ $r['b']['dispatched'] }}</td>
                    <td class="num">{{ $r['b']['delivered'] }}</td><td class="num">{{ $r['b']['returned'] }}</td><td class="num">{{ $r['b']['damaged'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">No stock matches.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Orders</h2></div>
        @include('product._orders-table', ['orders' => $orders, 'accounts' => $accounts])
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Stock movement history</h2><span class="sub">latest 60; history is never edited or deleted</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Date</th><th>Product / lot</th><th>Movement</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Dispatched</th><th class="num">Delivered</th><th class="num">Returned</th><th class="num">Damaged</th><th>By</th><th>Reason</th></tr></thead>
            <tbody>
            @forelse ($movements as $m)
                <tr><td>{{ $m->occurred_on->format('d M Y') }}</td><td>{{ $m->line->description }}<span class="secondary">{{ $m->line->lot->number }}</span></td><td>{{ $m->type }}</td>
                    <td class="num">{{ $m->d_available }}</td><td class="num">{{ $m->d_reserved }}</td><td class="num">{{ $m->d_dispatched }}</td><td class="num">{{ $m->d_delivered }}</td><td class="num">{{ $m->d_returned }}</td><td class="num">{{ $m->d_damaged }}</td>
                    <td>{{ $m->creator?->name ?? '—' }}</td><td>{{ $m->reason }}</td></tr>
            @empty
                <tr><td colspan="11" class="muted">No movements yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
