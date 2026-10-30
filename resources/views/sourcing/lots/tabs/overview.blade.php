@use('App\Support\Money')
<div class="metrics-auto">
    <div class="metric"><div class="label">Landed cost (actual)</div><div class="value">{{ Money::format($f['landed']) }}</div></div>
    <div class="metric"><div class="label">Sales value</div><div class="value">{{ Money::format($f['revenue']) }}</div></div>
    <div class="metric @if ($f['profit'] < 0) is-danger @endif"><div class="label">{{ $f['profit'] < 0 ? 'Loss' : 'Profit' }}</div><div class="value">{{ Money::format($f['profit']) }}</div></div>
    <div class="metric"><div class="label">Margin on sales / markup on cost</div><div class="value">{{ $f['margin_on_sales'] ?? '—' }}% / {{ $f['markup_on_cost'] ?? '—' }}%</div></div>
    <div class="metric @if ($f['supplier_outstanding'] > 0) is-danger @endif"><div class="label">Supplier payable</div><div class="value">{{ Money::format($f['supplier_outstanding']) }}</div></div>
    <div class="metric"><div class="label">Client receivable (billed − received)</div><div class="value">{{ Money::format($f['receivable']) }}</div></div>
</div>

<div class="grid-3" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:1rem">
    <section class="panel">
        <div class="panel-head"><h2>A. Initial estimate</h2></div>
        <div class="table-wrap"><table class="table">
            <tr><td>Product purchase cost</td><td class="num">{{ Money::format($f['purchase']) }}</td></tr>
            <tr><td>Estimated additional costs</td><td class="num">{{ Money::format($f['estimate_additional']) }}</td></tr>
            <tr><td><strong>Estimated landed cost</strong></td><td class="num strong">{{ Money::format($f['estimate_landed']) }}</td></tr>
        </table></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>B. Approved pricing snapshot</h2></div>
        @if ($lot->approved_at)
        <div class="table-wrap"><table class="table">
            <tr><td>Approved sales value</td><td class="num">{{ Money::format($lot->approved_revenue) }}</td></tr>
            <tr><td>Landed cost at approval</td><td class="num">{{ Money::format($lot->approved_cost) }}</td></tr>
            <tr><td><strong>Expected margin at approval</strong></td><td class="num strong">{{ Money::format($f['approved_profit']) }}</td></tr>
            <tr><td colspan="2" class="muted">Approved {{ $lot->approved_at->format('j M Y') }}. Client prices stay fixed when costs change.</td></tr>
        </table></div>
        @else
            <div class="panel-body muted">Pricing has not been approved yet.</div>
        @endif
    </section>
    <section class="panel">
        <div class="panel-head"><h2>C. Actual result</h2></div>
        <div class="table-wrap"><table class="table">
            <tr><td>Actual sourcing costs</td><td class="num">{{ Money::format($f['landed']) }}</td></tr>
            <tr><td>Sales value (agreed + unallocated at lot price)</td><td class="num">{{ Money::format($f['sales_value']) }}</td></tr>
            <tr><td>Client cost recovery (agreed)</td><td class="num">{{ Money::format($f['recoveries']) }}</td></tr>
            <tr><td><strong>Actual profit / loss</strong></td><td class="num strong {{ $f['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::format($f['profit']) }}</td></tr>
            <tr><td>Cost variance vs. approval</td><td class="num">{{ $f['cost_variance'] === null ? '—' : Money::format($f['cost_variance']) }}</td></tr>
            <tr><td>Unexpected expenses</td><td class="num">{{ Money::format($f['unexpected']) }}</td></tr>
            <tr><td>Emergency Fund utilised / open</td><td class="num">{{ Money::format($f['fund_utilised']) }} / {{ Money::format($f['fund_pending'] + $f['fund_approved']) }}</td></tr>
            <tr><td>Billed / received</td><td class="num">{{ Money::format($f['billed']) }} / {{ Money::format($f['received']) }}</td></tr>
        </table></div>
    </section>
</div>

@if ($f['fund_excess'] > 0)
    <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div>Emergency coverage ({{ Money::format($f['fund_live']) }}) now exceeds the remaining loss ({{ Money::format($f['uncovered_loss']) }}). Consider reversing the excess.</div></div>
@endif
@if ($lot->notes)<p class="muted">{{ $lot->notes }}</p>@endif
<p class="muted">Main Finance is cash-basis for income: client money counts when ClientHub receives it, and sourcing outflows count as expenses when paid (after Board verification). The figures above are the lot's accrual view and are never added to those postings.</p>
