@use('App\Services\ClientPortalData')
@php
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $sum = ClientPortalData::stockBuckets($rows);
    $total = array_sum(array_map('floatval', $sum));
    $labels = ['available' => 'Available', 'reserved' => 'Reserved', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'returned' => 'Returned', 'damaged' => 'Damaged'];
@endphp
<x-portal-layout title="Products & Stock" subtitle="Where each of your products is right now: available, reserved, on its way, delivered, returned or damaged.">
    @if ($total > 0)
    <section class="pt-card" aria-label="Stock summary" style="margin-bottom:1.25rem"><div class="pt-card-body">
        <div class="pt-stack" role="img" aria-label="Stock by status">@foreach ($labels as $k => $label)@if ((float) $sum[$k] > 0)<i class="s-{{ $k }}" style="width: {{ round((float) $sum[$k] / $total * 100, 2) }}%" title="{{ $label }}"></i>@endif @endforeach</div>
        <div class="pt-legend">@foreach ($labels as $k => $label)<div><span class="pt-dot s-{{ $k }}"></span>{{ $label }}<b>{{ $qty($sum[$k]) }}</b></div>@endforeach</div>
    </div></section>
    @endif
    <section class="pt-card">
        <form method="GET" class="pt-filters" role="search">
            <div class="pt-field grow"><label for="q">Search product</label><input id="q" class="pt-input" type="search" name="q" value="{{ request('q') }}" placeholder="Product name"></div>
            <div class="pt-field"><label for="pj">Project</label><select id="pj" class="pt-input" name="project"><option value="">All projects</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected($selected?->id === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <button class="pt-btn pt-btn-primary">Filter</button> <a class="pt-btn" href="{{ route('portal.inventory') }}">Clear</a>
        </form>
        @if ($rows->isNotEmpty())
        <div class="pt-table-wrap"><table class="pt-table stack">
            <thead><tr><th>Product</th><th>Lot</th><th class="r">Sourced</th><th class="r">Received</th><th>Status</th></tr></thead>
            <tbody>@foreach ($rows as $r)@php($line = $r['line'])
                <tr><td class="lead"><strong>{{ $line->description }}</strong>@if ($r['ownership'] === 'client_custody') <small>Held for you</small>@endif @if ($line->product?->sku)<small class="pt-sku">SKU {{ $line->product->sku }}</small>@endif<small>{{ $r['project']->name }}</small>@include('portal._pills', ['b' => $r['b']])</td>
                    <td data-label="Lot">{{ $line->lot->number }}</td><td class="r num" data-label="Sourced">{{ $qty($line->quantity) }}</td><td class="r num" data-label="Received">{{ $qty($r['b']['received']) }}</td>
                    <td data-label="Status"><x-portal.status kind="stage" :value="ClientPortalData::lineStage((string) $r['b']['received'], $r['b'], $line->lot->status)" /></td></tr>
            @endforeach</tbody>
        </table></div>
        @else
            <x-portal.empty icon="package" title="No stock to show yet">Your products appear here once they arrive at Sellbees. Try clearing the filters if you were searching.</x-portal.empty>
        @endif
    </section>
</x-portal-layout>
