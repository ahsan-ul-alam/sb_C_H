<x-portal-layout title="Products & Inventory">
    <section class="panel"><div class="panel-head"><h2>Products &amp; inventory</h2><span class="sub">Your own stock only</span></div>
        <form method="GET" class="filters"><div class="field grow"><label>Search product</label><input type="search" name="q" value="{{ request('q') }}"></div>
            <div class="field"><label>Project</label><select name="project"><option value="">All</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected($selected?->id === $p->id)>{{ $p->name }}</option>@endforeach</select></div><button class="btn">Filter</button></form>
        <div class="table-wrap"><table class="table table-min-760"><thead><tr><th>Product</th><th>Project</th><th class="num">Received</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Dispatched</th><th class="num">Delivered</th><th class="num">Returned</th><th class="num">Damaged</th></tr></thead><tbody>
        @forelse ($rows as $r)<tr><td>{{ $r['line']->description }}@if ($r['ownership'] === 'client_custody') <span class="muted">(held for you)</span>@endif</td><td>{{ $r['project']->name }}</td><td class="num">{{ $r['b']['received'] }}</td>
            @foreach (['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'] as $k)<td class="num">{{ $r['b'][$k] }}</td>@endforeach</tr>
        @empty<tr><td colspan="9" class="muted">No stock to show yet. Stock appears once your products arrive.</td></tr>@endforelse</tbody></table></div></section>
</x-portal-layout>