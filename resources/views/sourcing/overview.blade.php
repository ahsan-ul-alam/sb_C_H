@use('App\Support\Money')
<x-layout title="Sourcing Overview">
    <x-page-header title="Suppliers & Sourcing">
        <x-slot:meta>Estimated, invoiced, received and paid amounts are kept apart on purpose. They are different measures and are never summed into one total.</x-slot:meta>
        @can('sourcing.manage')
        <x-slot:actions><a href="{{ route('sourcing.lots.create') }}" class="btn btn-primary"><x-icon name="plus" /> New lot</a></x-slot:actions>
        @endcan
    </x-page-header>

    <h2 class="metric-group-label">Activity</h2>
    <div class="metrics-auto">
        <div class="metric"><div class="label">Active suppliers</div><div class="value">{{ $cards['activeSuppliers'] }}</div></div>
        <div class="metric"><div class="label">Active sourcing lots</div><div class="value">{{ $cards['activeLots'] }}</div></div>
        <div class="metric"><div class="label">Total supplier purchase value</div><div class="value">{{ Money::format($cards['purchase']) }}</div></div>
        <div class="metric"><div class="label">Total additional costs</div><div class="value">{{ Money::format($cards['additional']) }}</div></div>
    </div>

    <h2 class="metric-group-label">Sales &amp; profit (accrual view)</h2>
    <div class="metrics-auto">
        <div class="metric"><div class="label">Client sales value (agreed)</div><div class="value">{{ Money::format($cards['billing']) }}</div></div>
        <div class="metric"><div class="label">Expected profit (at approval)</div><div class="value">{{ Money::format($cards['expectedProfit']) }}</div></div>
        <div class="metric"><div class="label">Actual profit (profitable lots)</div><div class="value text-success">{{ Money::format($cards['actualProfit']) }}</div></div>
        <div class="metric @if ($cards['actualLoss'] > 0) is-danger @endif"><div class="label">Actual loss (loss-making lots)</div><div class="value">{{ Money::format($cards['actualLoss']) }}</div></div>
    </div>

    <h2 class="metric-group-label">Cash position of sourcing</h2>
    <div class="metrics-auto">
        <div class="metric"><div class="label">Invoiced to clients</div><div class="value">{{ Money::format($cards['billed']) }}</div></div>
        <div class="metric"><div class="label">Received from clients</div><div class="value">{{ Money::format($cards['received']) }}</div></div>
        <div class="metric"><div class="label">Outstanding client receivables</div><div class="value">{{ Money::format($cards['receivable']) }}</div></div>
        <div class="metric @if ($cards['payables'] > 0) is-danger @endif"><div class="label">Outstanding supplier payables</div><div class="value">{{ Money::format($cards['payables']) }}</div></div>
        <div class="metric"><div class="label">Other costs unpaid</div><div class="value">{{ Money::format($cards['costsUnpaid']) }}</div></div>
    </div>

    <h2 class="metric-group-label">Emergency Fund (sourcing)</h2>
    <div class="metrics-auto">
        <div class="metric"><div class="label">Coverage requested (open)</div><div class="value">{{ Money::format($cards['fundRequested']) }}</div></div>
        <div class="metric"><div class="label">Coverage approved</div><div class="value">{{ Money::format($cards['fundApproved']) }}</div></div>
        <div class="metric"><div class="label">Coverage utilised</div><div class="value">{{ Money::format($cards['fundUtilised']) }}</div></div>
        <div class="metric"><div class="label">Emergency Reserve balance</div><div class="value">{{ Money::format($reserve) }}</div></div>
    </div>

    <h2 class="metric-group-label">Main Finance exposure</h2>
    <div class="metrics-auto">
        <div class="metric @if ($obligations['total'] > 0) is-danger @endif"><div class="label">Not yet eligible for profit allocation (payables + unpaid costs + pending)</div><div class="value">{{ Money::format($obligations['total']) }}</div></div>
        <div class="metric"><div class="label">Pending sourcing expenses (awaiting verification)</div><div class="value">{{ Money::format($pendingExpenses) }}</div></div>
        <div class="metric @if ($uncoveredLoss > 0) is-danger @endif"><div class="label">Remaining uncovered loss (after utilised coverage)</div><div class="value">{{ Money::format($uncoveredLoss) }}</div></div>
        <div class="metric"><div class="label">Coverage held, not yet applied to a partner loss</div><div class="value">{{ Money::format($heldCoverage) }}</div></div>
    </div>
    <p class="muted">Income is recognised when clients pay; sourcing costs reach the ledger only when paid and verified. Profit allocation is blocked while obligations are open, so cash received is not treated as distributable profit.
        @if ($heldCoverage > 0 && ! $coverageApproved) Held coverage will not reduce partner losses until the Board approves the resolution "{{ \App\Services\SourcingObligations::COVERAGE_RESOLUTION }}". @endif</p>

    @foreach (['Loss-making lots' => $lossLots, 'Recent lots' => $recent] as $heading => $set)
        <section class="panel">
            <div class="panel-head"><h2>{{ $heading }}</h2></div>
            <div class="table-wrap"><table class="table table-min-640">
                <thead><tr><th>Lot</th><th>Status</th><th class="num">Landed cost</th><th class="num">Sales value</th><th class="num">Profit / loss</th></tr></thead>
                <tbody>
                @forelse ($set as $lot)
                    @php($f = $figures[$lot->id])
                    <tr><td class="primary-cell"><a href="{{ route('sourcing.lots.show', $lot) }}">{{ $lot->number }}</a><span class="secondary">{{ $lot->title }}</span></td><td><x-status :value="$lot->status" /></td>
                        <td class="num">{{ Money::format($f['landed']) }}</td><td class="num">{{ Money::format($f['revenue']) }}</td><td class="num {{ $f['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::format($f['profit']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="muted">Nothing to show.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    @endforeach
</x-layout>
