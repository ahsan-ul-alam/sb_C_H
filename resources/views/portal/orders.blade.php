@use('App\Support\Money')
@php
    $m = fn (int $v) => Money::format($v, config('finance.currency'));
@endphp
@php
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $keep = fn (array $extra) => array_filter(array_merge(request()->only('q', 'project'), $extra), fn ($v) => $v !== null && $v !== '');
    $chips = ['' => ['All', $counts['total']], 'confirmed' => ['Confirmed', $counts['confirmed']], 'packed' => ['Packed', $counts['packed']], 'dispatched' => ['Dispatched', $counts['dispatched']], 'delivered' => ['Delivered', $counts['delivered']], 'failed' => ['Failed', $counts['failed']], 'cancelled' => ['Cancelled', $counts['cancelled']]];
@endphp
<x-portal-layout title="Orders & Delivery" subtitle="Track every order from confirmation to delivery. Commission and your entitlement are worked out only on what was really delivered.">
    <section class="pt-card">
        <div class="pt-seg" aria-label="Filter by status">@foreach ($chips as $key => [$label, $n])<a href="{{ route('portal.orders', $keep(['status' => $key])) }}" @if ((string) request('status') === (string) $key) aria-current="true" @endif>{{ $label }}<b>{{ $n }}</b></a>@endforeach</div>
        <form method="GET" class="pt-filters" role="search">
            @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
            <div class="pt-field grow"><label for="q">Search order, tracking or product</label><input id="q" class="pt-input" type="search" name="q" value="{{ request('q') }}" placeholder="e.g. ORD-1042"></div>
            <div class="pt-field"><label for="pj">Project</label><select id="pj" class="pt-input" name="project"><option value="">All projects</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected(request('project') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <button class="pt-btn pt-btn-primary">Search</button> <a class="pt-btn" href="{{ route('portal.orders') }}">Clear</a>
        </form>
        @if ($orders->count())
        <div class="pt-table-wrap"><table class="pt-table stack">
            <thead><tr><th>Order</th><th>Products</th><th>Status</th><th>Courier / tracking</th><th class="r">Delivered units</th><th class="r">Selling amount</th><th class="r">Delivery charge</th><th class="r">Commission</th><th class="r">Your entitlement</th><th>Collection</th></tr></thead>
            <tbody>@foreach ($orders as $r)@php($o = $r['order'])
                <tr><td class="lead"><strong>{{ $o->reference }}</strong><small>{{ $o->ordered_on->format('j M Y') }} · {{ $o->project->name }}</small></td>
                    <td data-label="Products">{{ $r['products'] }}</td>
                    <td data-label="Status"><div><x-portal.status kind="order" :value="$o->fulfilmentLabel()" />@if ($r['return_status'] !== '—')<small>{{ $r['return_status'] }}</small>@endif</div></td>
                    <td data-label="Courier / tracking"><div>{{ $o->courier ?: '—' }}@if ($o->tracking_reference)<small>{{ $o->tracking_reference }}</small>@endif</div></td>
                    <td class="r num" data-label="Delivered units">{{ $qty($r['delivered_units']) }}</td>
                    <td class="r num" data-label="Selling amount"><div>{{ $m($o->delivery_status === 'delivered' ? $r['sales'] : $o->orderedValue()) }}@if ($o->delivery_status !== 'delivered')<small>ordered</small>@endif</div></td>
                    <td class="r num" data-label="Delivery charge">{{ $m($r['delivery_charge']) }}</td><td class="r num" data-label="Commission">{{ $m($r['commission']) }}</td><td class="r num" data-label="Your entitlement"><strong>{{ $m($r['entitlement']) }}</strong></td>
                    <td data-label="Collection"><x-portal.status kind="collection" :value="$r['collection']" /></td></tr>
            @endforeach</tbody>
        </table></div>
        @include('portal._pager', ['paginator' => $orders])
        @else
            <x-portal.empty icon="truck" title="No orders match">Orders appear here once Sellbees records them for your projects. If you were searching, try clearing the filters.</x-portal.empty>
        @endif
    </section>
</x-portal-layout>
