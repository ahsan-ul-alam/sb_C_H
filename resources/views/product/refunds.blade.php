@use('App\Support\Money')
@use('Illuminate\Support\Str')
<x-layout title="Customer refunds">
    <x-page-header title="Customer refunds">
        <x-slot:meta>Money collected from an end customer belongs to the client. A refund is requested, approved by someone else, then paid from a named account against the money held for that client. It never creates or reverses revenue, commission or cost of goods; returning the goods already reduces the commission.</x-slot:meta>
    </x-page-header>

    @can('orders.manage')
    <section class="panel"><div class="panel-head"><h2>Request a refund</h2></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Order</th><th class="num">Still refundable</th><th>Request</th></tr></thead>
        <tbody>
        @forelse ($refundable as $r)
            <tr><td class="primary-cell">{{ $r['order']->reference }}<span class="secondary">{{ $r['order']->project->client->name }}</span></td><td class="num">{{ Money::format($r['left']) }}</td>
                <td><form method="POST" action="{{ route('product.refunds.store', $r['order']) }}" class="inline-form">@csrf
                    <input name="amount" size="8" inputmode="decimal" placeholder="amount" required>
                    <select name="kind"><option value="return">Goods are coming back</option><option value="no_return">No goods returned</option></select>
                    <input name="reason" size="22" placeholder="reason" required><button class="btn">Request</button></form></td></tr>
        @empty
            <tr><td colspan="3" class="muted">No delivered order has collected money left to refund.</td></tr>
        @endforelse
        </tbody></table></div></section>
    @endcan

    <section class="panel"><div class="panel-head"><h2>Refund history</h2></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Order</th><th class="num">Amount</th><th>Type / reason</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse ($refunds as $f)
            <tr><td class="primary-cell">{{ $f->order->reference }}<span class="secondary">{{ $f->order->project->client->name }} · by {{ $f->requester?->name }}</span></td>
                <td class="num">{{ Money::format($f->amount) }}</td>
                <td>{{ $f->kind === 'return' ? 'Goods returned' : 'No goods returned' }}<span class="secondary">{{ $f->reason }}@if ($f->decision_note) · note: {{ $f->decision_note }}@endif</span></td>
                <td><span class="badge {{ $f->status === 'paid' ? 'badge-paid' : (in_array($f->status, ['rejected', 'cancelled']) ? 'badge-unpaid' : 'badge-partial') }}">{{ ucfirst($f->status) }}</span>@if ($f->status === 'paid')<span class="secondary">{{ $f->paid_on->format('j M Y') }} · {{ $f->reference }}</span>@endif</td>
                <td>
                    @if ($f->status === 'requested')
                        @can('order.refund.approve')
                        <form method="POST" action="{{ route('product.refunds.decide', $f) }}" class="inline-form">@csrf<input name="note" size="18" placeholder="note / justification"><button name="decision" value="approve" class="btn btn-sm btn-primary">Approve</button><button name="decision" value="reject" class="btn btn-sm">Reject</button></form>
                        @endcan
                    @elseif ($f->status === 'approved')
                        @can('settlement.record')
                        <form method="POST" action="{{ route('product.refunds.pay', $f) }}" class="inline-form">@csrf<input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
                            <input type="date" name="paid_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required>
                            <select name="financial_account_id" required>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                            <input name="reference" size="14" placeholder="transaction ref" required><button class="btn btn-sm btn-primary">Record payment</button></form>
                        @endcan
                    @endif
                    @if (in_array($f->status, ['requested', 'approved']))
                        @can('orders.manage')<form method="POST" action="{{ route('product.refunds.cancel', $f) }}" class="inline-form">@csrf<button class="btn btn-sm">Cancel</button></form>@endcan
                    @endif
                </td></tr>
        @empty
            <tr><td colspan="5" class="muted">No refunds yet.</td></tr>
        @endforelse
        </tbody></table></div></section>
</x-layout>
