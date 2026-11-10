@use('App\Support\Money')
@php($t = $e['total'])
@php($w = $e['client_wide'])
<x-portal-layout title="Sales & Profit">
    <section class="panel"><div class="panel-head"><h2>Sales &amp; profit by project</h2><span class="sub">Your product cost is deducted once, in the profit; it is never deducted again from what is paid to you.</span></div>
        <div class="table-wrap"><table class="table table-min-760"><thead><tr><th>Project</th><th class="num">Units sold</th><th class="num">Sales</th><th class="num">Delivery charges</th><th class="num">Commission</th><th class="num">Your entitlement</th><th class="num">Your product investment</th><th class="num">Your product cost sold</th><th class="num">Your net product profit</th></tr></thead><tbody>
        @forelse ($e['projects'] as $r)<tr><td>{{ $r['project']->name }}</td><td class="num">{{ $r['units_sold'] }}</td><td class="num">{{ Money::format($r['gross_sales']) }}</td><td class="num">{{ Money::format($r['delivery_charges']) }}</td><td class="num">{{ Money::format($r['commission']) }}</td><td class="num">{{ Money::format($r['entitlement']) }}</td><td class="num">{{ Money::format($r['investment']) }}</td>
            <td class="num">{{ $r['cost_pending'] ? 'Pending verification' : Money::format($r['cost_of_sold']) }}</td><td class="num strong">{{ $r['cost_pending'] ? 'Pending verification' : Money::format($r['client_profit']) }}</td></tr>
        @empty<tr><td colspan="9" class="muted">No sales yet.</td></tr>@endforelse</tbody>
        @if (count($e['projects']) > 1)<tfoot><tr><th>All projects</th><th></th><th class="num">{{ Money::format($t['gross_sales']) }}</th><th class="num">{{ Money::format($t['delivery_charges']) }}</th><th class="num">{{ Money::format($t['commission']) }}</th><th class="num">{{ Money::format($t['entitlement']) }}</th><th class="num">{{ Money::format($t['investment']) }}</th><th class="num">{{ $t['pending'] ? 'Pending' : Money::format($t['cost_of_sold']) }}</th><th class="num">{{ $t['pending'] ? 'Pending' : Money::format($t['client_profit']) }}</th></tr></tfoot>@endif
        </table></div></section>
    <section class="panel"><div class="panel-head"><h2>Settlement — all your projects together</h2><span class="sub">Settlements are not tied to a single project, so these figures are not split by project.</span></div><div class="panel-body"><div class="stat-grid">
        <div class="stat"><span class="stat-label">Entitlement earned (all projects)</span><span class="stat-value">{{ Money::format($w['entitlement']) }}</span></div>
        <div class="stat"><span class="stat-label">Settled to you</span><span class="stat-value">{{ Money::format($w['settled']) }}</span></div>
        <div class="stat"><span class="stat-label">Still owed to you</span><span class="stat-value">{{ Money::format($w['outstanding']) }}</span></div>
        <div class="stat"><span class="stat-label">Cash collected and held for you</span><span class="stat-value">{{ Money::format($w['cash_available']) }}</span></div>
    </div></div></section>
    @foreach ($e['projects'] as $r)@foreach ($r['notes'] as $n)<p class="muted">{{ $r['project']->name }}: {{ $n }}</p>@endforeach @endforeach
</x-portal-layout>