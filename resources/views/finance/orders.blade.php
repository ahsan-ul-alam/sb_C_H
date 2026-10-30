@use('App\Support\Money')
<x-layout title="Order Commission">
    <x-page-header title="Successful-Order Commission">
        <x-slot:meta>Commission = agreed net order value (excluding shipping and taxes) × the client's rate. It is billed through the client's normal invoice and becomes revenue only when ClientHub receives the payment.</x-slot:meta>
    </x-page-header>

    @if ($defaultBps === 0)
        <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div>The default commission rate is 0%, so nothing is earned yet. Finance sets the rate below (the usual rate is 1%).</div></div>
    @endif

    <div class="metrics-auto">
        <div class="metric"><div class="label">Default rate</div><div class="value">{{ number_format($defaultBps / 100, 2) }}%</div></div>
        <div class="metric"><div class="label">Commission earned, not yet invoiced</div><div class="value">{{ Money::format($totals['accrued']) }}</div></div>
        <div class="metric"><div class="label">Commission invoiced</div><div class="value">{{ Money::format($totals['billed']) }}</div></div>
    </div>

    @can('commission.rates')
    <section class="panel">
        <div class="panel-head"><h2>Rates</h2><span class="sub">A rate is fixed on each order when it becomes successful. Changing it later never alters earlier orders.</span></div>
        <form method="POST" action="{{ route('finance.orders.rates') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Default rate (%)</label><input name="default_percent" inputmode="decimal" value="{{ number_format($defaultBps / 100, 2, '.', '') }}"></div>
                <div class="field"><label>Client override</label><select name="client_id"><option value="">— none —</option>@foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->commission_bps === null ? 'default' : number_format($c->commission_bps / 100, 2).'%' }})</option>@endforeach</select></div>
                <div class="field"><label>Override rate (%) — blank resets to default</label><input name="client_percent" inputmode="decimal"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Save rates</button>
        </form>
    </section>
    @endcan

    @can('commission.manage')
    <section class="panel">
        <div class="panel-head"><h2>Record an order</h2></div>
        <form method="POST" action="{{ route('finance.orders.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Client project</label><select name="project_id" required>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->client->name }} — {{ $p->name }}</option>@endforeach</select></div>
                <div class="field"><label>Order reference</label><input name="reference" required></div>
                <div class="field"><label>Order date</label><input type="date" name="ordered_on" value="{{ today()->toDateString() }}" required></div>
                <div class="field"><label>Net order value</label><input name="net_value" inputmode="decimal" required></div>
                <div class="field"><label>Notes</label><input name="notes"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Record order</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Date</th><th>Client / order</th><th class="num">Net value</th><th>Status</th><th class="num">Rate</th><th class="num">Commission</th><th>Invoice</th><th></th></tr></thead>
            <tbody>
            @forelse ($orders as $o)
                <tr><td class="nowrap">{{ $o->ordered_on->format('j M Y') }}</td><td>{{ $o->project->client->name }}<span class="secondary">{{ $o->reference }}</span></td>
                    <td class="num">{{ Money::format($o->net_value) }}</td><td><x-status :value="$o->status" /></td>
                    <td class="num">{{ $o->rate_bps === null ? '—' : number_format($o->rate_bps / 100, 2).'%' }}</td><td class="num">{{ Money::format($o->commission) }}</td>
                    <td>@if ($o->invoice)<a href="{{ route('invoices.show', $o->invoice) }}">{{ $o->invoice->number }}</a>@else —@endif</td>
                    <td class="num">@can('commission.manage') @unless ($o->billed_invoice_id) @if ($o->status !== 'cancelled')
                        @if ($o->status === 'pending')<form method="POST" action="{{ route('finance.orders.success', $o) }}" style="display:inline">@csrf<button class="btn btn-sm btn-success">Mark successful</button></form>@endif
                        @if ($o->status === 'successful' && $o->commission > 0)<form method="POST" action="{{ route('finance.orders.bill', $o) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary">Invoice commission</button></form>@endif
                        <form method="POST" action="{{ route('finance.orders.adjust', $o) }}" class="inline-form">@csrf<input name="net_value" size="8" placeholder="new net value" inputmode="decimal" required><button class="btn btn-sm">Refund / reduce</button></form>
                        <form method="POST" action="{{ route('finance.orders.cancel', $o) }}" style="display:inline" data-confirm="Cancel this order? No commission will be due.">@csrf<button class="btn btn-sm btn-danger">Cancel</button></form>
                    @endif @endunless
                    @if ($o->billed_invoice_id && $o->status !== 'cancelled')
                        <form method="POST" action="{{ route('finance.orders.reduce', $o) }}" class="inline-form" data-confirm="Correct the invoiced commission? This edits an issued invoice line and is audited.">@csrf
                            <input name="net_value" size="8" placeholder="new net (blank = cancel)" inputmode="decimal"><input name="reason" size="12" placeholder="reason" required><button class="btn btn-sm btn-danger">Correct invoiced</button></form>
                    @endif @endcan</td></tr>
            @empty
                <tr><td colspan="8" class="muted">No orders recorded.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $orders])
        <div class="panel-foot muted">After a commission is invoiced it can only be corrected through the invoice and the existing refund process. There is no automatic link to a store or order system: orders are entered here.</div>
    </section>
</x-layout>
