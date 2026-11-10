@use('App\Support\Money')
@php
    $m = fn (int $v) => Money::format($v, config('finance.currency'));
@endphp
@php
    $t = $e['total'];
    $w = $e['client_wide'];
    $pending = (bool) $t['pending'];
    $invPending = $t['investment'] === 0 && $billing['awaiting_count'] > 0;
    $months = array_slice(array_reverse($monthly, true), -12, null, true);
    $maxSales = max(1, ...array_values(array_map(fn ($m) => $m['sales'], $months ?: [['sales' => 1]])));
@endphp
<x-portal-layout title="Sales & Profit" subtitle="Your investment, what has sold and what you earned. Your product cost is deducted once, in the profit, and never again from what is paid to you.">
    <div class="pt-grid pt-g4">
        <x-portal.kpi tone="primary" :pending="$invPending" icon="wallet" label="Approved product investment" :value="$invPending ? 'Pending verification' : $m($t['investment'])" :hint="$invPending ? 'Sourced lots not yet verified' : 'Your approved cost of the products sourced'" />
        <x-portal.kpi tone="primary" icon="trending-up" label="Investment recovered" :value="$pending ? 'Pending verification' : $m($t['investment_recovered'])" :pending="$pending" hint="Recovered through sales" />
        <x-portal.kpi tone="primary" icon="cash" label="Settlement entitlement" :value="$m($t['entitlement'])" hint="Owed to you from sales" />
        <x-portal.kpi tone="primary" icon="chart" label="Net product profit" :value="$pending ? 'Pending verification' : $m($t['client_profit'])" :pending="$pending" hint="After your product cost" />
    </div>

    @include('portal._billing', ['billing' => $billing, 'fin' => $fin])

    <div class="pt-grid pt-g21">
        <section class="pt-card" aria-labelledby="h-wf">
            <div class="pt-card-head"><h2 id="h-wf">How your result is made up</h2><span class="sub">All projects together</span></div>
            <div class="pt-card-body"><dl class="pt-dl" style="grid-template-columns: 1fr auto; gap:.7rem 1rem">
                <dt>Successful sales</dt><dd class="num" style="text-align:right">{{ $m($t['gross_sales']) }}</dd>
                <dt>Delivery charges (paid by you, once)</dt><dd class="num" style="text-align:right">{{ $m($t['delivery_charges']) }}</dd>
                <dt>Sellbees commission</dt><dd class="num" style="text-align:right">{{ $m($t['commission']) }}</dd>
                <dt><strong>Your settlement entitlement</strong></dt><dd class="num" style="text-align:right"><strong>{{ $m($t['entitlement']) }}</strong></dd>
                <dt>Your product cost of units sold</dt><dd class="num" style="text-align:right">{{ $pending ? 'Pending verification' : $m($t['cost_of_sold']) }}</dd>
                <dt><strong>Your net product profit</strong></dt><dd class="num" style="text-align:right"><strong>{{ $pending ? 'Pending verification' : $m($t['client_profit']) }}</strong></dd>
            </dl></div>
        </section>
        <section class="pt-card" aria-labelledby="h-set">
            <div class="pt-card-head"><h2 id="h-set">Settlement — all your projects together</h2></div>
            <div class="pt-card-body"><dl class="pt-dl" style="grid-template-columns: 1fr auto; gap:.7rem 1rem">
                <dt>Entitlement earned</dt><dd class="num" style="text-align:right">{{ $m($w['entitlement']) }}</dd>
                <dt>Settled to you</dt><dd class="num" style="text-align:right">{{ $m($w['settled']) }}</dd>
                <dt>Still owed to you</dt><dd class="num" style="text-align:right">{{ $m($w['outstanding']) }}</dd>
                <dt>Cash collected and held for you</dt><dd class="num" style="text-align:right">{{ $m($w['cash_available']) }}</dd>
            </dl></div>
            <div class="pt-card-foot">Settlements are not tied to a single project, so these figures are not split by project.</div>
        </section>
    </div>

    @if (count($months) >= 2)
    <section class="pt-card" aria-labelledby="h-trend" style="margin-bottom:1.25rem">
        <div class="pt-card-head"><h2 id="h-trend">Successful sales by month</h2><span class="sub">By delivery date</span></div>
        <div class="pt-card-body"><div class="pt-bars" role="img" aria-label="Successful sales by month">
            @foreach ($months as $m => $row)<div><b>{{ $m($row['sales']) }}</b><i style="height: {{ max(3, round($row['sales'] / $maxSales * 100)) }}%"></i><span>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('M y') }}</span></div>@endforeach
        </div></div>
    </section>
    @endif

    <section class="pt-card" aria-labelledby="h-by">
        <div class="pt-card-head"><h2 id="h-by">By project</h2></div>
        @if (count($e['projects']))
        <div class="pt-table-wrap"><table class="pt-table stack">
            <thead><tr><th>Project</th><th class="r">Sales</th><th class="r">Delivery charges</th><th class="r">Commission</th><th class="r">Your entitlement</th><th class="r">Product investment</th><th class="r">Net profit</th></tr></thead>
            <tbody>@foreach ($e['projects'] as $r)
                <tr><td class="lead"><a class="pt-link" href="{{ route('portal.projects', ['project' => $r['project']->id]) }}">{{ $r['project']->name }}</a></td>
                    <td class="r num" data-label="Sales">{{ $m($r['gross_sales']) }}</td><td class="r num" data-label="Delivery charges">{{ $m($r['delivery_charges']) }}</td>
                    <td class="r num" data-label="Commission">{{ $m($r['commission']) }}</td><td class="r num" data-label="Your entitlement">{{ $m($r['entitlement']) }}</td><td class="r num" data-label="Product investment">{{ $m($r['investment']) }}</td><td class="r num" data-label="Net profit"><strong>{{ $r['cost_pending'] ? 'Pending verification' : $m($r['client_profit']) }}</strong></td></tr>
            @endforeach</tbody>
            @if (count($e['projects']) > 1)<tfoot><tr><td>All projects</td><td class="r num">{{ $m($t['gross_sales']) }}</td><td class="r num">{{ $m($t['delivery_charges']) }}</td><td class="r num">{{ $m($t['commission']) }}</td><td class="r num">{{ $m($t['entitlement']) }}</td><td class="r num">{{ $m($t['investment']) }}</td><td class="r num">{{ $pending ? 'Pending' : $m($t['client_profit']) }}</td></tr></tfoot>@endif
        </table></div>
        @else
            <x-portal.empty icon="trending-up" title="No sales yet">Your sales and profit appear here as soon as your first orders are delivered.</x-portal.empty>
        @endif
    </section>
    @foreach ($e['projects'] as $r)@foreach ($r['notes'] as $n)<p class="pt-note" style="margin-top:.75rem">{{ $r['project']->name }}: {{ $n }}</p>@endforeach @endforeach
</x-portal-layout>
