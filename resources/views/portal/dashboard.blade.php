@use('App\Support\Money')
@use('Illuminate\Support\Str')
@php
    $m = fn (int $v) => Money::format($v, config('finance.currency'));
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $e = $o['economics'];
    $w = $o['client_wide'];
    $pending = (bool) $e['pending'];
    $bill = $o['billing'];
    $invPending = $e['investment'] === 0 && $bill['awaiting_count'] > 0;
    $awaitingUnits = $o['stock_awaiting']['available'];
    $c = $o['counts'];
    $stockTotal = array_sum(array_map('floatval', $o['stock']));
    $stockLabels = ['available' => 'Available', 'reserved' => 'Reserved', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'returned' => 'Returned', 'damaged' => 'Damaged'];
    $inProgress = $c['confirmed'] + $c['packed'] + $c['dispatched'];
    $orderTotal = max(1, $c['total']);
    $orderSegs = [['Delivered', $c['delivered'], 's-available'], ['In progress', $inProgress, 's-open'], ['Not yet confirmed', $c['draft'], 's-reserved'], ['Failed delivery', $c['failed'], 's-failed'], ['Cancelled', $c['cancelled'], 's-cancelled']];
@endphp
<x-portal-layout title="Overview" :subtitle="'Welcome back, '.$o['client']['name'].'. Figures come from Sellbees\' verified records; anything not yet verified is marked Pending.'">
    <div class="pt-grid pt-g4">
        <x-portal.kpi tone="primary" :pending="$invPending" icon="wallet" label="Approved product investment" :value="$invPending ? 'Pending verification' : $m($e['investment'])" :hint="$invPending ? $bill['awaiting_count'].' sourced lot(s) not yet verified, see product billing below' : ($bill['awaiting_count'] > 0 ? 'Verified lots only; '.$bill['awaiting_count'].' more pending verification' : 'Your approved cost of the products sourced')" />
        <x-portal.kpi tone="primary" icon="trending-up" label="Successful sales" :value="$m($o['successful_sales'])" :hint="$o['successful_orders'] > 0 ? 'Delivered and not returned' : 'No customer order delivered yet'" />
        <x-portal.kpi tone="primary" :pending="$pending" icon="chart" label="Realised product profit" :value="$pending ? 'Pending verification' : $m($e['client_profit'])" :hint="$pending ? 'Shown once product costs are verified' : ($o['successful_orders'] > 0 ? 'After your product cost, deducted once' : 'Appears after your first delivered sale; stock you paid for is not a loss')" />
        <x-portal.kpi tone="primary" icon="cash" label="Settlement entitlement" :value="$m($w['entitlement'])" hint="Earned from delivered sales, all projects" />
    </div>

    <div class="pt-grid pt-g5 keep2">
        <x-portal.kpi tone="sm" icon="folder" label="Active projects" :value="$o['active_projects']" />
        <x-portal.kpi tone="sm" icon="package" label="Available stock" :value="$qty($o['stock']['available'])" :hint="bccomp((string) $awaitingUnits, '0', 2) > 0 ? 'units, plus '.$qty($awaitingUnits).' awaiting ownership confirmation' : 'units'" />
        <x-portal.kpi tone="sm" icon="layers" label="Total orders" :value="$o['orders']" />
        <x-portal.kpi tone="sm" icon="check-circle" label="Successful orders" :value="$o['successful_orders']" />
        <x-portal.kpi tone="sm" icon="truck" label="Pending deliveries" :value="$o['pending_deliveries']" hint="confirmed, packed or dispatched" />
    </div>

    @include('portal._billing', ['billing' => $bill, 'fin' => $o['financials']])

    <section class="pt-card" aria-labelledby="h-proj" style="margin-bottom:1.25rem">
        <div class="pt-card-head"><h2 id="h-proj">Project performance</h2><a class="pt-link" href="{{ route('portal.projects') }}">All projects</a></div>
        @if (count($o['project_cards']))
        <div class="pt-table-wrap"><table class="pt-table stack">
            <thead><tr><th>Project</th><th class="r">Investment</th><th class="r">Successful sales</th><th class="r">Net profit</th><th class="r">Stock available</th><th class="r">Orders</th></tr></thead>
            <tbody>
            @foreach ($o['project_cards'] as $p)
                <tr><td class="lead"><a class="pt-link" href="{{ route('portal.projects', ['project' => $p['project']->id]) }}">{{ $p['project']->name }}</a> <x-portal.status kind="project" :value="$p['project']->status->label()" /></td>
                    <td class="r num" data-label="Investment">{{ $m($p['investment']) }}</td><td class="r num" data-label="Successful sales">{{ $m($p['gross_sales']) }}</td>
                    <td class="r num" data-label="Net profit">@if ($p['cost_pending'])<span class="pt-chip warn plain">Pending</span>@else {{ $m($p['client_profit']) }}@endif</td>
                    <td class="r num" data-label="Stock available">{{ $qty($p['stock']['available']) }}</td><td class="r num" data-label="Orders">{{ $p['orders'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @else
            <x-portal.empty icon="folder" title="No projects yet">Your projects will appear here as soon as Sellbees sets them up for you.</x-portal.empty>
        @endif
    </section>

    <div class="pt-grid pt-g2">
        <section class="pt-card" aria-labelledby="h-stock">
            <div class="pt-card-head"><h2 id="h-stock">Inventory status</h2><a class="pt-link" href="{{ route('portal.inventory') }}">View stock</a></div>
            <div class="pt-card-body">
                @if ($stockTotal > 0)
                    <div class="pt-stack" role="img" aria-label="Stock by status">@foreach ($stockLabels as $k => $label)@if ((float) $o['stock'][$k] > 0)<i class="s-{{ $k }}" style="width: {{ round((float) $o['stock'][$k] / $stockTotal * 100, 2) }}%" title="{{ $label }}"></i>@endif @endforeach</div>
                    <div class="pt-legend">@foreach ($stockLabels as $k => $label)<div><span class="pt-dot s-{{ $k }}"></span>{{ $label }}<b>{{ $qty($o['stock'][$k]) }}</b></div>@endforeach</div>
                @else
                    <x-portal.empty icon="package" title="No stock received yet">Once your products arrive, you will see how many are available, reserved, dispatched and delivered.</x-portal.empty>
                @endif
            </div>
        </section>

        <section class="pt-card" aria-labelledby="h-ful">
            <div class="pt-card-head"><h2 id="h-ful">Order fulfilment</h2><a class="pt-link" href="{{ route('portal.orders') }}">All orders</a></div>
            <div class="pt-card-body">
                @if ($c['total'] > 0)
                    <div class="pt-stack" role="img" aria-label="Orders by stage">@foreach ($orderSegs as [$l, $n, $cls])@if ($n > 0)<i class="{{ $cls }}" style="width: {{ round($n / $orderTotal * 100, 2) }}%" title="{{ $l }}"></i>@endif @endforeach</div>
                    <div class="pt-legend">@foreach ($orderSegs as [$l, $n, $cls])<div><span class="pt-dot {{ $cls }}"></span>{{ $l }}<b>{{ $n }}</b></div>@endforeach</div>
                @else
                    <x-portal.empty icon="truck" title="No orders yet">Orders for your products will be tracked here from confirmation to delivery.</x-portal.empty>
                @endif
            </div>
        </section>
    </div>

    <div class="pt-grid pt-g2">
        <section class="pt-card" aria-labelledby="h-set">
            <div class="pt-card-head"><h2 id="h-set">Settlement</h2><span class="sub">All your projects together, not split by project</span></div>
            <div class="pt-card-body">
                <dl class="pt-dl" style="grid-template-columns: 1fr auto; gap:.65rem 1rem">
                    <dt>Settled to you so far</dt><dd class="num" style="text-align:right">{{ $m($w['settled']) }}</dd>
                    <dt>Still owed to you</dt><dd class="num" style="text-align:right">{{ $m($w['outstanding']) }}</dd>
                    <dt>Collected and held, ready to settle</dt><dd class="num" style="text-align:right">{{ $m($w['cash_available']) }}</dd>
                    <dt>Owed, not yet received from couriers</dt><dd class="num" style="text-align:right">{{ $m($w['pending_remittance']) }}</dd>
                </dl>
            </div>
            <div class="pt-card-foot">Profit is what your sales earned after your product cost. Entitlement is what Sellbees owes you from sales. Cash is only what has actually been collected.</div>
        </section>

        <section class="pt-card" aria-labelledby="h-inv">
            <div class="pt-card-head"><h2 id="h-inv">Outstanding invoices</h2><a class="pt-link" href="{{ route('portal.invoices') }}">All invoices</a></div>
            <div class="pt-card-body">
                @if ($o['invoices']['open'] > 0)
                    <div class="pt-kpi-value" style="margin:0 0 .15rem">{{ $m($o['invoices']['outstanding']) }}</div>
                    <div class="muted" style="font-size:.84rem;margin-bottom:1rem">across {{ $o['invoices']['open'] }} open {{ Str::plural('invoice', $o['invoices']['open']) }}</div>
                    @foreach ($o['invoices']['open_list'] as $inv)
                        <div style="display:flex;justify-content:space-between;gap:1rem;padding:.55rem 0;border-top:1px solid var(--border)">
                            <span><strong>{{ $inv->number }}</strong><br><small class="muted">{{ $inv->due_on ? 'Due '.$inv->due_on->format('j M Y') : 'Issued '.$inv->issued_on->format('j M Y') }}</small></span>
                            <span class="num" style="text-align:right">{{ $m($inv->balance()) }}<br>@if ($inv->isOverdue())<x-portal.status kind="invoice" value="Overdue" />@endif</span>
                        </div>
                    @endforeach
                @else
                    <x-portal.empty icon="check-circle" title="Nothing outstanding">All your invoices are paid. Thank you.</x-portal.empty>
                @endif
            </div>
        </section>
    </div>

    <section class="pt-card" aria-labelledby="h-rec">
        <div class="pt-card-head"><h2 id="h-rec">Recent orders</h2><a class="pt-link" href="{{ route('portal.orders') }}">View all</a></div>
        @if ($o['recent_orders']->isNotEmpty())
        <div class="pt-table-wrap"><table class="pt-table stack">
            <thead><tr><th>Order</th><th>Products</th><th>Status</th><th class="r">Selling amount</th><th class="r">Your entitlement</th></tr></thead>
            <tbody>@foreach ($o['recent_orders'] as $r)@php($ord = $r['order'])
                <tr><td class="lead"><strong>{{ $ord->reference }}</strong><small>{{ $ord->ordered_on->format('j M Y') }} · {{ $ord->project->name }}</small></td><td data-label="Products">{{ $r['products'] }}</td>
                    <td data-label="Status"><x-portal.status kind="order" :value="$ord->fulfilmentLabel()" /></td>
                    <td class="r num" data-label="Selling amount"><div>{{ $m($ord->delivery_status === 'delivered' ? $r['sales'] : $ord->orderedValue()) }}@if ($ord->delivery_status !== 'delivered')<small>ordered</small>@endif</div></td>
                    <td class="r num" data-label="Your entitlement">{{ $m($r['entitlement']) }}</td></tr>
            @endforeach</tbody>
        </table></div>
        @else
            <x-portal.empty icon="truck" title="No orders yet">Your latest orders will appear here.</x-portal.empty>
        @endif
    </section>
</x-portal-layout>
