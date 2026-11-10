{{-- Pending delivery and return exceptions (SalesAnalytics::exceptions). Needs $exceptions. --}}
<section class="panel">
    <div class="panel-head"><h2>Exceptions to chase</h2><span class="sub">dispatched {{ \App\Services\SalesAnalytics::PENDING_DELIVERY_DAYS }}+ days ago and not delivered, and returned units still waiting for inspection</span></div>
    <div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>Order</th><th>Issue</th><th>Detail</th></tr></thead>
        <tbody>
        @foreach ($exceptions['overdue'] as $r)
            <tr><td>{{ $r['order']->reference }}<span class="secondary">{{ $r['order']->project->name ?? '' }}</span></td><td><span class="badge badge-unpaid">Pending delivery</span></td><td>Dispatched {{ $r['days'] }} days ago @if ($r['order']->courier) · {{ $r['order']->courier }} {{ $r['order']->tracking_reference }}@endif</td></tr>
        @endforeach
        @foreach ($exceptions['awaiting_inspection'] as $r)
            <tr><td>{{ $r['order']->reference }}<span class="secondary">{{ $r['order']->project->name ?? '' }}</span></td><td><span class="badge badge-partial">Return awaiting inspection</span></td><td>{{ $r['quantity'] }} × {{ $r['item']->description }} {{ $r['order']->delivery_status === 'failed' ? '(failed delivery)' : '(customer return)' }}</td></tr>
        @endforeach
        @if ($exceptions['overdue'] === [] && $exceptions['awaiting_inspection'] === [])
            <tr><td colspan="3" class="muted">Nothing to chase.</td></tr>
        @endif
        </tbody>
    </table></div>
</section>