@use('App\Support\Money')
<x-layout title="Partner {{ $partner->code }}">
    <x-page-header title="{{ $partner->code }} · {{ $partner->name }}" :crumbs="[['Partners', route('finance.partners')], [$partner->code, null]]">
        <x-slot:badges><x-status :value="$partner->status" /></x-slot:badges>
        <x-slot:meta>
            @if ($partner->joined_on) <span>Joined {{ $partner->joined_on->format('j M Y') }}</span> @endif
            @if ($partner->exited_on) <span>Exited {{ $partner->exited_on->format('j M Y') }}</span> @endif
            <span>Account: {{ $linkedUser ? $linkedUser->name.' ('.$linkedUser->email.')' : 'not linked yet' }}</span>
        </x-slot:meta>
        <x-slot:actions>
            @can('partner.manage')
                <a href="{{ route('finance.partners.edit', $partner) }}" class="btn btn-primary"><x-icon name="edit" class="icon-sm" /> Edit Partner</a>
            @endcan
            <a href="{{ route('finance.partners') }}" class="btn">Back to partners</a>
        </x-slot:actions>
    </x-page-header>

    <div class="summary-strip">
        <div><div class="label">Paid capital (ledger)</div><div class="value">{{ Money::format($capital) }}</div></div>
        <div><div class="label">Profit entitlement payable</div><div class="value">{{ Money::format($payable) }}</div></div>
    </div>

    @php($cap = $report['capital'])
    @php($pr = $report['profit'])
    <section class="panel">
        <div class="panel-head"><h2>Capital</h2><span class="sub">Verified contributions only. Profit and loan-recovery figures never change this.</span></div>
        <div class="panel-body"><div class="stat-grid">
            <div class="stat"><span class="stat-label">Verified contributions</span><span class="stat-value">{{ Money::format($cap['contributed']) }}</span></div>
            <div class="stat"><span class="stat-label">Withdrawals</span><span class="stat-value">{{ Money::format($cap['withdrawn']) }}</span></div>
            <div class="stat"><span class="stat-label">Losses charged</span><span class="stat-value">{{ Money::format($cap['loss_charged']) }}</span></div>
            <div class="stat"><span class="stat-label">Pending verification</span><span class="stat-value">{{ Money::format($cap['pending']) }}</span></div>
            <div class="stat"><span class="stat-label">Current capital position</span><span class="stat-value">{{ Money::format($cap['balance']) }}</span></div>
        </div></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Profit</h2><span class="sub">A profit share is an amount owed to the investor, not cash in hand. Only a verified payout moves cash.</span></div>
        <div class="panel-body"><div class="stat-grid">
            <div class="stat"><span class="stat-label">Approved profit share</span><span class="stat-value">{{ $pr['share']['bps'] === null ? $pr['share']['state'] : number_format($pr['share']['bps'] / 100, 2).'%' }}</span></div>
            <div class="stat"><span class="stat-label">Allocated</span><span class="stat-value">{{ Money::format($pr['allocated']) }}</span></div>
            <div class="stat"><span class="stat-label">Paid</span><span class="stat-value">{{ Money::format($pr['paid']) }}</span></div>
            <div class="stat"><span class="stat-label">Outstanding payable</span><span class="stat-value">{{ Money::format($pr['outstanding']) }}</span></div>
        </div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Period</th><th scope="col">Policy</th><th scope="col" class="num">Share</th><th scope="col" class="num">Allocated</th><th scope="col" class="num">Paid (oldest first)</th><th scope="col" class="num">Outstanding</th></tr></thead>
            <tbody>
            @forelse ($pr['history'] as $h)
                <tr><td>{{ $h['month'] }}</td><td>{{ $h['policy'] ?? '—' }}</td><td class="num">{{ $h['share_bps'] === null ? $h['share_state'] : number_format($h['share_bps'] / 100, 2).'%' }}</td><td class="num">{{ Money::format($h['allocated']) }}</td><td class="num">{{ Money::format($h['paid']) }}</td><td class="num">{{ Money::format($h['outstanding']) }}</td></tr>
            @empty
                <tr><td colspan="6" class="muted">No profit has been allocated to this investor yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Loan recovery tracking</h2><span class="sub">Tracking only: never added to capital or profit, and not income.</span></div>
        <div class="panel-body"><div class="stat-grid"><div class="stat"><span class="stat-label">Allocated by verified loan repayments</span><span class="stat-value">{{ Money::format($report['loan_recovery']['allocated']) }}</span></div></div></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Capital history</h2><a class="btn btn-sm" href="{{ route('finance.capital') }}">Investment ledger</a></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Date</th><th scope="col">Reference</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
            <tbody>
            @forelse ($entries as $e)
                <tr><td>{{ $e->paid_on->format('j M Y') }}</td><td>{{ $e->reference }}</td><td class="num">{{ Money::format($e->amount) }}</td><td><x-status :value="$e->status" /></td></tr>
            @empty
                <tr><td colspan="4" class="muted">No capital entries yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Payouts</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">Requested</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
            <tbody>
            @forelse ($payouts as $p)
                <tr><td>{{ $p->created_at->format('j M Y') }}</td><td class="num">{{ Money::format($p->amount) }}</td><td><x-status :value="$p->status" /></td></tr>
            @empty
                <tr><td colspan="3" class="muted">No payouts yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
