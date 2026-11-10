@use('App\Support\Money')
<x-portal-layout title="My Projects">
    <section class="panel"><div class="panel-head"><h2>My projects</h2></div>
        <form method="GET" class="filters"><div class="field"><label>Project</label><select name="project" onchange="this.form.submit()"><option value="">All projects</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected($selected?->id === $p->id)>{{ $p->name }}</option>@endforeach</select></div><noscript><button class="btn">Show</button></noscript></form>
        <div class="table-wrap"><table class="table"><thead><tr><th>Project</th><th>Status</th><th>Started</th></tr></thead><tbody>
        @forelse ($projects as $p)<tr><td>{{ $p->name }}</td><td>{{ $p->status->label() }}@if ($p->isArchived()) (archived)@endif</td><td>{{ $p->started_on?->format('j M Y') ?? '—' }}</td></tr>
        @empty<tr><td colspan="3" class="muted">You have no projects yet.</td></tr>@endforelse</tbody></table></div></section>
    @forelse ($lots as $l)
    <section class="panel"><div class="panel-head"><h2>Lot {{ $l['number'] }}</h2><span class="sub">{{ $l['project']?->name }} · status: {{ ucfirst(str_replace('_', ' ', $l['status'])) }}</span></div>
        <div class="table-wrap"><table class="table table-min-760"><thead><tr><th>Product</th><th class="num">Your price / unit</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Accepted</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Dispatched</th><th class="num">Delivered</th><th class="num">Returned</th><th class="num">Damaged</th></tr></thead><tbody>
        @foreach ($l['lines'] as $x)<tr><td>{{ $x['product'] }}</td><td class="num">{{ $x['client_price'] === null ? 'Pending approval' : Money::format($x['client_price']) }}</td><td class="num">{{ $x['ordered'] }}</td><td class="num">{{ $x['received'] ?? '—' }}</td><td class="num">{{ $x['accepted'] ?? '—' }}</td>
            @foreach (['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'] as $k)<td class="num">{{ $x['buckets'][$k] }}</td>@endforeach</tr>@endforeach</tbody></table></div></section>
    @empty
    <section class="panel"><div class="panel-body muted">No sourced lots yet. They appear here once Sellbees sources products for you.</div></section>
    @endforelse
</x-portal-layout>