@use('App\Support\Money')
<x-layout title="Advances & Refunds">
    <x-page-header title="Customer Advances & Refunds">
        <x-slot:meta>A payment counts as earned revenue unless it is marked as an advance. Advances are liabilities until recognised. Refunds reduce revenue in the month they are made. Voiding a payment = deleting it in ClientHub, only while its month is open.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Paid</th><th>Client / Project</th><th class="num">Amount</th><th class="num">Unearned advance</th><th class="num">Refundable revenue</th>@can('revenue.record')<th>Action</th>@endcan</tr></thead>
            <tbody>
            @forelse ($payments as $p)
                @php($b = $balances[$p->id])
                <tr><td class="nowrap">{{ $p->paid_on->format('j M Y') }}</td><td>{{ $p->project->client->name }}<span class="secondary">{{ $p->project->name }}</span></td>
                    <td class="num">{{ Money::format($p->amount) }}</td><td class="num">{{ Money::format($b['advance_outstanding']) }}</td><td class="num">{{ Money::format($b['revenue_refundable']) }}</td>
                    @can('revenue.record')
                    <td>
                        <form method="POST" action="{{ route('finance.revenue.store', $p) }}" style="display:flex; gap:.25rem; flex-wrap:wrap">@csrf
                            <select name="kind"><option value="advance">Mark as advance</option><option value="recognition">Recognise advance</option><option value="refund">Refund</option></select>
                            <select name="against"><option value="revenue">refund of revenue</option><option value="advance">refund of advance</option></select>
                            <input name="amount" size="8" placeholder="amount" inputmode="decimal"><input type="date" name="effective_on" title="Date (not for advances)">
                            <input name="reason" placeholder="reason" size="12"><button class="btn btn-sm">Record</button>
                        </form>
                    </td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No payments.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Adjustments</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Type</th><th>Client</th><th class="num">Amount</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($adjustments as $a)
                <tr><td>{{ $a->effective_on->format('j M Y') }}</td><td>{{ ucfirst($a->kind) }}{{ $a->against ? ' ('.$a->against.')' : '' }}</td><td>{{ $a->payment->project->client->name }}</td>
                    <td class="num">{{ Money::format($a->amount) }}</td><td>{{ $a->reason }}</td><td><x-status :value="$a->status" /></td>
                    <td class="num">@if ($a->status === 'pending') @can('expense.verify')
                        @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)
                            <form method="POST" action="{{ route('finance.revenue.decide', $a) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>
                        @endforeach
                    @endcan @endif</td></tr>
            @empty
                <tr><td colspan="7" class="muted">None.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
