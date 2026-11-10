@use('App\Support\Money')
@php
    $m = fn (int $v) => Money::format($v, config('finance.currency'));
@endphp
@use('App\Services\ClientPortalData')
@php
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
@endphp
@if ($detail)
@php
    $p = $detail['project'];
    $pending = $detail['cost_pending'];
    $b = $detail['stock'];
    $stockTotal = array_sum(array_map('floatval', $b));
    $stockLabels = ['available' => 'Available', 'reserved' => 'Reserved', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'returned' => 'Returned', 'damaged' => 'Damaged'];
    $c = $detail['counts'];
    $invPending = $detail['investment'] === 0 && $detail['awaiting_lots'] > 0;
    $awaitUnits = $detail['stock_awaiting']['available'];
@endphp
<x-portal-layout :title="$p->name" :subtitle="'Started '.($p->started_on?->format('j M Y') ?? '—').' · '.$p->status->label().($p->isArchived() ? ' (archived)' : '')" :crumbs="[['My Projects', route('portal.projects')], [$p->name, null]]">
    <nav class="pt-tabs" aria-label="Project sections"><a href="#overview">Overview</a><a href="#lots">Sourcing lots</a><a href="#stock">Products &amp; stock</a><a href="#orders">Orders</a><a href="#finance">Financial summary</a></nav>

    <section id="overview" aria-label="Project overview">
        <div class="pt-grid pt-g4">
            <x-portal.kpi tone="primary" :pending="$invPending" icon="wallet" label="Approved product investment" :value="$invPending ? 'Pending verification' : $m($detail['investment'])" :hint="$invPending ? 'Sourced lot not yet verified: see its status below' : 'Approved client prices, billed lots only'" />
            <x-portal.kpi tone="primary" icon="trending-up" label="Successful sales" :value="$m($detail['gross_sales'])" :hint="$qty($detail['units_sold']).' units delivered, not returned'" />
            <x-portal.kpi tone="primary" :pending="$pending" icon="chart" label="Your net profit" :value="$pending ? 'Pending verification' : $m($detail['client_profit'])" :hint="$pending ? 'Product cost not yet verified' : 'After your product cost, once'" />
            <x-portal.kpi tone="primary" icon="package" label="Stock available" :value="$qty($b['available'])" :hint="bccomp((string) $awaitUnits, '0', 2) > 0 ? 'units, plus '.$qty($awaitUnits).' awaiting ownership confirmation' : 'units ready to order'" />
        </div>
    </section>

    <section class="pt-card" id="lots" aria-labelledby="h-lots">
        <div class="pt-card-head"><h2 id="h-lots">Sourcing lots</h2><span class="sub">{{ count($detail['lots']) }} {{ \Illuminate\Support\Str::plural('lot', count($detail['lots'])) }}</span></div>
        @forelse ($detail['lots'] as $l)
            <div style="padding:1rem 1.25rem .25rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap"><strong>Lot {{ $l['number'] }}</strong><x-portal.status kind="lot" :value="$l['status_label']" />@unless ($l['ownership_confirmed'])<span class="pt-chip warn">Ownership being confirmed</span>@endunless @unless ($l['billed'])<span class="pt-chip warn">Not yet billed against this lot</span>@endunless</div>
            <div class="pt-table-wrap"><table class="pt-table stack">
                <thead><tr><th>Product</th><th class="r">Your price / unit</th><th class="r">Sourced</th><th class="r">Received</th><th class="r">Accepted</th><th>Status</th></tr></thead>
                <tbody>@foreach ($l['lines'] as $x)
                    <tr><td class="lead"><strong>{{ $x['product'] }}</strong>@if ($x['sku'])<small class="pt-sku">SKU {{ $x['sku'] }}</small>@endif @include('portal._pills', ['b' => $x['buckets']])</td>
                        <td class="r num" data-label="Your price / unit">{{ $x['client_price'] === null ? 'Pending approval' : $m($x['client_price']) }}</td>
                        <td class="r num" data-label="Sourced">{{ $qty($x['ordered']) }}</td><td class="r num" data-label="Received">{{ $x['received'] === null ? '—' : $qty($x['received']) }}</td><td class="r num" data-label="Accepted">{{ $x['accepted'] === null ? '—' : $qty($x['accepted']) }}</td>
                        <td data-label="Status"><x-portal.status kind="stage" :value="$x['stage']" /></td></tr>
                @endforeach</tbody>
            </table></div>
        @empty
            <x-portal.empty icon="package" title="No sourcing lots yet">When Sellbees sources products for this project, each lot and its products will be listed here.</x-portal.empty>
        @endforelse
    </section>

    <section class="pt-card" id="stock" aria-labelledby="h-stock" style="margin-top:1.25rem">
        <div class="pt-card-head"><h2 id="h-stock">Products &amp; stock</h2><a class="pt-link" href="{{ route('portal.inventory', ['project' => $p->id]) }}">Open stock list</a></div>
        <div class="pt-card-body">
            @if ($stockTotal > 0)
                <div class="pt-stack" role="img" aria-label="Stock by status">@foreach ($stockLabels as $k => $label)@if ((float) $b[$k] > 0)<i class="s-{{ $k }}" style="width: {{ round((float) $b[$k] / $stockTotal * 100, 2) }}%" title="{{ $label }}"></i>@endif @endforeach</div>
                <div class="pt-legend">@foreach ($stockLabels as $k => $label)<div><span class="pt-dot s-{{ $k }}"></span>{{ $label }}<b>{{ $qty($b[$k]) }}</b></div>@endforeach</div>
            @else
                <x-portal.empty icon="package" title="No stock received yet">Stock for this project appears here once your products arrive.</x-portal.empty>
            @endif
        </div>
    </section>

    <section class="pt-card" id="orders" aria-labelledby="h-ord" style="margin-top:1.25rem">
        <div class="pt-card-head"><h2 id="h-ord">Order performance</h2><a class="pt-link" href="{{ route('portal.orders', ['project' => $p->id]) }}">All orders for this project</a></div>
        <div class="pt-card-body" style="border-bottom:1px solid var(--border)"><div class="pt-g5 pt-grid keep2" style="margin:0">
            <x-portal.kpi tone="sm" label="Orders" :value="$c['total']" /><x-portal.kpi tone="sm" label="Delivered" :value="$c['delivered']" /><x-portal.kpi tone="sm" label="In progress" :value="$c['pending_deliveries']" /><x-portal.kpi tone="sm" label="Failed" :value="$c['failed']" /><x-portal.kpi tone="sm" label="With returns" :value="$c['with_returns']" />
        </div></div>
        @if ($detail['recent_orders']->isNotEmpty())
        <div class="pt-table-wrap"><table class="pt-table stack"><thead><tr><th>Order</th><th>Products</th><th>Status</th><th class="r">Your entitlement</th></tr></thead><tbody>
            @foreach ($detail['recent_orders'] as $r)@php($ord = $r['order'])<tr><td class="lead"><strong>{{ $ord->reference }}</strong><small>{{ $ord->ordered_on->format('j M Y') }}</small></td><td data-label="Products">{{ $r['products'] }}</td><td data-label="Status"><x-portal.status kind="order" :value="$ord->fulfilmentLabel()" /></td><td class="r num" data-label="Your entitlement">{{ $m($r['entitlement']) }}</td></tr>@endforeach
        </tbody></table></div>
        @else
            <x-portal.empty icon="truck" title="No orders yet">Orders for this project will be tracked here.</x-portal.empty>
        @endif
    </section>

    <section class="pt-card" id="finance" aria-labelledby="h-fin" style="margin-top:1.25rem">
        <div class="pt-card-head"><h2 id="h-fin">Project financial summary</h2><span class="sub">This project only. Settlement is across all your projects.</span></div>
        <div class="pt-card-body"><dl class="pt-dl" style="grid-template-columns: 1fr auto">
            <dt>Product investment</dt><dd class="num" style="text-align:right">{{ $m($detail['investment']) }}</dd>
            <dt>Successful sales</dt><dd class="num" style="text-align:right">{{ $m($detail['gross_sales']) }}</dd>
            <dt>Delivery charges</dt><dd class="num" style="text-align:right">{{ $m($detail['delivery_charges']) }}</dd>
            <dt>Sellbees commission</dt><dd class="num" style="text-align:right">{{ $m($detail['commission']) }}</dd>
            <dt>Your settlement entitlement</dt><dd class="num" style="text-align:right">{{ $m($detail['entitlement']) }}</dd>
            <dt>Your product cost of units sold</dt><dd class="num" style="text-align:right">{{ $pending ? 'Pending verification' : $m($detail['cost_of_sold']) }}</dd>
            <dt><strong>Your net product profit</strong></dt><dd class="num" style="text-align:right"><strong>{{ $pending ? 'Pending verification' : $m($detail['client_profit']) }}</strong></dd>
        </dl>
        @foreach ($detail['notes'] as $n)<p class="pt-note" style="margin-top:.75rem">{{ $n }}</p>@endforeach
        </div>
    </section>
</x-portal-layout>
@else
<x-portal-layout title="My Projects" subtitle="Each project shows what you invested, what has sold and what is still in stock.">
    @if ($selected === null && request('project'))<div class="pt-flash info">That project was not found among your projects.</div>@endif
    @if (count($cards))
    <div class="pt-grid pt-g3">
        @foreach ($cards as $p)
        <a class="pt-card pt-proj" href="{{ route('portal.projects', ['project' => $p['project']->id]) }}">
            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start"><h3>{{ $p['project']->name }}</h3><x-portal.status kind="project" :value="$p['project']->status->label()" /></div>
            <div class="pt-mini">
                <div><span>Investment</span><b>{{ $p['investment'] === 0 && $p['awaiting_lots'] > 0 ? 'Pending' : $m($p['investment']) }}</b></div>
                <div><span>Successful sales</span><b>{{ $m($p['gross_sales']) }}</b></div>
                <div><span>Net profit</span><b>{{ $p['cost_pending'] ? 'Pending' : $m($p['client_profit']) }}</b></div>
                <div><span>Stock available</span><b>{{ $qty($p['stock']['available']) }}</b></div>
            </div>
            <span class="pt-link" style="margin-top:auto">View project →</span>
        </a>
        @endforeach
    </div>
    @else
    <section class="pt-card"><x-portal.empty icon="folder" title="No projects yet">Your projects will appear here as soon as Sellbees sets them up for you.</x-portal.empty></section>
    @endif
</x-portal-layout>
@endif
