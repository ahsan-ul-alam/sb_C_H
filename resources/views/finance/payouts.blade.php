@use('App\Support\Money')
<x-layout title="Payouts">
    <x-page-header title="Partner Payouts" />

    @can('payout.request')
    <section class="panel">
        <div class="panel-head"><h2>Request a withdrawal</h2>
            <span class="sub">Available: {{ Money::format($available[auth()->user()->partner_id] ?? 0) }}</span></div>
        <form method="POST" action="{{ route('finance.payouts.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Amount</label><input name="amount" inputmode="decimal" value="{{ old('amount') }}" required></div>
                <div class="field"><label>Note</label><input name="note" value="{{ old('note') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Request payout</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Requested</th><th>Partner</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($payouts as $p)
                <tr><td>{{ $p->created_at->format('j M Y') }}</td><td>{{ $p->partner->code }}</td><td class="num">{{ Money::format($p->amount) }}</td><td><x-status :value="$p->status" /></td>
                    <td class="num">
                        @if ($p->status === 'requested')
                            @can('payout.approve')
                                @foreach (['approve' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)
                                    <form method="POST" action="{{ route('finance.payouts.decide', $p) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>
                                @endforeach
                            @endcan
                        @elseif ($p->status === 'approved')
                            @can('payout.pay')
                                <form method="POST" action="{{ route('finance.payouts.pay', $p) }}" data-confirm="Pay this out and post to the ledger?">@csrf<button class="btn btn-sm btn-primary">Mark paid</button></form>
                            @endcan
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="5" class="muted">No payouts.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
