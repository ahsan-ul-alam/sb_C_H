@use('App\Support\Money')
<x-layout title="Profit & Loss">
    <x-page-header title="Company Profit & Loss">
        <x-slot:meta>Income = client money received (ClientHub, net of advances and refunds). Expenses = Board-verified expenses, net of verified corrections. Months not yet closed are live figures.</x-slot:meta>
    </x-page-header>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Cumulative income</div><div class="value">{{ Money::format($totals['income']) }}</div></div>
        <div class="metric"><div class="label">Cumulative expenses</div><div class="value">{{ Money::format($totals['expenses']) }}</div></div>
        <div class="metric @if ($totals['net'] < 0) is-danger @endif"><div class="label">Cumulative net profit / (loss)</div><div class="value">{{ Money::format($totals['net']) }}</div></div>
    </div>

    <section class="panel">
        <div class="panel-head"><h2>Monthly and cumulative performance</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Month</th><th>State</th><th class="num">Income</th><th class="num">Expenses</th><th class="num">of which sourcing</th><th class="num">Net</th><th class="num">Cumulative</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr><td><a href="{{ $r['state'] === 'closed' || $r['state'] === 'verified' ? route('finance.periods.show', \App\Models\AccountingPeriod::firstWhere('month', $r['month'])) : route('finance.periods', ['month' => $r['month']]) }}">{{ $r['month'] }}</a></td>
                    <td><x-status :value="$r['state']" />@if ($r['pending'] > 0)<span class="secondary text-danger">{{ $r['pending'] }} unverified item(s)</span>@endif</td>
                    <td class="num">{{ Money::format($r['income']) }}</td><td class="num">{{ Money::format($r['expenses']) }}</td><td class="num muted">{{ Money::format($r['sourcing']) }}</td>
                    <td class="num {{ $r['net'] < 0 ? 'text-danger' : '' }}">{{ Money::format($r['net']) }}</td><td class="num strong">{{ Money::format($r['cumulative']) }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Allocation of profit</h2><span class="sub">20% Salary · 5% Office &amp; Growth · 25% Emergency · 50% Partners ({{ $codes->implode(' / ') }} by the policy profit ratio). Losses are shared equally after any applied Emergency coverage.</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Month</th><th>Basis</th><th class="num">Salary 20%</th><th class="num">Office 5%</th><th class="num">Emergency 25%</th><th class="num">Partner pool 50%</th>@foreach ($codes as $c)<th class="num">Partner {{ $c }}</th>@endforeach<th class="num">Loss charged to partners</th><th class="num">Coverage applied</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr><td>{{ $r['month'] }}</td><td class="muted">{{ $r['basis'] }}</td>
                    <td class="num">{{ Money::format($r['salary']) }}</td><td class="num">{{ Money::format($r['office']) }}</td><td class="num">{{ Money::format($r['emergency']) }}</td><td class="num strong">{{ Money::format($r['partner_pool']) }}</td>
                    @foreach ($codes as $c)<td class="num">{{ Money::format($r['partners'][$c] ?? 0) }}</td>@endforeach
                    <td class="num">{{ Money::format($r['loss_charge']) }}</td><td class="num">{{ Money::format($r['coverage']) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><td colspan="2">Total</td><td class="num">{{ Money::format($totals['salary']) }}</td><td class="num">{{ Money::format($totals['office']) }}</td><td class="num">{{ Money::format($totals['emergency']) }}</td><td class="num">{{ Money::format($totals['partner_pool']) }}</td>
                @foreach ($codes as $c)<td class="num">{{ Money::format((int) $rows->sum(fn ($r) => $r['partners'][$c] ?? 0)) }}</td>@endforeach<td class="num">{{ Money::format((int) $rows->sum('loss_charge')) }}</td><td class="num">{{ Money::format((int) $rows->sum('coverage')) }}</td></tr></tfoot>
        </table></div>
        <div class="panel-foot muted">"Preview" rows are not posted: the Board must verify the period, approve the allocation and the policy must be signed before anything reaches the ledger. Profit allocation is also held back while sourcing obligations are open.</div>
    </section>
</x-layout>
