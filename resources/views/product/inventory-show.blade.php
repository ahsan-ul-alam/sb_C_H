@use('App\Support\Money')
<x-layout :title="$line->description">
    <x-page-header :title="$line->description" :crumbs="[['Inventory', route('product.inventory')], [$line->lot->number, null]]">
        <x-slot:meta>Lot <a href="{{ route('sourcing.lots.show', $line->lot) }}">{{ $line->lot->number }}</a> · {{ $line->supplier->name }} · client {{ $line->lot->project?->client?->name ?? 'not set' }}</x-slot:meta>
    </x-page-header>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Ordered</div><div class="value">{{ $line->quantity }}</div></div>
        <div class="metric"><div class="label">Received / accepted</div><div class="value">{{ $line->received_quantity }} / {{ $b['received'] }}</div></div>
        <div class="metric"><div class="label">Missing / rejected at arrival</div><div class="value">{{ bcsub((string) $line->quantity, (string) $line->received_quantity, 2) }} / {{ bcsub((string) $line->received_quantity, (string) $line->usable_quantity, 2) }}</div></div>
        <div class="metric"><div class="label">Available</div><div class="value">{{ $b['available'] }}</div></div>
        <div class="metric"><div class="label">Reserved · Dispatched</div><div class="value">{{ $b['reserved'] }} · {{ $b['dispatched'] }}</div></div>
        <div class="metric"><div class="label">Delivered · Returned · Damaged</div><div class="value">{{ $b['delivered'] }} · {{ $b['returned'] }} · {{ $b['damaged'] }}</div></div>
    </div>
    <p class="muted">Stock check:
        @if ($reconciles)
            <span class="badge badge-paid">balances add up to the movements</span>
        @else
            <span class="badge badge-unpaid">does not reconcile — investigate</span>
        @endif
    </p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:1rem">
        <section class="panel"><div class="panel-head"><h2>Ownership</h2></div><div class="panel-body">
            @if ($line->lot->ownership)
                <p>{{ $line->lot->ownership === 'client' ? 'Client-owned: SellBees sells it for the client and settles the proceeds to them.' : 'SellBees-owned: sales accounting for company stock is not enabled yet.' }}</p>
            @else
                <p class="muted">Not stated. Orders and settlements need to know whose stock this is. It cannot be changed once chosen.</p>
                @can('sourcing.manage')
                <form method="POST" action="{{ route('product.lots.ownership', $line->lot) }}" class="inline-form">@csrf
                    <select name="ownership"><option value="client">Client-owned (client paid for it)</option><option value="company">SellBees-owned</option></select><button class="btn btn-primary">Save</button></form>
                @endcan
            @endif
        </div></section>
        <section class="panel"><div class="panel-head"><h2>Storage</h2></div><div class="panel-body">
            <p>Occupied area: <strong>{{ $line->lot->storage_sqft ? $line->lot->storage_sqft.' sq ft' : 'not measured' }}</strong>
                @if ($line->lot->storage_measured_on)<span class="muted">(measured {{ $line->lot->storage_measured_on->format('j M Y') }})</span>@endif</p>
            <p class="muted">Storage is charged monthly only while more than {{ $settings->storage_threshold }} units of the lot are on hand, and only once the Board has approved it. Update the area when the footprint really changes.</p>
            @can('sourcing.manage')
            <form method="POST" action="{{ route('product.lots.area', $line->lot) }}" class="inline-form">@csrf<input name="storage_sqft" size="8" inputmode="decimal" placeholder="sq ft" required><button class="btn">Save area</button></form>
            @endcan
        </div></section>
        @can('inventory.adjust')
        <section class="panel"><div class="panel-head"><h2>Stock-take adjustment</h2></div><div class="panel-body">
            <form method="POST" action="{{ route('product.inventory.adjust', $line) }}" class="inline-form">@csrf
                <input name="delta" size="7" inputmode="decimal" placeholder="+5 / -3" required><input name="reason" size="24" placeholder="reason (required)" required>
                <label class="check"><input type="checkbox" name="damaged" value="1"> write off as damaged</label><button class="btn">Record</button></form>
        </div></section>
        @endcan
    </div>

    @if ($charges->isNotEmpty())
    <section class="panel"><div class="panel-head"><h2>Storage charges</h2></div><div class="table-wrap"><table class="table">
        <thead><tr><th>Month</th><th class="num">Units</th><th class="num">Sq ft</th><th class="num">Rate</th><th class="num">Charge</th><th>Status</th></tr></thead>
        <tbody>
        @foreach ($charges as $c)
            <tr><td>{{ $c->period }}</td><td class="num">{{ $c->quantity }}</td><td class="num">{{ $c->sqft ?? '—' }}</td><td class="num">{{ Money::format($c->rate) }}</td><td class="num">{{ Money::format($c->amount) }}</td>
                <td>
                    @if ($c->status === 'needs_measurement')
                        <span class="badge badge-partial">Needs measurement</span>
                    @elseif ($c->invoice)
                        <a href="{{ route('invoices.show', $c->invoice) }}">{{ $c->invoice->number }}</a> — balance {{ Money::format($c->invoice->balance()) }}
                    @endif
                </td></tr>
        @endforeach
        </tbody>
    </table></div></section>
    @endif

    <section class="panel"><div class="panel-head"><h2>Stock movements</h2><span class="sub">History is never edited; corrections are new movements.</span></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Date</th><th>Movement</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Dispatched</th><th class="num">Delivered</th><th class="num">Returned</th><th class="num">Damaged</th><th>Note</th></tr></thead>
        <tbody>
        @forelse ($movements as $m)
            <tr><td class="nowrap">{{ $m->occurred_on->format('j M Y') }}</td><td>{{ ucfirst($m->type) }}</td>
                @foreach (['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'] as $k)<td class="num">{{ bccomp((string) $m->{'d_'.$k}, '0', 2) === 0 ? '' : $m->{'d_'.$k} }}</td>@endforeach
                <td>{{ $m->reason }} {{ $m->creator ? '· '.$m->creator->name : '' }}</td></tr>
        @empty
            <tr><td colspan="9" class="muted">No movements.</td></tr>
        @endforelse
        </tbody>
    </table></div></section>
</x-layout>
