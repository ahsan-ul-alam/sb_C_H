@use('App\Support\Money')
{{-- Sales figures from SalesAnalytics::figures(): shared by the project dashboard and the Sales Analytics page. Read-only. Needs $a. --}}
@php($pct = fn ($bps) => $bps === null ? '—' : number_format($bps / 100, 1).'%')
<section class="panel">
    <div class="panel-head"><h2>Sales performance</h2><span class="sub">from the order records; a delivered order counts once and only for what was delivered and not returned</span></div>
    <div class="panel-body">
        <div class="stat-grid">
            <div class="stat"><span class="stat-label">Successful sales</span><span class="stat-value">{{ Money::format($a['totals']['sales']) }}</span></div>
            <div class="stat"><span class="stat-label">Successful orders</span><span class="stat-value">{{ $a['successful_orders'] }}</span></div>
            <div class="stat"><span class="stat-label">Average order value</span><span class="stat-value">{{ $a['average_order_value'] === null ? '—' : Money::format($a['average_order_value']) }}</span></div>
            <div class="stat"><span class="stat-label">Success rate</span><span class="stat-value">{{ $pct($a['success_rate_bps']) }}</span></div>
            <div class="stat"><span class="stat-label">Failure rate</span><span class="stat-value">{{ $pct($a['failure_rate_bps']) }}</span></div>
            <div class="stat"><span class="stat-label">Return rate (orders with a return)</span><span class="stat-value">{{ $pct($a['return_rate_bps']) }}</span></div>
            <div class="stat"><span class="stat-label">Unit return rate</span><span class="stat-value">{{ $pct($a['unit_return_rate_bps']) }}</span></div>
            <div class="stat"><span class="stat-label">Delivery charges / commission</span><span class="stat-value">{{ Money::format($a['totals']['delivery']) }} / {{ Money::format($a['totals']['commission']) }}</span></div>
        </div>
        <div class="stat-grid" style="margin-top:.6rem">
            @foreach ([['Draft', 'draft'], ['Confirmed', 'confirmed'], ['Packed', 'packed'], ['Dispatched', 'dispatched'], ['Delivered', 'delivered'], ['Failed', 'failed'], ['Cancelled', 'cancelled'], ['Total orders', 'total']] as [$label, $k])
                <div class="stat"><span class="stat-label">{{ $label }}</span><span class="stat-value">{{ $a['counts'][$k] }}</span></div>
            @endforeach
        </div>
    </div>
    <div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>Period</th><th class="num">Monthly orders</th><th class="num">Monthly sales</th><th>Week</th><th class="num">Orders</th><th class="num">Sales</th><th>Day</th><th class="num">Orders</th><th class="num">Sales</th></tr></thead>
        <tbody>
        @php($m = array_slice($a['monthly'], 0, 6, true))
        @php($w = array_slice($a['weekly'], 0, 6, true))
        @php($d = array_slice($a['daily'], 0, 6, true))
        @php($rows = max(count($m), count($w), count($d)))
        @for ($i = 0; $i < $rows; $i++)
            @php($mk = array_keys($m)[$i] ?? null)
            @php($wk = array_keys($w)[$i] ?? null)
            @php($dk = array_keys($d)[$i] ?? null)
            <tr>
                <td>{{ $mk ?? '' }}</td><td class="num">{{ $mk ? $m[$mk]['orders'] : '' }}</td><td class="num">{{ $mk ? Money::format($m[$mk]['sales']) : '' }}</td>
                <td>{{ $wk ?? '' }}</td><td class="num">{{ $wk ? $w[$wk]['orders'] : '' }}</td><td class="num">{{ $wk ? Money::format($w[$wk]['sales']) : '' }}</td>
                <td>{{ $dk ?? '' }}</td><td class="num">{{ $dk ? $d[$dk]['orders'] : '' }}</td><td class="num">{{ $dk ? Money::format($d[$dk]['sales']) : '' }}</td>
            </tr>
        @endfor
        @if ($rows === 0)
            <tr><td colspan="9" class="muted">No successful sales yet.</td></tr>
        @endif
        </tbody>
    </table></div>
</section>

<section class="panel">
    <div class="panel-head"><h2>Top-selling products</h2></div>
    <div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>Product</th><th class="num">Units sold (net of returns)</th><th class="num">Sales</th><th class="num">Orders</th></tr></thead>
        <tbody>
        @forelse ($a['top_products'] as $p)
            <tr><td>{{ $p['name'] }}</td><td class="num">{{ $p['units'] }}</td><td class="num">{{ Money::format($p['sales']) }}</td><td class="num">{{ $p['orders'] }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">Nothing sold yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>