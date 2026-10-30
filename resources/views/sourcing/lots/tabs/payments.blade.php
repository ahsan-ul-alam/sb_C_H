@use('App\Support\Money')
@php($supplierRows = collect($f['lines'])->groupBy(fn ($r) => $r['line']->supplier_id))
<div class="metrics-auto">
    <div class="metric"><div class="label">Total sourcing cost (recognised)</div><div class="value">{{ Money::format($f['landed']) }}</div></div>
    <div class="metric"><div class="label">Paid to suppliers</div><div class="value">{{ Money::format($f['supplier_paid']) }}</div></div>
    <div class="metric @if ($f['supplier_outstanding'] > 0) is-danger @endif"><div class="label">Supplier payable</div><div class="value">{{ Money::format($f['supplier_outstanding']) }}</div></div>
    <div class="metric"><div class="label">Other costs unpaid</div><div class="value">{{ Money::format($f['costs_unpaid']) }}</div></div>
    <div class="metric"><div class="label">Cash still required</div><div class="value">{{ Money::format($f['cash_required']) }}</div></div>
</div>
<p class="muted">Cost stays the same whether or not it is paid. A payment becomes a Main Finance expense once (after Board verification), never in addition to the cost.</p>

<section class="panel">
    <div class="panel-head"><h2>Supplier payables</h2></div>
    <div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>Supplier</th><th class="num">Purchase value</th><th class="num">Paid</th><th class="num">Outstanding</th><th></th></tr></thead>
        <tbody>
        @foreach ($supplierRows as $supplierId => $rows)
            @php($purchase = $rows->sum('purchase'))
            @php($paid = (int) $lot->payments->where('supplier_id', $supplierId)->sum(fn ($p) => $p->effectiveAmount()))
            <tr><td>{{ $rows->first()['line']->supplier->name }}</td><td class="num">{{ Money::format($purchase) }}</td><td class="num">{{ Money::format($paid) }}</td><td class="num">{{ Money::format(max(0, $purchase - $paid)) }}</td>
                <td class="num">@can('sourcing.payment.record') @if (! $lot->isClosed() && $purchase > $paid)
                    <form method="POST" action="{{ route('sourcing.lots.payments.store', $lot) }}" class="inline-form">@csrf
                        <input type="hidden" name="supplier_id" value="{{ $supplierId }}"><input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
                        <input name="amount" size="9" placeholder="amount" inputmode="decimal" required><input type="date" name="paid_on" value="{{ today()->toDateString() }}" required>
                        <input name="method" size="8" placeholder="method"><input name="reference" size="10" placeholder="reference"><button class="btn btn-sm btn-primary">Pay</button></form>
                @endif @endcan</td></tr>
        @endforeach
        </tbody>
    </table></div>
</section>

<section class="panel">
    <div class="panel-head"><h2>Payment history</h2></div>
    <div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>Date</th><th>Supplier</th><th>Method / ref</th><th class="num">Amount</th><th>Expense</th><th></th></tr></thead>
        <tbody>
        @forelse ($lot->payments as $p)
            <tr @if ($p->isVoided()) style="opacity:.55" @endif><td>{{ $p->paid_on->format('j M Y') }}</td><td>{{ $p->supplier->name }}</td><td>{{ $p->method }} {{ $p->reference }}</td>
                <td class="num">{{ Money::format($p->amount) }}</td>
                <td>@if ($p->isVoided())<x-status :value="'voided'" /> <span class="secondary">{{ $p->void_reason }}</span>@else<x-status :value="$p->expense->status" />@endif</td>
                <td class="num">@can('sourcing.payment.record') @if (! $p->isVoided() && $p->expense->status !== 'rejected')
                    @if ($p->reversal_expense_id)<span class="badge badge-partial">Correction awaiting Board</span>
                    @else
                    <form method="POST" action="{{ route('sourcing.payments.void', $p) }}" class="inline-form">@csrf
                        @if ($p->expense->status === 'verified')
                            <select name="type" title="What actually happened?">@foreach (\App\Services\SourcingPayments::CORRECTIONS as $k => $label)<option value="{{ $k }}">{{ \Illuminate\Support\Str::before($label, ' —') }}</option>@endforeach</select>
                            <input name="amount" size="8" placeholder="amount (blank = all)" inputmode="decimal"><input name="reference" size="10" placeholder="receipt / credit-note ref">
                        @endif
                        <input name="reason" placeholder="reason" required><button class="btn btn-sm btn-danger">{{ $p->expense->status === 'verified' ? 'Request correction' : 'Void' }}</button></form>
                    @endif
                @endif @endcan</td></tr>
        @empty
            <tr><td colspan="6" class="muted">No payments yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<section class="panel">
    <div class="panel-head"><h2>Cancel lot</h2></div>
    <div class="panel-body">
        @can('sourcing.manage') @if (! $lot->isClosed())
            <form method="POST" action="{{ route('sourcing.lots.cancel', $lot) }}" class="inline-form">@csrf<input name="reason" placeholder="Reason" required><button class="btn btn-danger">Cancel lot</button></form>
            <p class="muted">Blocked while the lot has supplier payments, paid costs, invoices or fund requests.</p>
        @else
            <p class="muted">{{ $lot->statusLabel() }}{{ $lot->cancel_reason ? ': '.$lot->cancel_reason : '' }}</p>
        @endif @endcan
    </div>
</section>
