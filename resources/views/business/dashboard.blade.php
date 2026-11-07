@use('App\Support\Money')
@php
    $cur = $data['currency'];
    $m = fn (int $v) => Money::format($v);
    $net = $data['net_profit'];
    $status = $data['status'];
    $badge = fn (string $s) => match ($s) { 'verified' => 'badge-completed', 'provisional' => 'badge-pending', 'incomplete' => 'badge-rejected', default => 'badge-pending' };
    $label = fn (string $s) => ucfirst($s);
    $chart = $data['chart'];
    $n = max(1, count($chart['buckets']));
    $W = 720; $H = 200; $pl = 8; $pb = 24; $pt = 8; $plotH = $H - $pb - $pt; $band = ($W - $pl) / $n; $bw = max(3, min(22, $band * 0.36));
@endphp
<x-layout title="Business Dashboard">
    <style>
        .hero { background: var(--glass); border: 1px solid var(--glass-border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 1.4rem 1.6rem; margin-bottom: 1.25rem; border-left: 6px solid var(--gold); }
        .hero.is-loss { border-left-color: var(--danger); } .hero.is-profit { border-left-color: var(--success-solid); }
        .hero .kicker { font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
        .hero .big { font-size: clamp(2rem, 5vw, 3rem); font-weight: 700; font-variant-numeric: tabular-nums; margin: .25rem 0; }
        .hero.is-loss .big { color: var(--danger); } .hero.is-profit .big { color: var(--success); }
        .hero .sub { color: var(--text-2); font-size: .9rem; max-width: 70ch; }
        .biz-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
        @media (max-width: 1000px) { .biz-grid { grid-template-columns: minmax(0, 1fr); } }
        .biz-list { list-style: none; margin: 0; padding: 0; } .biz-list li { display: flex; justify-content: space-between; gap: 1rem; padding: .4rem 0; border-bottom: 1px solid var(--border); font-size: .9rem; }
        .biz-list li:last-child { border-bottom: 0; } .biz-list .muted { color: var(--muted); } .biz-list .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .unres li { display: block; } .unres .t { font-weight: 600; } .unres .d { color: var(--muted); font-size: .82rem; }
        .legend { display: flex; gap: 1rem; font-size: .8rem; color: var(--muted); margin: .4rem 0 0; }
        .legend i { display: inline-block; width: .7rem; height: .7rem; border-radius: 2px; margin-right: .3rem; vertical-align: -1px; }
    </style>

    <x-page-header title="Business Dashboard">
        <x-slot:meta>
            @if ($data['from']) {{ \Carbon\CarbonImmutable::parse($data['from'])->format('j M Y') }} – @else Since the first record, to @endif {{ \Carbon\CarbonImmutable::parse($data['to'])->format('j M Y') }} · {{ $cur }} · management view
        </x-slot:meta>
    </x-page-header>

    <form method="GET" action="{{ route('dashboard') }}" class="toolbar" id="business-filters">
        <div class="field"><label for="period">Period</label>
            <select id="period" name="period">@foreach ($periods as $k => $l)<option value="{{ $k }}" @selected($period === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field" id="custom-from" @if ($period !== 'custom') hidden @endif><label for="from">From</label><input type="date" id="from" name="from" value="{{ $from?->toDateString() }}"></div>
        <div class="field" id="custom-to" @if ($period !== 'custom') hidden @endif><label for="to">To</label><input type="date" id="to" name="to" value="{{ $to->toDateString() }}"></div>
        <button type="submit" class="btn">Apply</button>
    </form>
    <script>
        (function () {
            var p = document.getElementById('period'); if (!p) return;
            p.addEventListener('change', function () { var c = p.value === 'custom'; document.getElementById('custom-from').hidden = !c; document.getElementById('custom-to').hidden = !c; if (!c) p.form.submit(); });
        })();
    </script>

    <section @class(['hero', 'is-loss' => $net['status'] === 'verified' && $net['amount'] < 0, 'is-profit' => $net['status'] === 'verified' && $net['amount'] > 0])>
        <div class="kicker">Overall business profit / loss <span class="badge {{ $badge($net['status']) }}">{{ $label($net['status']) }}</span></div>
        <div class="big"><span class="cur" style="font-size:1rem;color:var(--muted)">{{ $cur }}</span> {{ $m($net['amount']) }}</div>
        <div class="sub">
            @if ($net['status'] === 'verified')
                <strong>{{ $net['amount'] < 0 ? 'Loss' : ($net['amount'] > 0 ? 'Profit' : 'Break-even') }}</strong>
            @else
                <strong>{{ $net['status'] === 'incomplete' ? 'Incomplete position, not a profit or loss yet' : 'Provisional position, not a final profit or loss' }}</strong>
            @endif
            = verified earned revenue {{ $m($data['revenue']['verified']) }} − management expenses {{ $m($data['expenses']['total']) }}.
            Partner investment, customer and client money, supplier advances and loans are not in this figure.
            @if ($net['status'] !== 'verified')
                <br>This result is <strong>{{ $net['status'] }}</strong>:
                @if ($data['receipts']['provisional_service']['amount'] > 0)
                    {{ $m($data['receipts']['provisional_service']['amount']) }} of receipts look like service fees but are not classified yet, so they are not counted. If Finance confirms them the result would be {{ $m($net['if_suggested_service_accepted']) }}.
                @endif
                @if ($data['sourcing']['potential_total'] !== 0)
                    Potential sourcing margin, not earned: {{ $m($data['sourcing']['potential_total']) }} (excluded).
                @endif
                Money collected from customers is shown under "Where the money came from"; it is not profit.
                See "What is not verified yet" below.
            @endif
        </div>
    </section>

    <div class="metrics-auto">
        <div class="metric">
            <div class="label">Total investment <span class="badge {{ $badge($status['investment']) }}">{{ $label($status['investment']) }}</span></div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ $m($data['investment']['net']) }}</div>
            <div class="hint">Contributed {{ $m($data['investment']['contributed']) }}@if ($data['investment']['withdrawn'] > 0) · returned {{ $m($data['investment']['withdrawn']) }}@endif · capital, not profit</div>
        </div>
        <div class="metric">
            <div class="label">Total earned revenue <span class="badge {{ $badge($status['revenue']) }}">{{ $label($status['revenue']) }}</span></div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ $m($data['revenue']['verified']) }}</div>
            <div class="hint">Verified only · received {{ $m($data['receipts']['total']['amount']) }} in total</div>
        </div>
        <div class="metric">
            <div class="label">Management expenses <span class="badge {{ $badge($status['expenses']) }}">{{ $label($status['expenses']) }}</span></div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ $m($data['expenses']['total']) }}</div>
            <div class="hint">Includes {{ $m($data['expenses']['asset_tagged']) }} tagged equipment/asset (owner policy)</div>
        </div>
        <div class="metric">
            <div class="label">Product sourcing profit <span class="badge {{ $badge($status['sourcing']) }}">{{ $label($status['sourcing']) }}</span></div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ $m($data['sourcing']['recognised']) }}</div>
            <div class="hint">Earned on settled lots only · potential, not earned: {{ $m($data['sourcing']['potential_total']) }}</div>
        </div>
        <div class="metric">
            <div class="label">Available cash <span class="badge {{ $badge($status['cash']) }}">{{ $status['cash'] === 'verified' ? 'Verified' : 'Unverified' }}</span></div>
            @if ($data['cash']['status'] === 'verified')
                <div class="value"><span class="cur">{{ $cur }}</span>{{ $m($data['cash']['verified_total']) }}</div>
                <div class="hint">After client funds held: {{ $m($data['cash']['company_cash_after_client_funds']) }}</div>
            @else
                <div class="value" style="font-size:1.05rem">Unverified</div>
                <div class="hint">Verified accounts {{ $m($data['cash']['verified_total']) }} · ledger calculation {{ $m($data['cash']['ledger_total']) }} · unassigned {{ $m($data['cash']['unassigned']) }}</div>
            @endif
        </div>
        <a class="metric" href="{{ route('invoices.index', ['status' => 'open']) }}">
            <div class="label">Outstanding receivables <span class="badge {{ $badge($status['receivables']) }}">{{ $label($status['receivables']) }}</span></div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ $m($data['position']['invoices_outstanding']) }}</div>
            <div class="hint">{{ $data['position']['invoices_open'] }} open invoice(s) · not included in profit</div>
        </a>
    </div>

    @php $f = $data['funding']; $split = $f['collection_split']; @endphp
    <section class="panel" style="margin-bottom:1.25rem" id="funding">
        <div class="panel-head"><h2>Where the money came from</h2> <span class="badge {{ $f['status'] === 'verified' ? 'badge-completed' : 'badge-pending' }}">{{ $f['status'] === 'verified' ? 'Verified' : 'Unverified' }}</span></div>
        <div class="panel-body">
            <div class="biz-grid" style="margin-bottom:.5rem">
                <ul class="biz-list">
                    <li><span>Partner investment</span><span class="num">{{ $m($f['investment']) }}</span></li>
                    <li><span>Customer collections (recorded)</span><span class="num">{{ $m($f['collections']) }}</span></li>
                    <li class="muted"><span>· Onboarding</span><span class="num">{{ $m($split['onboarding']['amount']) }}</span></li>
                    <li class="muted"><span>· Marketing</span><span class="num">{{ $m($split['marketing']['amount']) }}</span></li>
                    <li class="muted"><span>· Product sourcing (client money)</span><span class="num">{{ $m($split['product_sourcing']['amount']) }}</span></li>
                    @if ($split['other']['amount'] > 0)<li class="muted"><span>· Other or unclassified</span><span class="num">{{ $m($split['other']['amount']) }}</span></li>@endif
                    @if ($f['other_inflows'] > 0)<li><span>Other recorded inflows ({{ implode(', ', array_keys($f['other_inflow_parts'])) }})</span><span class="num">{{ $m($f['other_inflows']) }}</span></li>@endif
                    <li><strong>Total recorded cash inflows</strong><strong class="num">{{ $m($f['total_in']) }}</strong></li>
                </ul>
                <ul class="biz-list">
                    <li><span>Operating expenses</span><span class="num">{{ $m($f['outflows']['operating']) }}</span></li>
                    <li><span>Office purchases tagged equipment/asset</span><span class="num">{{ $m($f['outflows']['equipment']) }}</span></li>
                    <li><span>Supplier and sourcing payments</span><span class="num">{{ $m($f['outflows']['sourcing']) }}</span></li>
                    <li><span>Loans lent</span><span class="num">{{ $m($f['outflows']['loans']) }}</span></li>
                    @if ($f['outflows']['partner_withdrawals'] > 0)<li><span>Partner withdrawals</span><span class="num">{{ $m($f['outflows']['partner_withdrawals']) }}</span></li>@endif
                    @if ($f['outflows']['other'] > 0)<li><span>Other outflows</span><span class="num">{{ $m($f['outflows']['other']) }}</span></li>@endif
                    <li><strong>Total recorded cash outflows</strong><strong class="num">{{ $m($f['total_out']) }}</strong></li>
                    <li><strong>Provisional cash remaining</strong><strong class="num">{{ $m($f['remaining']) }}</strong></li>
                </ul>
            </div>
            <p class="hint" style="color:var(--muted);font-size:.82rem;margin:0">
                A funding explanation from recorded movements, not profit and not verified available cash. Customer collections are inflows even while their revenue classification is pending; none of them is counted as earned revenue here.
                @if ($f['collections_not_in_ledger'] > 0) {{ $m($f['collections_not_in_ledger']) }} of collections is recorded but not yet in the ledger or any account. @endif
                Potential sourcing margin (not earned): {{ $m($data['sourcing']['potential_total']) }}; recognised sourcing profit: {{ $m($data['sourcing']['recognised']) }}.
            </p>
        </div>
    </section>

    <div class="biz-grid">
        <section class="panel">
            <div class="panel-head"><h2>Revenue versus expenses</h2></div>
            <div class="panel-body">
                @if ($chart['buckets'] === [])
                    <x-empty-state icon="chart" message="Nothing recorded for this period." />
                @else
                    <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Revenue and management expenses per {{ $chart['unit'] }}" style="width:100%;height:auto">
                        <line x1="{{ $pl }}" y1="{{ $pt + $plotH }}" x2="{{ $W }}" y2="{{ $pt + $plotH }}" stroke="var(--border-strong)" stroke-width="1" />
                        @foreach ($chart['buckets'] as $i => $b)
                            @php $x = $pl + $i * $band + ($band - 2 * $bw - 2) / 2; $rh = $b['revenue'] > 0 ? max(1, $plotH * $b['revenue'] / $chart['max']) : 0; $eh = $b['expenses'] > 0 ? max(1, $plotH * $b['expenses'] / $chart['max']) : 0; @endphp
                            <rect x="{{ round($x, 1) }}" y="{{ round($pt + $plotH - $rh, 1) }}" width="{{ $bw }}" height="{{ round($rh, 1) }}" fill="var(--success-solid)"><title>{{ $b['label'] }} revenue {{ $m($b['revenue']) }}</title></rect>
                            <rect x="{{ round($x + $bw + 2, 1) }}" y="{{ round($pt + $plotH - $eh, 1) }}" width="{{ $bw }}" height="{{ round($eh, 1) }}" fill="var(--gold-bar)"><title>{{ $b['label'] }} expenses {{ $m($b['expenses']) }}</title></rect>
                            @if ($i % max(1, (int) ceil($n / 10)) === 0)<text x="{{ round($pl + $i * $band + $band / 2, 1) }}" y="{{ $H - 6 }}" text-anchor="middle" font-size="11" fill="var(--muted)">{{ $b['label'] }}</text>@endif
                        @endforeach
                    </svg>
                    <div class="legend"><span><i style="background:var(--success-solid)"></i>Verified revenue</span><span><i style="background:var(--gold-bar)"></i>Management expenses</span><span>Peak bar {{ $m($chart['max']) }} · per {{ $chart['unit'] }}</span></div>
                @endif
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Revenue breakdown</h2></div>
            <div class="panel-body">
                <ul class="biz-list">
                    <li><span>Onboarding and service fees (verified)</span><span class="num">{{ $m($data['revenue']['service']) }}</span></li>
                    <li><span>Product sourcing margin (settled lots)</span><span class="num">{{ $m($data['revenue']['sourcing']) }}</span></li>
                    <li><strong>Verified earned revenue</strong><strong class="num">{{ $m($data['revenue']['verified']) }}</strong></li>
                    <li class="muted"><span>Suggested service fees, not classified (not counted)</span><span class="num">{{ $m($data['receipts']['provisional_service']['amount']) }}</span></li>
                    <li class="muted"><span>Received for client purchases (client money, never revenue)</span><span class="num">{{ $m($data['receipts']['sourcing_receipts']['amount']) }}</span></li>
                    @if ($data['receipts']['unknown']['amount'] > 0)<li class="muted"><span>Received, nature unknown</span><span class="num">{{ $m($data['receipts']['unknown']['amount']) }}</span></li>@endif
                </ul>
                <p class="hint" style="margin-top:.6rem;color:var(--muted);font-size:.8rem">Basis: {{ $data['revenue']['basis'] }}.</p>
            </div>
        </section>
    </div>

    <div class="biz-grid">
        <section class="panel">
            <div class="panel-head"><h2>What is not verified yet</h2></div>
            <div class="panel-body">
                @forelse ($data['unresolved'] as $u)
                    @if ($loop->first)<ul class="biz-list unres">@endif
                    <li><div style="display:flex;justify-content:space-between;gap:1rem"><span class="t"><a href="{{ route($u['route']) }}">{{ $u['label'] }}</a></span><span class="num">{{ $u['amount'] !== null ? $m($u['amount']) : '' }}</span></div>
                        <div class="d">Affects: {{ $u['affects'] }}. {{ $u['detail'] }}</div></li>
                    @if ($loop->last)</ul>@endif
                @empty
                    <p>Every input of the displayed metrics is verified.</p>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Balances and obligations</h2></div>
            <div class="panel-body">
                <ul class="biz-list">
                    <li><span>Customer invoices outstanding</span><span class="num">{{ $m($data['position']['invoices_outstanding']) }}</span></li>
                    <li><span>Supplier advances and recoverables</span><span class="num">{{ $m($data['position']['supplier_advances']) }}</span></li>
                    <li class="muted"><span>Supplier shortfall (not yet recorded)</span><span class="num">{{ $m($data['position']['supplier_shortfall']) }}</span></li>
                    <li><span>Unpaid supplier and operational obligations</span><span class="num">{{ $m($data['position']['payables']) }}</span></li>
                    <li><span>Loans receivable</span><span class="num">{{ $m($data['position']['loans_receivable']) }}</span></li>
                    <li><span>Client funds held (ledger)</span><span class="num">{{ $m($data['position']['client_funds_ledger']) }}</span></li>
                    <li class="muted"><span>Client money received, not yet in the ledger</span><span class="num">{{ $m($data['position']['client_money_received']) }}</span></li>
                    @foreach ($data['cash']['accounts'] as $a)<li class="muted"><span>{{ $a['group'] }}: {{ $a['name'] }} {{ $a['verified'] ? '' : '(unverified)' }}</span><span class="num">{{ $m($a['balance']) }}</span></li>@endforeach
                    @if ($data['cash']['unassigned'] !== 0)<li class="muted"><span>Cash not assigned to any account</span><span class="num">{{ $m($data['cash']['unassigned']) }}</span></li>@endif
                </ul>
            </div>
        </section>
    </div>
</x-layout>
