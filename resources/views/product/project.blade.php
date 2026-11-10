@use('App\Services\ProjectInventory')
@use('App\Support\Money')
<x-layout :title="$project->name.' — inventory & orders'">
    <x-page-header :title="$project->name">
        <x-slot:meta>{{ $project->client->name }} · this project's own stock and orders. Stock of another project is never shown or used here; quantities come from the stock ledger and orders from the order records.</x-slot:meta>
        <x-slot:actions>
            @can('orders.manage')<a class="btn btn-primary" href="{{ route('product.orders', ['project' => $project->id]) }}">New order</a>@endcan
            <a class="btn" href="{{ route('stock.projects', ['project' => $project->id]) }}">Full stock detail</a>
        </x-slot:actions>
    </x-page-header>

    @php($e = $economics)
    <section class="panel">
        <div class="panel-head"><h2>Client financial performance</h2><span class="sub">Worked out by the server from the orders; nothing here is entered by hand and nothing is posted.</span></div>
        <div class="panel-body"><div class="stat-grid">
            @foreach ([['Client product investment', $e['investment']], ['Gross successful sales', $e['gross_sales']], ['Delivery charges deducted (once)', $e['delivery_charges']], ['Net successful sales', $e['net_sales']], ['Sellbees commission', $e['commission']], ['Investment recovered through successful sales', $e['investment_recovered']], ['Client product profit', $e['client_profit']], ['Project settlement entitlement', $e['entitlement']]] as [$label, $amount])
                <div class="stat"><span class="stat-label">{{ $label }}</span><span class="stat-value">{{ $amount === null ? 'Cost pending verification' : Money::format($amount) }}</span></div>
            @endforeach
        </div>
        <p class="muted" style="margin-top:.6rem">The client's product cost was paid at sourcing, so it is not deducted from what is payable; it is deducted once, only to show profit. Sold-unit cost (approved client price): {{ $e['cost_of_sold'] === null ? 'pending verification' : Money::format($e['cost_of_sold']) }}; investment still to recover: {{ $e['investment_unrecovered'] === null ? 'pending verification' : Money::format($e['investment_unrecovered']) }}.@if ($e['courier_advance_withheld'] > 0) Courier charge Sellbees paid for the client: {{ Money::format($e['courier_advance_withheld']) }} is kept out of the client's payout. Record the courier payment as a client advance (a second person verifies it) so it is cleared from client funds at settlement; nothing is billed or counted as income.@endif @if ($e['refund_reductions'] > 0) Refunds that reduced sales: {{ Money::format($e['refund_reductions']) }}.@endif @if ($e['unsuccessful_charges'] > 0) Courier charges on unsuccessful deliveries and returns (client-borne): {{ Money::format($e['unsuccessful_charges']) }}.@endif</p>
        @foreach ($e['notes'] as $n)<p class="muted">⚠ {{ $n }}</p>@endforeach
        <div class="stat-grid" style="margin-top:.6rem">
            @foreach ([['Client-wide entitlement (all projects of this client)', $e['client']['entitlement']], ['Client-wide settlement paid (not allocated to any project)', $e['client']['settled']], ['Client-wide storage fees netted', $e['client']['deductions_applied']], ['Client-wide outstanding settlement', $e['client']['outstanding']], ['Cash available to pay now', $e['client']['cash_available']], ['Earned but not yet in hand (e.g. courier remittance pending)', $e['client']['pending_remittance']]] as [$label, $amount])
                <div class="stat"><span class="stat-label">{{ $label }}</span><span class="stat-value">{{ Money::format($amount) }}</span></div>
            @endforeach
        </div></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Sellbees earnings</h2><span class="sub">Separate figures, never added together here.</span></div>
        <div class="panel-body"><div class="stat-grid">
            <div class="stat"><span class="stat-label">Sourcing margin (lot view; income only under the existing sourcing rules)</span><span class="stat-value">{{ Money::format($e['sourcing_margin']) }}</span></div>
            <div class="stat"><span class="stat-label">Commission earned on successful deliveries</span><span class="stat-value">{{ Money::format($e['commission']) }}</span></div>
            <div class="stat"><span class="stat-label">of which invoiced to the client</span><span class="stat-value">{{ Money::format($e['commission_invoiced']) }}</span></div>
            <div class="stat"><span class="stat-label">Courier cost Sellbees bears (not passed to the client; refunded: {{ Money::format($e['courier_refunded']) }})</span><span class="stat-value">{{ Money::format($e['sellbees_courier_cost']) }}</span></div>
            <div class="stat"><span class="stat-label">Estimate on orders not yet delivered (not earned)</span><span class="stat-value">{{ Money::format($e['commission_estimate']) }}</span></div>
        </div></div>
    </section>

    @include('product._sales-figures', ['a' => $dash])
    <section class="panel">
        <div class="panel-head"><h2>Low-stock warnings</h2><span class="sub">available stock under the minimum Finance set for the product</span></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Product</th><th class="num">Available</th><th class="num">Minimum</th><th>State</th></tr></thead>
            <tbody>
            @forelse ($lowStock as $r)
                <tr><td>{{ $r['product'] }}</td><td class="num">{{ $r['available'] }}</td><td class="num">{{ $r['minimum'] }}</td><td><span class="badge badge-{{ $r['state'] === 'out' ? 'unpaid' : 'partial' }}">{{ $r['state'] === 'out' ? 'Out of stock' : 'Low' }}</span></td></tr>
            @empty
                <tr><td colspan="4" class="muted">No low-stock warnings (or no minimum has been set for this project's products).</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    @include('product._sales-exceptions', ['exceptions' => $exceptions])
    <section class="panel">
        <div class="panel-head"><h2>Inventory</h2></div>
        <div class="panel-body">
            @forelse ($totals['units'] as $unit => $u)
                <div class="stat-grid" style="margin-bottom:.6rem">
                    @foreach (['received' => 'Received (accepted)', 'available' => 'Available', 'reserved' => 'Reserved', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'returned' => 'Returned, awaiting inspection', 'damaged' => 'Damaged', 'rejected' => 'Rejected at receiving'] as $k => $label)
                        <div class="stat"><span class="stat-label">{{ $label }} ({{ $unit }})</span><span class="stat-value">{{ $u[$k] }}</span></div>
                    @endforeach
                    <div class="stat"><span class="stat-label">Remaining with Sellbees ({{ $unit }})</span><span class="stat-value">{{ bcadd(bcadd($u['available'], $u['reserved'], 2), $u['returned'], 2) }}</span></div>
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
        <div class="panel-head"><h2>Stock allocated between projects</h2><span class="sub">A project can sell only its own share of a lot plus the quantity explicitly allocated to it. Billing allocations do not count.</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Product / lot</th><th>Direction</th><th class="num">Quantity</th><th>Reason</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse ($allocations as $a)
                <tr><td>{{ $a->line->description }}<span class="secondary">{{ $a->line->lot->number }}</span></td>
                    <td>@if ($a->project_id === $project->id) received from {{ $a->line->lot->project->name }} @else given to {{ $a->project->name }} @endif</td>
                    <td class="num">{{ $a->quantity }}</td><td>{{ $a->reason }}</td><td>{{ $a->creator?->name }}</td>
                    <td>@can('inventory.adjust')<form method="POST" action="{{ route('stock.allocations.cancel', $a) }}" class="inline-form" data-confirm="Cancel this allocation?">@csrf<button class="btn btn-sm btn-danger">Cancel</button></form>@endcan</td></tr>
            @empty
                <tr><td colspan="6" class="muted">No stock is shared with another project.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @can('inventory.adjust')
            @if ($homeLines->isNotEmpty() && $siblings->isNotEmpty())
            <form method="POST" action="{{ route('stock.allocations.store') }}" class="panel-body">@csrf
                <div class="form-grid">
                    <div class="field"><label>Lot line (free units)</label><select name="line_id" required>@foreach ($homeLines as $r)<option value="{{ $r['line']->id }}">{{ $r['line']->description }} · {{ $r['line']->lot->number }} ({{ $r['free'] }} free)</option>@endforeach</select></div>
                    <div class="field"><label>Give to project</label><select name="project_id" required>@foreach ($siblings as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                    <div class="field"><label>Quantity</label><input name="quantity" inputmode="decimal" required></div>
                    <div class="field grow"><label>Reason</label><input name="reason" maxlength="255" required></div>
                </div>
                <button class="btn btn-primary" style="margin-top:.7rem">Allocate</button>
            </form>
            @endif
        @endcan
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
