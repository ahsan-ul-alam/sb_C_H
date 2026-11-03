@use('App\Support\Money')
@use('App\Support\DateRange')
@php
    $cur = config('finance.currency');
    $m = fn (int $v): string => Money::format($v, $cur);
    $labels = $series['months'];
    $vals = fn (string $key): array => array_values($series[$key]);
    $maxCategory = max(1, (int) $categories->max('total'));
    $cards = [
        ['Business performance', [
            ['Total recognised revenue', $kpis['recognised_revenue'], 'Earned in the period — not the same as cash received', null],
            ['Gross profit', $kpis['gross_profit'], 'Recognised revenue − direct project costs', null],
            ['Accounting net profit', $kpis['net_profit'], 'Not the Board-approved distributable profit — see below', null],
        ]],
        ['Cash position', [
            ['Available cash & bank', $kpis['available_cash'], 'To date · money actually in your accounts', null],
            ['Total payments received', $kpis['payments_received'], 'Cash collected from customers in the period', null],
            ['Accounts payable', $kpis['accounts_payable'], 'To date · verified expenses not yet paid', null],
            ['Loans outstanding', $kpis['loans_outstanding'], 'To date · principal owed; borrowed money is not income', null],
        ]],
        ['Investments & contracts', [
            ['Total capital investment', $kpis['capital_invested'], 'To date · owner money, never revenue or profit', null],
            ['Total project contract value', $kpis['contract_value'], 'To date · agreed charges on all projects', null],
            ['Outstanding receivables', $kpis['outstanding_receivables'], 'To date · agreed charges not yet paid — not cash', null],
        ]],
        ['Expenses', [
            ['Total project expenses', $kpis['project_expenses'], 'Direct project and sourcing costs in the period', null],
            ['Office & operating expenses', $kpis['operating_expenses'], 'Operating, other and tax expenses in the period', null],
            ['Total expenses', $kpis['total_expenses'], 'Excludes equipment purchases and payments of payables', null],
        ]],
    ];
@endphp
<x-layout title="Financial Overview">
    <x-page-header title="Financial Overview">
        <x-slot:meta>All amounts in {{ $cur }}, from verified records and the posted journal. Period: {{ $range->label() }}.</x-slot:meta>
        <x-slot:actions>
            <a class="btn" href="{{ route('finance.pnl') }}">Profit &amp; Loss</a>
            <a class="btn" href="{{ route('finance.position') }}">Financial position</a>
            <a class="btn" href="{{ route('finance.reconciliation') }}">Reconciliation</a>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('finance.overview') }}" class="toolbar">
        <div class="field">
            <label for="range">Period</label>
            <select id="range" name="range" onchange="document.querySelectorAll('.custom-dates').forEach(e => e.hidden = this.value !== 'custom')">
                @foreach (DateRange::PRESETS as $key => $label)
                    <option value="{{ $key }}" @selected($range->preset === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field custom-dates" @if ($range->preset !== 'custom') hidden @endif><label for="from">From</label><input type="date" id="from" name="from" value="{{ $range->from->toDateString() }}"></div>
        <div class="field custom-dates" @if ($range->preset !== 'custom') hidden @endif><label for="to">To</label><input type="date" id="to" name="to" value="{{ $range->to->toDateString() }}"></div>
        <button type="submit" class="btn">Apply</button>
    </form>

    <div class="flash" role="note"><x-icon name="alert" /> <div><strong>Profit is not cash.</strong> Profit counts revenue when it is earned and costs when they are verified. Cash is what is in your accounts. Owner investments, unpaid invoices and unpaid bills change one but not the other.</div></div>

    @foreach ($cards as [$heading, $items])
        <h2 class="metric-group-label">{{ $heading }}</h2>
        <div class="metrics-auto">
            @foreach ($items as [$label, $amount, $hint])
                <div class="metric @if ($amount < 0) is-danger @endif"><div class="label">{{ $label }}</div><div class="value">{{ $m($amount) }}</div><div class="hint">{{ $hint }}</div></div>
            @endforeach
        </div>
    @endforeach

    <section class="panel">
        <div class="panel-head"><h2>Business performance</h2><span class="sub">{{ $range->label() }}</span></div>
        <div class="table-wrap"><table class="table">
            <tbody>
                <tr><td>Recognised revenue</td><td class="num">{{ $m($pl['recognised_revenue']) }}</td></tr>
                <tr><td class="muted">− Direct project costs</td><td class="num">{{ $m($pl['direct_costs']) }}</td></tr>
                <tr class="strong"><td>Gross profit</td><td class="num">{{ $m($pl['gross_profit']) }}</td></tr>
                <tr><td class="muted">− Operating expenses</td><td class="num">{{ $m($pl['operating_expenses']) }}</td></tr>
                <tr class="strong"><td>Operating profit</td><td class="num">{{ $m($pl['operating_profit']) }}</td></tr>
                <tr><td class="muted">+ Other income</td><td class="num">{{ $m($pl['other_income']) }}</td></tr>
                <tr><td class="muted">− Other expenses</td><td class="num">{{ $m($pl['other_expenses']) }}</td></tr>
                <tr><td class="muted">− Taxes</td><td class="num">{{ $m($pl['taxes']) }}</td></tr>
                @if ($pl['estimated_tax'] > 0)<tr class="muted"><td>Memo: estimated tax at the configured rate (not posted)</td><td class="num">{{ $m($pl['estimated_tax']) }}</td></tr>@endif
                <tr class="strong"><td>Accounting net profit</td><td class="num {{ $pl['net_profit'] < 0 ? 'text-danger' : '' }}">{{ $m($pl['net_profit']) }}</td></tr>
            </tbody>
        </table></div>
        <div class="panel-body"><h3 style="margin-top:0">Monthly revenue vs expenses</h3>
            <x-series-chart title="Monthly revenue against expenses" :labels="$labels" :series="[['name' => 'Revenue', 'color' => 'var(--success)', 'values' => $vals('revenue')], ['name' => 'Expenses', 'color' => 'var(--danger)', 'values' => $vals('expenses')]]" /></div>
        <div class="panel-body"><h3 style="margin-top:0">Monthly net profit</h3>
            <x-series-chart title="Monthly net profit" :labels="$labels" :series="[['name' => 'Net profit', 'color' => 'var(--info)', 'values' => $vals('net')]]" /></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Accounting profit vs distributable profit</h2><span class="sub">{{ $bridge['month'] }} · distributable basis: {{ $bridge['basis'] }}</span></div>
        <div class="table-wrap"><table class="table">
            <tbody>
                <tr class="strong"><td>Accounting net profit (this dashboard)</td><td class="num">{{ $m($bridge['accounting_profit']) }}</td></tr>
                <tr><td>Profit on the distributable basis, live</td><td class="num">{{ $m($bridge['distributable_basis_profit']) }}</td></tr>
                <tr><td>Profit frozen at period close @if ($bridge['allocation_status'])<span class="muted">· allocation {{ $bridge['allocation_status'] }}</span>@endif</td><td class="num">{{ $bridge['frozen_period_profit'] === null ? 'Month not closed' : $m($bridge['frozen_period_profit']) }}</td></tr>
                @foreach ($bridge['items'] as $label => $amount)<tr class="muted"><td>Why they differ: {{ $label }}</td><td class="num">{{ $m($amount) }}</td></tr>@endforeach
                @if ($bridge['unverified_items'] > 0)<tr class="muted"><td>Unverified items still to be decided in this month</td><td class="num">{{ $bridge['unverified_items'] }}</td></tr>@endif
            </tbody>
        </table></div>
        <div class="panel-body"><p class="hint" style="margin:0">Only the profit frozen at period close and the Board-approved allocation are distributed to partners. Accounting profit is for management reporting; changing what is distributable needs a Board decision.</p></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Cash position</h2><span class="sub">Transfers between accounts never change total cash</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Account</th><th>Type</th><th class="num">Inflows</th><th class="num">Outflows</th><th class="num">Balance</th></tr></thead>
            <tbody>
            @forelse ($accounts as $a)
                <tr><td>{{ $a['name'] }}</td><td class="muted">{{ $a['type'] }}</td><td class="num">{{ $m($a['inflow']) }}</td><td class="num">{{ $m($a['outflow']) }}</td><td class="num strong {{ $a['balance'] < 0 ? 'text-danger' : '' }}">{{ $m($a['balance']) }}</td></tr>
            @empty
                <tr><td colspan="5" class="muted">No financial accounts yet. <a href="{{ route('finance.accounts') }}">Set them up</a>.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr><td colspan="4">Total available cash</td><td class="num">{{ $m($kpis['available_cash']) }}</td></tr></tfoot>
        </table></div>
        <div class="panel-body"><h3 style="margin-top:0">Cash inflow vs outflow</h3>
            <x-series-chart title="Monthly cash inflow against outflow" :labels="$labels" :series="[['name' => 'Inflow', 'color' => 'var(--success)', 'values' => $vals('inflow')], ['name' => 'Outflow', 'color' => 'var(--danger)', 'values' => $vals('outflow')]]" /></div>
        <div class="panel-body"><h3 style="margin-top:0">Cash and bank balance at month end</h3>
            <x-series-chart title="Cash and bank balance trend" :labels="$labels" :series="[['name' => 'Balance', 'color' => 'var(--gold-bar)', 'values' => $vals('balance')]]" /></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Investments &amp; capital</h2><a class="btn btn-sm" href="{{ route('finance.capital') }}">Investment ledger</a></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Partner</th><th class="num">Invested</th><th class="num">Withdrawn</th><th class="num">Losses charged</th><th class="num">Capital balance</th></tr></thead>
            <tbody>
            @foreach ($capital['rows'] as $r)
                <tr><td>{{ $r['partner']->code }} · {{ $r['partner']->name }}</td><td class="num">{{ $m($r['contributed']) }}</td><td class="num">{{ $m($r['withdrawn']) }}</td><td class="num">{{ $m($r['loss_charged']) }}</td><td class="num strong">{{ $m($r['capital_balance']) }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="panel-body"><h3 style="margin-top:0">Investment history (net of withdrawals)</h3>
            <x-series-chart title="Monthly owner investment history" :labels="$labels" :series="[['name' => 'Invested', 'color' => 'var(--info)', 'values' => $vals('invested')]]" /></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Expense categories</h2><span class="sub">{{ $range->label() }} · net of reversals · equipment purchases excluded</span></div>
        <div class="table-wrap"><table class="table">
            <tbody>
            @forelse ($categories as $c)
                <tr><td style="width:34%">{{ $c['category'] }}</td>
                    <td><div style="background:var(--neutral-bg); border-radius:4px; height:.7rem"><div style="background:var(--danger); width:{{ max(1, intdiv(max(0, $c['total']) * 100, $maxCategory)) }}%; height:100%; border-radius:4px"></div></div></td>
                    <td class="num" style="width:20%">{{ $m($c['total']) }}</td></tr>
            @empty
                <tr><td class="muted">No verified expenses in this period.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Project financial performance</h2><span class="sub">To date · gross profit = recognised revenue − direct costs; cash flow = received − paid costs − refunds</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Project</th><th class="num">Contract</th><th class="num">Received</th><th class="num">Recognised</th><th class="num">Direct costs</th><th class="num">Gross profit</th><th class="num">Margin</th><th class="num">Cash flow</th></tr></thead>
            <tbody>
            @forelse ($profitability as $r)
                <tr><td><a href="{{ route('projects.show', $r['project']) }}">{{ $r['project']->name }}</a></td>
                    <td class="num">{{ $m($r['contract_value']) }}</td><td class="num">{{ $m($r['payments_received']) }}</td><td class="num">{{ $m($r['recognised_revenue']) }}</td>
                    <td class="num">{{ $m($r['direct_costs']) }}</td><td class="num strong {{ $r['gross_profit'] < 0 ? 'text-danger' : '' }}">{{ $m($r['gross_profit']) }}</td>
                    <td class="num">{{ $r['gross_margin_bps'] === null ? '—' : number_format($r['gross_margin_bps'] / 100, 1).'%' }}</td>
                    <td class="num {{ $r['cash_flow'] < 0 ? 'text-danger' : '' }}">{{ $m($r['cash_flow']) }}</td></tr>
            @empty
                <tr><td colspan="8" class="muted">No project revenue or direct costs yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if ($profitability->isNotEmpty())
            @php($maxProfit = max(1, (int) $profitability->max(fn ($r) => abs($r['gross_profit']))))
            <div class="panel-body"><h3 style="margin-top:0">Project-wise profitability (gross profit)</h3>
                @foreach ($profitability as $r)
                    <div style="display:flex; align-items:center; gap:.6rem; margin:.35rem 0">
                        <span style="width:30%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">{{ $r['project']->name }}</span>
                        <div style="flex:1; background:var(--neutral-bg); border-radius:4px; height:.7rem" role="img" aria-label="{{ $r['project']->name }} gross profit {{ $m($r['gross_profit']) }}">
                            <div style="background:{{ $r['gross_profit'] < 0 ? 'var(--danger)' : 'var(--success)' }}; width:{{ max(1, intdiv(abs($r['gross_profit']) * 100, $maxProfit)) }}%; height:100%; border-radius:4px"></div>
                        </div>
                        <span class="num" style="width:22%; text-align:right">{{ $m($r['gross_profit']) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <div class="grid grid-2">
        <section class="panel">
            <div class="panel-head"><h2>Outstanding customer payments</h2><span class="sub">Not cash until received</span></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Project</th><th class="num">Outstanding</th></tr></thead>
                <tbody>
                @forelse ($receivables as $r)
                    <tr><td><a href="{{ route('projects.show', $r['project']) }}">{{ $r['project']->name }}</a><span class="secondary">{{ $r['project']->client->name }}</span></td><td class="num strong">{{ $m($r['outstanding']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">Nothing outstanding.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Accounts payable</h2><span class="sub">Recorded, not yet paid</span></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Expense</th><th class="num">Still owed</th></tr></thead>
                <tbody>
                @forelse ($payables as $e)
                    <tr><td>{{ $e->description }}<span class="secondary">{{ $e->vendor ?: $e->category }} · {{ $e->spent_on->format('j M Y') }}</span></td><td class="num strong">{{ $m($e->outstanding()) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">No unpaid expenses.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-head"><h2>Financial reports</h2></div>
        <div class="panel-body" style="display:flex; gap:.5rem; flex-wrap:wrap">
            <a class="btn" href="{{ route('finance.pnl') }}">Profit &amp; Loss</a>
            <a class="btn" href="{{ route('finance.position') }}">Financial position</a>
            <a class="btn" href="{{ route('finance.reports') }}">Financial reports</a>
            <a class="btn" href="{{ route('finance.journal') }}">Journal</a>
            <a class="btn" href="{{ route('finance.accounts') }}">Accounts &amp; transfers</a>
            <a class="btn" href="{{ route('finance.reconciliation') }}">Reconciliation</a>
        </div>
    </section>
</x-layout>
