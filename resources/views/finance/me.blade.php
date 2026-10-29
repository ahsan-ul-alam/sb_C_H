@use('App\Support\Money')
<x-layout title="My Account">
    <x-page-header title="{{ $partner->code }} · {{ $partner->name }}" />
    <div class="summary-strip">
        <div><div class="label">Paid capital (verified)</div><div class="value">{{ Money::format($capital) }}</div></div>
        <div><div class="label">Profit entitlement payable</div><div class="value">{{ Money::format($payable) }}</div></div>
    </div>
    <section class="panel">
        <div class="panel-head"><h2>My payouts</h2><a class="btn btn-sm btn-primary" href="{{ route('finance.payouts') }}">Request payout</a></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th class="num">Amount</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($payouts as $p)
                <tr><td>{{ $p->created_at->format('j M Y') }}</td><td class="num">{{ Money::format($p->amount) }}</td><td><x-status :value="$p->status" /></td></tr>
            @empty
                <tr><td colspan="3" class="muted">No payouts yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>My capital entries</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Reference</th><th class="num">Amount</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($capitalEntries as $c)
                <tr><td>{{ $c->paid_on->format('j M Y') }}</td><td>{{ $c->reference }}</td><td class="num">{{ Money::format($c->amount) }}</td><td><x-status :value="$c->status" /></td></tr>
            @empty
                <tr><td colspan="4" class="muted">None.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
