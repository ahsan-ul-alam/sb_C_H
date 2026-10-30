@use('App\Support\Money')
<x-layout title="Investment Overview">
    <x-page-header title="Owner Investment & Capital">
        <x-slot:meta>Five separate things: paid-in capital (money partners put in; never revenue) · contribution target (40/30/30, a commitment) · ownership (one third each) · profit entitlement (the 40/30/30 pool share, a liability to the partner until paid out) · loss responsibility (equal thirds). Paid-in capital comes only from verified investment entries and approved opening balances. The 40/30/30 investment ratio is the commitment target; ownership is exactly one third each.</x-slot:meta>
        <x-slot:actions><a href="{{ route('finance.capital') }}" class="btn">Investment ledger</a></x-slot:actions>
    </x-page-header>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Total paid-in capital</div><div class="value">{{ Money::format($total) }}</div></div>
        @foreach ($funds as $label => $amount)
            <div class="metric"><div class="label">{{ $label }}</div><div class="value">{{ Money::format($amount) }}</div></div>
        @endforeach
        <div class="metric"><div class="label">Emergency coverage held (not yet applied)</div><div class="value">{{ Money::format($held_coverage) }}</div></div>
    </div>

    <section class="panel">
        <div class="panel-head"><h2>Capital by partner</h2><span class="sub">Policy: {{ $policy?->name ?? '—' }}</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Partner</th><th class="num">Target share</th><th class="num">Actual share</th><th class="num">Paid-in capital (not revenue)</th><th class="num">Target amount*</th><th class="num">Contribution gap (target − paid-in)</th><th class="num">Pending entries</th><th class="num">Loss charged (capital account)</th><th class="num">Capital account balance</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr><td class="primary-cell"><a href="{{ route('finance.partners.show', $r['partner']) }}">{{ $r['partner']->code }} · {{ $r['partner']->name }}</a></td>
                    <td class="num">{{ $r['target_bps'] === null ? '—' : number_format($r['target_bps'] / 100, 2).'%' }}</td>
                    <td class="num">{{ $r['actual_bps'] === null ? '—' : number_format($r['actual_bps'] / 100, 2).'%' }}</td>
                    <td class="num">{{ Money::format($r['contributed']) }}</td>
                    <td class="num">{{ $r['target_amount'] === null ? '—' : Money::format($r['target_amount']) }}</td>
                    <td class="num {{ ($r['gap'] ?? 0) > 0 ? 'text-danger' : '' }}">{{ $r['gap'] === null ? '—' : Money::format($r['gap']) }}</td>
                    <td class="num">{{ Money::format($r['pending']) }}</td><td class="num">{{ Money::format($r['loss_charged']) }}</td>
                    <td class="num strong">{{ Money::format($r['capital_balance']) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><td>Total</td><td></td><td></td><td class="num">{{ Money::format($total) }}</td><td colspan="2"></td><td class="num">{{ Money::format((int) $rows->sum('pending')) }}</td><td class="num">{{ Money::format((int) $rows->sum('loss_charged')) }}</td><td class="num">{{ Money::format((int) $rows->sum('capital_balance')) }}</td></tr></tfoot>
        </table></div>
        <div class="panel-foot muted">*Target amount = total paid-in × target share. A positive gap is what that partner would still add to match the policy proportions at today's total.</div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Profit entitlement &amp; payouts</h2><span class="sub">50% of each posted profit allocation is the partners' pool, split by the profit ratio.</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Partner</th><th class="num">Entitlement accrued</th><th class="num">Paid out</th><th class="num">Outstanding balance</th><th class="num">Payout requests open</th><th class="num">Available to request</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr><td>{{ $r['partner']->code }} · {{ $r['partner']->name }}</td><td class="num">{{ Money::format($r['accrued']) }}</td><td class="num">{{ Money::format($r['paid_out']) }}</td>
                    <td class="num strong">{{ Money::format($r['outstanding']) }}</td><td class="num">{{ Money::format($r['payout_open']) }}</td><td class="num">{{ Money::format(max(0, $r['outstanding'] - $r['payout_open'])) }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Emergency coverage for sourcing losses</h2></div>
        <div class="panel-body muted">Coverage is held in its own ledger account until a partner-loss allocation applies it (needs the Board resolution "{{ \App\Services\SourcingObligations::COVERAGE_RESOLUTION }}"). If the company is in profit and the coverage is not needed, release it back to the Emergency Reserve below. Applied or claimed coverage cannot be released.</div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Lot</th><th class="num">Coverage</th><th>Utilised</th><th></th></tr></thead>
            <tbody>
            @forelse ($utilised as $r)
                <tr><td><a href="{{ route('sourcing.lots.show', [$r->lot, 'tab' => 'emergency']) }}">{{ $r->lot->number }}</a></td><td class="num">{{ Money::format($r->amount) }}</td><td>{{ $r->utilised_at?->format('j M Y') }}</td>
                    <td class="num">@can('sourcing.fund.utilize') @if ($held_coverage >= $r->amount)
                        <form method="POST" action="{{ route('sourcing.fund.reverse', $r) }}" class="inline-form">@csrf<input name="reason" placeholder="reason for release" required><button class="btn btn-sm">Release to reserve</button></form>
                    @endif @endcan</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No utilised coverage.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
