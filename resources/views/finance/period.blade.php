@use('App\Support\Money')
<x-layout title="Period {{ $period->month }}">
    <x-page-header title="Period {{ $period->month }}" :crumbs="[['Periods', route('finance.periods')], [$period->month, null]]">
        <x-slot:meta>Income {{ Money::format($period->income) }} · Expenses {{ Money::format($period->expenses) }} · Net {{ Money::format($period->net_profit) }} · <x-status :value="$period->status" /></x-slot:meta>
        <x-slot:actions>
            @if ($period->status === 'closed')
                @can('period.verify')
                    <form method="POST" action="{{ route('finance.periods.verify', $period) }}">@csrf<button class="btn btn-success">Verify period</button></form>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($allocation)
        @php($lines = $allocation->lines) @php($label = 'Allocation')
    @else
        @php($lines = collect($preview)->map(fn ($l) => (object) $l)) @php($label = 'Preview (not posted)')
    @endif

    @if ($obligations && $obligations['total'] > 0 && ! $allocation)
        <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div><strong>Profit is held back.</strong> Sourcing obligations of {{ Money::format($obligations['total']) }} (supplier payables {{ Money::format($obligations['suppliers']) }}, unpaid lot costs {{ Money::format($obligations['costs']) }}, payments awaiting verification {{ Money::format($obligations['pending']) }}) are not yet in the ledger. Income is cash-based, so this profit is not safely distributable until they are paid and verified.</div></div>
    @endif
    @if ($period->net_profit < 0 && $heldCoverage > 0 && ! $allocation)
        <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div>Emergency Fund coverage of {{ Money::format($heldCoverage) }} is held for sourcing losses.
            @if ($coverageApproved) It is applied below and reduces the partner loss charge; the accounting loss is unchanged.
            @else It does NOT yet reduce the partner loss: the Board must approve a resolution (type Other) titled "{{ \App\Services\SourcingObligations::COVERAGE_RESOLUTION }}".
            @endif</div></div>
    @endif

    @if ($period->net_profit === 0)
        <div class="flash flash-error"><x-icon name="alert" /> <div>Net profit is zero; nothing is distributed.</div></div>
    @elseif ($lines->isNotEmpty())
        <section class="panel">
            <div class="panel-head"><div><h2>{{ $label }} — {{ $period->net_profit > 0 ? 'profit waterfall' : 'loss split (equal thirds)' }}</h2>
                <div class="sub">Policy: {{ ($allocation?->policy ?? $policy)->name }}</div></div>
                @if ($allocation) <span class="badge badge-{{ $allocation->status === 'posted' ? 'paid' : 'partial' }}"><x-status :value="$allocation->status" /></span> @endif
            </div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Destination</th><th class="num">Amount</th></tr></thead>
                <tbody>
                @foreach ($lines as $l)
                    <tr><td>{{ match ($l->account) { 'fund.salary' => 'Salary Fund', 'fund.office' => 'Office & Growth Fund', 'fund.emergency' => 'Emergency Reserve', 'fund.coverage' => 'Emergency Fund coverage (absorbs this part of the loss)', 'partner.payable' => 'Partner '.$partners[$l->partner_id].' — profit entitlement', 'partner.capital' => 'Partner '.$partners[$l->partner_id].' — loss charge', default => $l->account } }}</td>
                        <td class="num">{{ Money::format((int) $l->amount) }}</td></tr>
                @endforeach
                <tr><td class="strong">Total</td><td class="num strong">{{ Money::format((int) $lines->sum('amount')) }}</td></tr>
                </tbody>
            </table></div>
            <div class="panel-foot">
                @if (! $allocation)
                    @if ($blocker = $policy?->liveBlocker()) <div class="muted">Live posting blocked: {{ $blocker }}</div> @endif
                    @if ($period->status === 'verified')
                        @can('allocation.prepare')
                            <form method="POST" action="{{ route('finance.periods.prepare', $period) }}">@csrf<button class="btn btn-primary">Prepare allocation</button></form>
                        @endcan
                    @else
                        <span class="muted">The Board must verify the period before an allocation can be prepared.</span>
                    @endif
                @elseif ($allocation->status === 'proposed')
                    @can('allocation.approve')
                        <form method="POST" action="{{ route('finance.allocations.approve', $allocation) }}">@csrf<button class="btn btn-success">Approve allocation</button></form>
                    @endcan
                @elseif ($allocation->status === 'approved')
                    @can('allocation.post')
                        <form method="POST" action="{{ route('finance.allocations.post', $allocation) }}" data-confirm="Post once to the ledger? This cannot be undone.">@csrf<button class="btn btn-primary">Post to ledger</button></form>
                    @endcan
                @endif
            </div>
        </section>
    @endif
</x-layout>
