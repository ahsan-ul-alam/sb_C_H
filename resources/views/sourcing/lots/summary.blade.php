@use('App\Support\Money')
@use('Illuminate\Support\Str')
@php
    $operational = \App\Services\LotStatus::operational($lot);
    $financialState = \App\Services\LotStatus::financial($lot);
    $open = in_array($lot->status, ['approved', 'in_progress', 'completed'], true);
    $invoices = $lot->allocations->pluck('invoice')->filter()->unique('id');
    $replaced = $lot->costs->pluck('replaces_id')->filter()->all();
    $costs = $lot->costs->reject(fn ($c) => in_array($c->id, $replaced, true))->where('kind', 'actual');
    $bySupplier = collect($f['lines'])->groupBy(fn ($r) => $r['line']->supplier_id);
    $unit = fn ($minor) => Money::format((int) bcadd((string) $minor, '0.5', 0));
    $clientState = $f['billed'] === 0 ? 'Not billed yet' : ($f['receivable'] <= 0 ? 'Paid in full' : ($f['received'] > 0 ? 'Partly paid' : 'Unpaid'));
    $supplierState = $f['supplier_outstanding'] <= 0 ? 'Paid in full' : ($f['supplier_paid'] > 0 ? 'Partly paid' : 'Unpaid');
    $blockers = \App\Services\FinancialCompleteness::lotBlockers($lot);
    $paidUp = $f['supplier_outstanding'] <= 0 && $f['costs_unpaid'] <= 0 && $f['estimates_open'] <= 0;
    $canClose = $open && $lot->status !== 'settled' && $paidUp && $blockers === [];
    $awaiting = $lot->lines->filter(fn ($l) => $l->received_quantity === null);
@endphp

<div style="margin-bottom:1rem">
    <span class="badge badge-{{ $operational['tone'] }}">Goods: {{ $operational['label'] }}</span>
    <span class="badge badge-{{ $financialState['tone'] }}">Books: {{ $financialState['label'] }}</span>
    @foreach (\App\Services\LotStatus::flags($lot) as $flag)<span class="badge badge-partial">⚠ {{ $flag }}</span>@endforeach
</div>

@if ($lot->status === 'priced')
    <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div><strong>Waiting for pricing approval.</strong> The prices below are not final and the client cannot be billed yet.
        @if (auth()->user()->can('sourcing.price.approve') && $lot->priced_by !== auth()->id())
            <form method="POST" action="{{ route('sourcing.lots.approve', $lot) }}" style="display:inline; margin-left:.5rem">@csrf<button class="btn btn-sm btn-primary">Approve pricing</button></form>
        @else Someone with approval rights other than the person who saved it must approve it. @endif</div></div>
@endif

<div class="metrics-auto">
    <div class="metric"><div class="label">Total purchase cost</div><div class="value">{{ Money::format($f['purchase']) }}</div></div>
    <div class="metric"><div class="label">Delivery &amp; other costs</div><div class="value">{{ Money::format($f['additional']) }}</div></div>
    <div class="metric"><div class="label">Total landed cost</div><div class="value">{{ Money::format($f['landed']) }}</div></div>
    <div class="metric"><div class="label">Client selling total</div><div class="value">{{ Money::format($f['revenue']) }}</div></div>
    <div class="metric"><div class="label">Expected profit {{ $lot->approved_at ? '(at approval)' : '(planned)' }}</div><div class="value">{{ Money::format($f['approved_profit'] ?? $f['profit']) }}</div></div>
    <div class="metric @if ($f['profit'] < 0) is-danger @endif"><div class="label">Actual {{ $f['profit'] < 0 ? 'loss' : 'profit' }} (now)</div><div class="value">{{ Money::format($f['profit']) }}</div></div>
</div>
<p class="muted">Profit = selling total − real costs, whether or not the money has moved. What the client has actually paid and what has actually been paid out are shown separately below.</p>

<section class="panel">
    <div class="panel-head"><h2>Products</h2><span class="sub">Supplier: {{ $bySupplier->map(fn ($rows) => $rows->first()['line']->supplier->name)->implode(', ') ?: '—' }}</span></div>
    <div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Product</th><th class="num">Qty</th><th class="num">Base cost / unit</th><th class="num">Purchase</th><th class="num">Delivery share</th><th class="num">Landed / unit</th><th class="num">Profit / unit</th><th class="num">Client price / unit</th><th class="num">Client total</th><th class="num">Expected profit</th></tr></thead>
        <tbody>
        @forelse ($f['lines'] as $row)
            <tr><td>{{ $row['line']->description }}</td><td class="num">{{ $row['line']->quantity }}</td><td class="num">{{ Money::format($row['line']->unit_price) }}</td>
                <td class="num">{{ Money::format($row['purchase']) }}</td><td class="num">{{ Money::format($row['direct'] + $row['shared']) }}</td>
                <td class="num">{{ $unit($row['unit_cost']) }}</td><td class="num">{{ $row['unit_profit'] === null ? '—' : $unit($row['unit_profit']) }}</td>
                <td class="num strong">{{ $row['price'] === null ? '—' : Money::format($row['price']) }}</td><td class="num">{{ Money::format($row['revenue']) }}</td>
                <td class="num {{ $row['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::format($row['profit']) }}</td></tr>
        @empty
            <tr><td colspan="10" class="muted">No products.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:1rem">
    <section class="panel">
        <div class="panel-head"><h2>Client</h2><span class="badge badge-{{ $clientState === 'Paid in full' ? 'paid' : ($clientState === 'Unpaid' ? 'unpaid' : 'partial') }}">{{ $clientState }}</span></div>
        <div class="table-wrap"><table class="table">
            <tr><td>Selling total (agreed)</td><td class="num">{{ Money::format($f['revenue']) }}</td></tr>
            <tr><td>Client billed (invoiced)</td><td class="num">{{ Money::format($f['billed']) }}</td></tr>
            <tr><td>Client received (real receipts)</td><td class="num">{{ Money::format($f['received']) }}</td></tr>
            <tr><td class="strong">Client outstanding</td><td class="num strong">{{ Money::format($f['receivable']) }}</td></tr>
            @if ($f['unbilled'] > 0)<tr><td class="muted">Not invoiced yet</td><td class="num muted">{{ Money::format($f['unbilled']) }}</td></tr>@endif
        </table></div>
        <div class="panel-body">
            @foreach ($invoices as $inv)<p class="muted">Invoice <a href="{{ route('invoices.show', $inv) }}">{{ $inv->number }}</a> — balance {{ Money::format($inv->balance()) }}</p>@endforeach
            @if ($open && $f['unbilled'] > 0)
                @can('sourcing.manage')
                <form method="POST" action="{{ route('sourcing.lots.bill-client', $lot) }}" class="inline-form">@csrf
                    <select name="project_id" required><option value="">Client project…</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected($lot->project_id === $p->id)>{{ $p->name }} — {{ $p->client->name }}</option>@endforeach</select>
                    <button class="btn btn-primary">Bill client</button></form>
                @endcan
            @endif
            @if ($f['receivable'] > 0)
                @can('clienthub')
                <form method="POST" action="{{ route('sourcing.lots.client-payment', $lot) }}" class="inline-form" style="margin-top:.5rem">@csrf
                    <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
                    <input name="amount" size="9" inputmode="decimal" placeholder="{{ Money::toDecimal($f['receivable']) }}" title="Leave blank for the full remaining balance">
                    <input type="date" name="paid_on" value="{{ today()->toDateString() }}" required>
                    <select name="method">@foreach (\App\Enums\PaymentMethod::cases() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select>
                    <input name="reference" size="10" placeholder="reference">
                    <button class="btn btn-primary">Mark client paid</button></form>
                <p class="muted">Use this only when the money has actually been received. Blank amount = the whole balance; type a smaller amount for a part payment.</p>
                @endcan
            @endif
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Supplier &amp; costs</h2><span class="badge badge-{{ $supplierState === 'Paid in full' ? 'paid' : ($supplierState === 'Unpaid' ? 'unpaid' : 'partial') }}">{{ $supplierState }}</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Supplier</th><th class="num">Purchase obligation</th><th class="num">Paid</th><th class="num">Outstanding</th></tr></thead>
            @foreach ($bySupplier as $supplierId => $rows)
                @php($owed = (int) $rows->sum('purchase'))
                @php($paid = \App\Services\SourcingPayments::paidTotal($lot->payments->where('supplier_id', $supplierId)))
                <tr><td>{{ $rows->first()['line']->supplier->name }}</td><td class="num">{{ Money::format($owed) }}</td><td class="num">{{ Money::format($paid) }}</td><td class="num strong">{{ Money::format(max(0, $owed - $paid)) }}</td></tr>
            @endforeach
        </table></div>
        @php($awaitingVerification = $lot->payments->filter(fn ($p) => $p->expense?->status === 'pending' && $p->effectiveAmount() > 0))
        @if ($awaitingVerification->isNotEmpty())<p class="panel-body muted" style="padding-bottom:0">{{ Money::format((int) $awaitingVerification->sum('amount')) }} of the paid amount is recorded but still awaiting Board verification; it reaches the company books when verified.</p>@endif
        <div class="panel-body">
            @can('sourcing.payment.record') @unless ($lot->isClosed())
                @foreach ($bySupplier as $supplierId => $rows)
                    @php($left = max(0, (int) $rows->sum('purchase') - \App\Services\SourcingPayments::paidTotal($lot->payments->where('supplier_id', $supplierId))))
                    @if ($left > 0)
                    <form method="POST" action="{{ route('sourcing.lots.payments.store', $lot) }}" class="inline-form" style="margin-bottom:.5rem">@csrf
                        <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><input type="hidden" name="supplier_id" value="{{ $supplierId }}">
                        <input name="amount" size="9" inputmode="decimal" value="{{ Money::toDecimal($left) }}" required title="Full balance; reduce it for a part payment">
                        <input type="date" name="paid_on" value="{{ today()->toDateString() }}" required>
                        <input name="reference" size="10" placeholder="reference"><input name="evidence_reference" size="10" placeholder="invoice / slip no.">
                        @if ($accounts->isNotEmpty())<select name="financial_account_id" title="Account paid from"><option value="">Account…</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>@endif
                        <button class="btn btn-primary">Mark supplier paid</button></form>
                    @endif
                @endforeach
            @endunless @endcan
            <h3 style="margin:.6rem 0 .3rem; font-size:.95rem">Delivery &amp; other costs</h3>
            <table class="table">
                @forelse ($costs as $c)
                    <tr><td>{{ $c->category }}{{ $c->description ? ' — '.$c->description : '' }}</td><td class="num">{{ Money::format($c->amount) }}</td>
                        <td class="num">
                            @if ($c->isPaid())
                                @if ($c->expense->status === 'verified')
                                    <span class="badge badge-paid">Paid</span>
                                @else
                                    <span class="badge badge-partial">Paid — awaiting verification</span>
                                @endif
                            @else
                                <span class="badge badge-unpaid">Unpaid</span>
                                @can('sourcing.payment.record') @unless ($lot->isClosed())
                                <form method="POST" action="{{ route('sourcing.costs.pay', $c) }}" class="inline-form">@csrf<input type="date" name="paid_on" value="{{ today()->toDateString() }}" required><button class="btn btn-sm btn-primary">Mark paid</button></form>
                                @endunless @endcan
                            @endif
                        </td></tr>
                @empty
                    <tr><td class="muted" colspan="3">No delivery or other costs.</td></tr>
                @endforelse
            </table>
        </div>
    </section>
</div>

@if ($awaiting->isNotEmpty() && ! $lot->isClosed())
<section class="panel">
    <div class="panel-head"><h2>Goods received</h2><span class="sub">Record what actually arrived. The lot cannot be closed until this is on record.</span></div>
    <div class="panel-body">
        @foreach ($awaiting as $line)
            @can('sourcing.manage')
            <form method="POST" action="{{ route('sourcing.lines.arrival', $line) }}" class="inline-form" style="margin-bottom:.4rem">@csrf
                <strong style="min-width:9rem">{{ $line->description }}</strong>
                <input name="received_quantity" size="7" inputmode="decimal" value="{{ $line->quantity }}" required title="Quantity received">
                <input type="date" name="arrived_on" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required>
                <input name="acceptance_reference" size="14" placeholder="delivery / inspection ref (optional)">
                <button class="btn btn-sm">Mark received</button></form>
            @endcan
        @endforeach
    </div>
</section>
@endif

<section class="panel">
    <div class="panel-head"><h2>Profit &amp; cash</h2></div>
    <div class="table-wrap"><table class="table">
        <tr><td>Client selling total</td><td class="num">{{ Money::format($f['revenue']) }}</td></tr>
        <tr><td>− Actual sourcing costs (supplier + delivery)</td><td class="num">{{ Money::format($f['landed']) }}</td></tr>
        <tr><td class="strong">= Sourcing profit / loss</td><td class="num strong {{ $f['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::format($f['profit']) }}</td></tr>
        <tr><td class="muted" colspan="2">Cash so far (separate from profit)</td></tr>
        <tr><td>Collected from the client</td><td class="num">{{ Money::format($f['received']) }}</td></tr>
        <tr><td>Paid out (supplier + costs)</td><td class="num">{{ Money::format($f['supplier_paid'] + $f['costs_paid']) }}</td></tr>
        <tr><td>Still to pay out</td><td class="num">{{ Money::format($f['supplier_outstanding'] + $f['costs_unpaid']) }}</td></tr>
    </table></div>
    <div class="panel-foot muted">Sourcing profit does not go to the partners directly: it flows into the company's monthly result and the 20/5/25/50 allocation.</div>
    @if ($canClose)
        @can('sourcing.settle')
        <div class="panel-body"><form method="POST" action="{{ route('sourcing.lots.close', $lot) }}">@csrf<button class="btn btn-primary">Close lot</button> <span class="muted">Everything is paid; closing settles the lot financially.</span></form></div>
        @endcan
    @elseif ($open && $paidUp && $blockers !== [])
        <div class="panel-body muted">Before this lot can be closed: {{ implode(' ', $blockers) }}</div>
    @endif
</section>

<details class="panel"><summary class="panel-head" style="cursor:pointer"><h2>Advanced</h2><span class="sub">Unexpected costs, receiving &amp; returns, detailed costing, Emergency Fund, audit history</span></summary>
    <div class="panel-body" style="display:flex; gap:.5rem; flex-wrap:wrap">
        @foreach (['lines' => 'Lines & costs', 'receiving' => 'Receiving & documents', 'pricing' => 'Costing & pricing', 'billing' => 'Client billing', 'payments' => 'Payments & settlement', 'emergency' => 'Emergency Fund', 'activity' => 'Audit history'] as $key => $label)
            <a class="btn btn-sm" href="{{ route('sourcing.lots.show', [$lot, 'tab' => $key]) }}">{{ $label }}</a>
        @endforeach
    </div>
</details>
