@use('App\Support\Money')
<x-layout title="Finance Dashboard">
    <x-page-header title="Finance Dashboard">
        <x-slot:meta>All amounts in {{ config('finance.currency') }}, taken from the posted journal and closed periods.</x-slot:meta>
    </x-page-header>

    @if ($policy && ($blocker = $policy->liveBlocker()))
        <div class="flash flash-error" role="alert"><x-icon name="alert" /> <div><strong>Live profit and loss posting is blocked.</strong> {{ $blocker }}</div></div>
    @endif

    <h2 class="metric-group-label">Financial summary</h2>
    <div class="metrics-auto">
        @foreach ($totals as $label => $amount)
            <div class="metric @if ($amount < 0) is-danger @endif"><div class="label">{{ $label }}</div><div class="value">{{ Money::format($amount) }}</div></div>
        @endforeach
        <div class="metric"><div class="label"><x-icon name="cash" class="icon-sm" /> Cash &amp; bank (ledger)</div><div class="value">{{ Money::format($cash) }}</div></div>
        <div class="metric"><div class="label">Customer advances (liability)</div><div class="value">{{ Money::format($advances) }}</div></div>
    </div>

    <h2 class="metric-group-label">Funds</h2>
    <div class="metrics-auto">
        @foreach ($funds as $label => $amount)
            <div class="metric"><div class="label">{{ $label }}</div><div class="value">{{ Money::format($amount) }}</div></div>
        @endforeach
        <div class="metric"><div class="label">Partners' Profit Fund (payable)</div><div class="value">{{ Money::format($partnerFund) }}</div></div>
    </div>

    <div class="grid grid-2">
        <section class="panel">
            <div class="panel-head"><h2>Partners</h2><a class="btn btn-sm" href="{{ route('finance.partners') }}">Manage partners</a></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th scope="col">Partner</th><th scope="col">Status</th><th scope="col" class="num">Paid capital</th><th scope="col" class="num">Profit payable</th></tr></thead>
                <tbody>
                @foreach ($partners as $row)
                    <tr><td><a href="{{ route('finance.partners.show', $row['partner']) }}">{{ $row['partner']->code }} · {{ $row['partner']->name }}</a></td><td><x-status :value="$row['partner']->status" /></td>
                        <td class="num">{{ Money::format($row['capital']) }}</td><td class="num">{{ Money::format($row['payable']) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Waiting for action</h2></div>
            <div class="table-wrap"><table class="table"><tbody>
                @foreach ($pending as $label => $count)
                    <tr><td>{{ $label }}</td><td class="num strong">@if ($count > 0)<span class="badge badge-partial">{{ $count }}</span>@else<span class="muted">0</span>@endif</td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-head"><h2>Recent periods</h2><a class="btn btn-sm" href="{{ route('finance.periods') }}">All periods</a></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th scope="col">Month</th><th scope="col" class="num">Income</th><th scope="col" class="num">Expenses</th><th scope="col" class="num">Net profit</th><th scope="col">Status</th><th scope="col">Allocation</th></tr></thead>
            <tbody>
            @forelse ($periods as $p)
                <tr><td><a href="{{ route('finance.periods.show', $p) }}">{{ $p->month }}</a></td>
                    <td class="num">{{ Money::format($p->income) }}</td><td class="num">{{ Money::format($p->expenses) }}</td>
                    <td class="num strong">{{ Money::format($p->net_profit) }}</td><td><x-status :value="$p->status" /></td>
                    <td>@if ($p->allocation)<x-status :value="$p->allocation->status" />@else<span class="muted">Not prepared</span>@endif</td></tr>
            @empty
                <tr><td colspan="6" class="muted">No closed periods yet. Close the first finished month from Periods &amp; Profit.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Recent activity</h2><a class="btn btn-sm" href="{{ route('finance.audit') }}">Audit log</a></div>
        <div class="table-wrap"><table class="table table-min-640">
            <tbody>
            @forelse ($recentAudit as $log)
                <tr><td class="nowrap">{{ $log->created_at->format('j M, H:i') }}</td><td>{{ $log->user?->email ?? 'system' }}</td><td>{{ $log->action }}</td><td class="muted">{{ $log->subject }}</td></tr>
            @empty
                <tr><td class="muted">No activity yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
