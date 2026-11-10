@use('App\Support\Money')
@use('App\Services\ClientFinancials')
@use('App\Support\InvoiceCategories')
@php
    $m = fn (int $v) => Money::format($v, config('finance.currency'));
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $t = $fin['totals'];
    $e = $o['economics'];
    $bill = $o['billing'];
    $invPending = $e['investment'] === 0 && $bill['awaiting_count'] > 0;
    $unc = $fin['unclassified'];
    $sources = ClientFinancials::sourceLabels();
@endphp
<x-portal-layout title="My Financial Overview" subtitle="Everything you have been invoiced and have paid to Sellbees, grouped by what it was for. Invoices are never counted as cash received, and a payment is never counted as a sale.">
    <div class="pt-grid pt-g3">
        <x-portal.kpi tone="primary" icon="invoice" label="Total billed" :value="$m($t['invoiced'])" :hint="$t['invoices'].' '.\Illuminate\Support\Str::plural('invoice', $t['invoices'])" />
        <x-portal.kpi tone="primary" icon="check-circle" label="Total paid" :value="$m($t['paid'])" hint="Verified payments only; voided payments are not counted" />
        <x-portal.kpi tone="primary" icon="alert" label="Total due" :value="$m($t['due'])" hint="Billed less paid, invoice by invoice" />
    </div>

    @if ($unc['lines'] > 0)
        <div class="pt-flash info" role="status"><x-icon name="alert" /> {{ $unc['lines'] }} invoice {{ \Illuminate\Support\Str::plural('line', $unc['lines']) }} ({{ $m($unc['billed']) }}) have no category yet. They are included in every total below and shown as "{{ InvoiceCategories::NEEDS_CLASSIFICATION }}" until Sellbees classifies them.</div>
    @endif

    <section class="pt-card" aria-labelledby="h-cat" style="margin-bottom:1.25rem">
        <div class="pt-card-head"><h2 id="h-cat">By category</h2><span class="sub">Invoices that belong to one category</span></div>
        @if (count($fin['by_category']) || $fin['mixed']['count'])
        <div class="pt-table-wrap"><table class="pt-table stack">
            <thead><tr><th>Category</th><th class="r">Invoices</th><th class="r">Billed</th><th class="r">Paid</th><th class="r">Due</th><th class="r"></th></tr></thead>
            <tbody>
            @foreach ($fin['by_category'] as $key => $c)
                <tr><td class="lead"><strong>{{ $c['label'] }}</strong>@if ($key === InvoiceCategories::UNCLASSIFIED)<small>Waiting for Sellbees to classify</small>@endif</td><td class="r num" data-label="Invoices">{{ $c['invoices'] }}</td><td class="r num" data-label="Billed">{{ $m($c['billed']) }}</td><td class="r num" data-label="Paid">{{ $m($c['paid']) }}</td><td class="r num" data-label="Due">{{ $m($c['due']) }}</td>
                    <td class="r"><a class="pt-link" href="{{ route('portal.finance', ['category' => $key]) }}#invoices">View invoices</a></td></tr>
            @endforeach
            @if ($fin['mixed']['count'])
                <tr><td class="lead"><strong>Invoices with several categories</strong><small>Paid at invoice level: category allocation pending</small></td><td class="r num" data-label="Invoices">{{ $fin['mixed']['count'] }}</td><td class="r num" data-label="Billed">{{ $m($fin['mixed']['billed']) }}</td><td class="r num" data-label="Paid">{{ $m($fin['mixed']['paid']) }}</td><td class="r num" data-label="Due">{{ $m($fin['mixed']['due']) }}</td>
                    <td class="r"><a class="pt-link" href="{{ route('portal.finance', ['category' => 'mixed']) }}#invoices">View invoices</a></td></tr>
            @endif
            </tbody>
            <tfoot><tr><td>Total</td><td class="r num">{{ $t['invoices'] }}</td><td class="r num">{{ $m($t['invoiced']) }}</td><td class="r num">{{ $m($t['paid']) }}</td><td class="r num">{{ $m($t['due']) }}</td><td class="r">@if ($fin['reconciled'])<span class="pt-chip ok">Reconciles</span>@endif</td></tr></tfoot>
        </table></div>
        @else
            <x-portal.empty icon="invoice" title="No invoices yet">Invoices from Sellbees will appear here, grouped by what they are for.</x-portal.empty>
        @endif
    </section>

    @if (count($fin['by_line']) && $fin['mixed']['count'])
    <section class="pt-card" aria-labelledby="h-line" style="margin-bottom:1.25rem">
        <div class="pt-card-head"><h2 id="h-line">Billed by invoice line</h2><span class="sub">Every line of every invoice, including those on mixed invoices</span></div>
        <div class="pt-table-wrap"><table class="pt-table stack"><thead><tr><th>Category</th><th class="r">Billed</th></tr></thead><tbody>
            @foreach ($fin['by_line'] as $l)<tr><td class="lead">{{ $l['label'] }}</td><td class="r num" data-label="Billed">{{ $m($l['billed']) }}</td></tr>@endforeach
        </tbody><tfoot><tr><td>Total billed</td><td class="r num">{{ $m($t['invoiced']) }}</td></tr></tfoot></table></div>
    </section>
    @endif

    <div class="pt-grid pt-g2">
        <section class="pt-card" aria-labelledby="h-prod">
            <div class="pt-card-head"><h2 id="h-prod">Product sourcing</h2><span class="sub">Kept apart from other services</span></div>
            <div class="pt-card-body"><dl class="pt-dl" style="grid-template-columns: 1fr auto; gap:.65rem 1rem">
                <dt>Product amount billed</dt><dd class="num" style="text-align:right">{{ $m($fin['product']['billed']) }}</dd>
                <dt>Paid on product invoices</dt><dd class="num" style="text-align:right">{{ $m($fin['product']['paid']) }}</dd>
                <dt>Due on product invoices</dt><dd class="num" style="text-align:right">{{ $m($fin['product']['due']) }}</dd>
                <dt>Approved product investment</dt><dd class="num" style="text-align:right">{{ $invPending ? 'Pending verification' : $m($e['investment']) }}</dd>
                <dt>Received units</dt><dd class="num" style="text-align:right">{{ $qty($o['received_units']) }}</dd>
                <dt>Available to sell (verified ownership)</dt><dd class="num" style="text-align:right">{{ $qty($o['stock']['available']) }}</dd>
                @if (bccomp((string) $o['stock_awaiting']['available'], '0', 2) > 0)<dt>Received, ownership being confirmed</dt><dd class="num" style="text-align:right">{{ $qty($o['stock_awaiting']['available']) }}</dd>@endif
            </dl>
            @if ($fin['product']['in_mixed_billed'] > 0)<p class="pt-note" style="margin-top:.75rem">{{ $m($fin['product']['in_mixed_billed']) }} of product lines sit on mixed invoices; their payments ({{ $m($fin['product']['mixed_paid_pending']) }} on those invoices) are paid at invoice level and not yet allocated to product.</p>@endif
            @if ($fin['product']['billed'] === 0 && $unc['lines'] > 0)<p class="pt-note" style="margin-top:.75rem">No invoice line is classified as product sourcing yet, but {{ $unc['lines'] }} {{ \Illuminate\Support\Str::plural('line', $unc['lines']) }} still need a category, so this is not a statement that nothing was bought.</p>@endif
            </div>
            <div class="pt-card-foot">Your approved product cost is counted once, in your profit. A product invoice is not a sale.</div>
        </section>
        <section class="pt-card" aria-labelledby="h-res">
            <div class="pt-card-head"><h2 id="h-res">Sales, profit &amp; settlement</h2><a class="pt-link" href="{{ route('portal.sales') }}">Open Sales &amp; Profit</a></div>
            <div class="pt-card-body"><dl class="pt-dl" style="grid-template-columns: 1fr auto; gap:.65rem 1rem">
                <dt>Successful customer sales</dt><dd class="num" style="text-align:right">{{ $m($o['successful_sales']) }}</dd>
                <dt>Realised product profit</dt><dd class="num" style="text-align:right">{{ $e['pending'] ? 'Pending verification' : $m($e['client_profit']) }}</dd>
                <dt>Settlement entitlement earned</dt><dd class="num" style="text-align:right">{{ $m($o['client_wide']['entitlement']) }}</dd>
                <dt>Settled to you</dt><dd class="num" style="text-align:right">{{ $m($o['client_wide']['settled']) }}</dd>
                <dt>Still owed to you</dt><dd class="num" style="text-align:right">{{ $m($o['client_wide']['outstanding']) }}</dd>
            </dl></div>
            <div class="pt-card-foot">Money you paid to Sellbees and money Sellbees owes you from sales are separate. {{ $o['successful_orders'] === 0 ? 'No customer order has been delivered yet, so these stay at zero.' : 'Settlement figures cover all your projects together.' }}</div>
        </section>
    </div>

    <section class="pt-card" id="invoices" aria-labelledby="h-inv" style="margin-top:1.25rem">
        <div class="pt-card-head"><h2 id="h-inv">Invoices{{ $category !== '' ? ': '.($category === 'mixed' ? 'several categories' : InvoiceCategories::label($category)) : '' }}</h2>@if ($category !== '')<a class="pt-link" href="{{ route('portal.finance') }}#invoices">Show all invoices</a>@endif</div>
        @forelse ($invoices as $i)
            <details style="border-bottom:1px solid var(--border)"@if ($category !== '' || $loop->first) open @endif>
                <summary style="cursor:pointer;padding:.9rem 1.25rem;display:flex;gap:1rem;flex-wrap:wrap;align-items:center;justify-content:space-between">
                    <span><strong>{{ $i['number'] }}</strong> <span class="muted">· {{ $i['issued_on']->format('j M Y') }} · {{ $i['category_label'] }}</span></span>
                    <span class="num">{{ $m($i['total']) }} billed · {{ $m($i['paid']) }} paid · <strong>{{ $m($i['due']) }} due</strong> <x-portal.status kind="invoice" :value="$i['status']" /></span>
                </summary>
                <div class="pt-table-wrap" style="padding:0 1.25rem 1rem"><table class="pt-table stack">
                    <thead><tr><th>Line</th><th>Category</th><th class="r">Amount</th></tr></thead>
                    <tbody>@foreach ($i['lines'] as $l)<tr><td class="lead">{{ $l['description'] }}@if ($l['quantity'] !== '')<small>{{ $l['quantity'] }}</small>@endif</td><td data-label="Category"><div>{{ InvoiceCategories::label($l['key']) }}<small>{{ $sources[$l['source']] }}</small></div></td><td class="r num" data-label="Amount">{{ $m($l['amount']) }}</td></tr>@endforeach</tbody>
                </table></div>
                <div class="pt-table-wrap" style="padding:0 1.25rem 1.1rem"><table class="pt-table stack">
                    <thead><tr><th>Verified payment</th><th>Method</th><th class="r">Amount</th></tr></thead>
                    <tbody>
                    @foreach ($i['payments'] as $p)<tr><td class="lead">{{ $p['date']->format('j M Y') }}</td><td data-label="Method">{{ $p['method'] }}</td><td class="r num" data-label="Amount">{{ $m($p['amount']) }}</td></tr>@endforeach
                    @if ($i['segment_paid'] > 0)<tr><td class="lead">Received on the project</td><td data-label="Method">Recorded against the project, then applied to this invoice</td><td class="r num" data-label="Amount">{{ $m($i['segment_paid']) }}</td></tr>@endif
                    @if (! count($i['payments']) && $i['segment_paid'] === 0)<tr><td colspan="3" class="muted">No payment yet.</td></tr>@endif
                    </tbody>
                </table></div>
                <div style="padding:0 1.25rem 1rem"><a class="pt-btn pt-btn-sm" href="{{ route('portal.invoices.pdf', $i['id']) }}" target="_blank" rel="noopener"><x-icon name="eye" /> View invoice</a></div>
            </details>
        @empty
            <x-portal.empty icon="invoice" title="No invoices to show">There are no invoices in this category.</x-portal.empty>
        @endforelse
    </section>
</x-portal-layout>