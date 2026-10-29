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
