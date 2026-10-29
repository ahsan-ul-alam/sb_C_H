@use('App\Support\Money')
<x-layout title="Periods & Profit">
    <x-page-header title="Closed Periods & Net Profit" />

    @can('period.close')
    <section class="panel">
        <div class="panel-head"><h2>Close a month</h2></div>
        <div class="panel-body">
            <form method="GET" action="{{ route('finance.periods') }}" style="display:flex; gap:.5rem; align-items:end; flex-wrap:wrap">
                <div class="field"><label>Month</label><input type="month" name="month" value="{{ $month }}"></div>
                <button class="btn">Check figures</button>
            </form>
            <p class="muted" style="margin-top:.75rem">Collected {{ Money::format($draft['collected']) }} − new advances {{ Money::format($draft['advances']) }} + advances recognised {{ Money::format($draft['recognised']) }} − refunds {{ Money::format($draft['refund_revenue']) }}</p>
            <p style="margin-top:.75rem">Income {{ Money::format($draft['income']) }} − Expenses {{ Money::format($draft['expenses']) }} =
                <strong>{{ Money::format($draft['income'] - $draft['expenses']) }}</strong>
                @if ($draft['pending']) <span class="muted">({{ $draft['pending'] }} unverified expense(s) block closing)</span> @endif
            </p>
            <form method="POST" action="{{ route('finance.periods.close') }}">@csrf
                <input type="hidden" name="month" value="{{ $month }}"><button class="btn btn-primary">Close {{ $month }}</button>
            </form>
        </div>
    </section>
    @endcan

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Month</th><th class="num">Income</th><th class="num">Expenses</th><th class="num">Net profit</th><th>Status</th><th>Allocation</th></tr></thead>
            <tbody>
            @forelse ($periods as $p)
                <tr><td><a href="{{ route('finance.periods.show', $p) }}">{{ $p->month }}</a></td>
                    <td class="num">{{ Money::format($p->income) }}</td><td class="num">{{ Money::format($p->expenses) }}</td>
                    <td class="num strong">{{ Money::format($p->net_profit) }}</td><td><x-status :value="$p->status" /></td>
                    <td>{{ $p->allocation ? ucfirst($p->allocation->status) : '—' }}</td></tr>
            @empty
                <tr><td colspan="6" class="muted">No closed periods.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
