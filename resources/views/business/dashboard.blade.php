@use('App\Support\Money')
@php
    $cur = $data['currency'];
    $m = fn (int $v) => Money::format($v);
    $net = $data['net_profit'];
    $status = $data['status'];
    $f = $data['funding'];
    $split = $f['collection_split'];
    $pill = fn (string $s) => match ($s) { 'verified' => 'ok', 'provisional' => 'warn', 'incomplete' => 'bad', default => 'mute' };
    $neg = $net['amount'] < 0;
    $earned = $data['revenue']['verified'];
    $cashVerified = $data['cash']['status'] === 'verified';
    $chart = $data['chart'];
    $n = max(1, count($chart['buckets']));
    $W = 640; $H = 190; $pl = 6; $pb = 24; $pt = 8; $plotH = $H - $pb - $pt; $band = ($W - $pl) / $n; $bw = max(3, min(20, $band * 0.36));
    $pending = $data['unresolved'];
@endphp
<x-layout title="Business Overview">
    <div class="bo">
        <header class="bo-header">
            <div>
                <h1>Business Overview</h1>
                <div class="bo-meta">
                    @if ($data['from']) {{ \Carbon\CarbonImmutable::parse($data['from'])->format('j M Y') }} to @else Since the first record, to @endif {{ \Carbon\CarbonImmutable::parse($data['to'])->format('j M Y') }} · {{ $cur }} · management view
                </div>
            </div>
            <div class="bo-tools">
                <form method="GET" action="{{ route('dashboard') }}" id="business-filters">
                    <label class="sr-only" for="period">Reporting period</label>
                    <select id="period" name="period">@foreach ($periods as $k => $l)<option value="{{ $k }}" @selected($period === $k)>{{ $l }}</option>@endforeach</select>
                    <span id="custom-dates" @if ($period !== 'custom') hidden @endif>
                        <label class="sr-only" for="from">From</label><input type="date" id="from" name="from" value="{{ $from?->toDateString() }}">
                        <label class="sr-only" for="to">To</label><input type="date" id="to" name="to" value="{{ $to->toDateString() }}">
                        <button type="submit" class="btn btn-sm">Apply</button>
                    </span>
                </form>
                <button type="button" class="topbar-search" data-cmdk-open aria-haspopup="dialog" style="margin-right:0"><x-icon name="search" /> <span>Search</span> <kbd data-cmdk-hint>Ctrl K</kbd></button>
                <span class="bo-updated" title="Figures are read from the live records each time this page loads"><i></i> Updated {{ now()->format('j M, H:i') }}</span>
            </div>
        </header>

        <section class="bo-card bo-hero" aria-labelledby="hero-title">
            <div>
                <div class="kick" id="hero-title">Overall Business Position <span class="bo-pill {{ $pill($net['status']) }}">{{ ucfirst($net['status']) }}</span></div>
                <div class="amount {{ $neg ? 'neg' : 'pos' }} {{ $net['status'] }}"><small>{{ $cur }}</small>{{ $m($net['amount']) }}</div>
                <p>
                    @if ($net['status'] === 'verified')
                        <strong>{{ $net['amount'] < 0 ? 'Net loss' : ($net['amount'] > 0 ? 'Net profit' : 'Break-even') }}</strong>: verified earned revenue minus management expenses.
                    @else
                        <strong>{{ $net['status'] === 'incomplete' ? 'Incomplete position, not a profit or loss yet' : 'Provisional position, not a final profit or loss' }}.</strong>
                        @if ($data['receipts']['provisional_service']['amount'] > 0)
                            {{ $m($data['receipts']['provisional_service']['amount']) }} of collected service fees is still awaiting classification and is not counted yet; once confirmed the position would be {{ $m($net['if_suggested_service_accepted']) }}.
                        @endif
                        @if ($data['sourcing']['potential_total'] !== 0) Potential sourcing margin of {{ $m($data['sourcing']['potential_total']) }} is not earned yet. @endif
                    @endif
                    Partner investment and client money are never counted as profit.
                </p>
            </div>
            <div class="bo-break" aria-label="How the position is built">
                <div><span>Earned revenue</span><b>{{ $m($earned) }}</b></div>
                <div><span>Product sourcing margin</span><b>{{ $m($data['sourcing']['recognised']) }}</b></div>
                <div><span>Management expenses</span><b>− {{ $m($data['expenses']['total']) }}</b></div>
            </div>
        </section>

        <div class="bo-kpis">
            <div class="bo-card bo-kpi"><div class="l">Investment <span class="bo-pill {{ $pill($status['investment']) }}">{{ ucfirst($status['investment']) }}</span></div>
                <div class="v"><small>{{ $cur }}</small>{{ $m($data['investment']['net']) }}</div><div class="h">Partner capital, never income @if ($data['investment']['withdrawn'] > 0) · {{ $m($data['investment']['withdrawn']) }} returned @endif</div></div>
            <div class="bo-card bo-kpi"><div class="l">Customer Collections <span class="bo-pill mute">Recorded</span></div>
                <div class="v"><small>{{ $cur }}</small>{{ $m($f['collections']) }}</div><div class="h">Money in, not revenue · onboarding {{ $m($split['onboarding']['amount']) }}, marketing {{ $m($split['marketing']['amount']) }}, products {{ $m($split['product_sourcing']['amount']) }}</div></div>
            <div class="bo-card bo-kpi"><div class="l">Earned Revenue <span class="bo-pill {{ $pill($status['revenue']) }}">{{ ucfirst($status['revenue']) }}</span></div>
                <div class="v"><small>{{ $cur }}</small>{{ $m($earned) }}</div><div class="h">Verified only @if ($data['receipts']['provisional_service']['amount'] > 0) · {{ $m($data['receipts']['provisional_service']['amount']) }} awaiting classification @endif</div></div>
            <div class="bo-card bo-kpi"><div class="l">Management Expenses <span class="bo-pill {{ $pill($status['expenses']) }}">{{ ucfirst($status['expenses']) }}</span></div>
                <div class="v"><small>{{ $cur }}</small>{{ $m($data['expenses']['total']) }}</div><div class="h">Includes {{ $m($data['expenses']['asset_tagged']) }} of equipment and asset-tagged purchases</div></div>
            <div class="bo-card bo-kpi"><div class="l">{{ $cashVerified ? 'Verified Cash' : 'Expected Cash' }} <span class="bo-pill {{ $cashVerified ? 'ok' : 'warn' }}">{{ $cashVerified ? 'Verified' : 'Unverified' }}</span></div>
                <div class="v"><small>{{ $cur }}</small>{{ $m($cashVerified ? $data['cash']['verified_total'] : $f['remaining']) }}</div>
                <div class="h">@if ($cashVerified) Matches verified statements @else From records; no statement or count verifies Cash, bKash or Bank yet @endif</div></div>
            <a class="bo-card bo-kpi" href="{{ route('invoices.index', ['status' => 'open']) }}" style="text-decoration:none;color:inherit"><div class="l">Outstanding Receivables <span class="bo-pill {{ $pill($status['receivables']) }}">{{ ucfirst($status['receivables']) }}</span></div>
                <div class="v"><small>{{ $cur }}</small>{{ $m($data['position']['invoices_outstanding']) }}</div><div class="h">{{ $data['position']['invoices_open'] }} open invoice(s) · not counted in profit</div></a>
        </div>

        <div class="bo-cols">
            <section class="bo-card" aria-labelledby="chart-h">
                <h2 id="chart-h">Revenue vs expenses</h2>
                @if ($chart['buckets'] === [])
                    <x-empty-state icon="chart" message="Nothing recorded for this period." />
                @else
                    <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Verified revenue and management expenses per {{ $chart['unit'] }}" style="width:100%;height:auto;display:block">
                        <line x1="{{ $pl }}" y1="{{ $pt + $plotH }}" x2="{{ $W }}" y2="{{ $pt + $plotH }}" stroke="var(--border-strong)" stroke-width="1" />
                        @foreach ($chart['buckets'] as $i => $b)
                            @php $x = $pl + $i * $band + ($band - 2 * $bw - 2) / 2; $rh = $b['revenue'] > 0 ? max(1, $plotH * $b['revenue'] / $chart['max']) : 0; $eh = $b['expenses'] > 0 ? max(1, $plotH * $b['expenses'] / $chart['max']) : 0; @endphp
                            <rect x="{{ round($x, 1) }}" y="{{ round($pt + $plotH - $rh, 1) }}" width="{{ $bw }}" height="{{ round($rh, 1) }}" rx="2" fill="var(--success-solid)"><title>{{ $b['label'] }} revenue {{ $m($b['revenue']) }}</title></rect>
                            <rect x="{{ round($x + $bw + 2, 1) }}" y="{{ round($pt + $plotH - $eh, 1) }}" width="{{ $bw }}" height="{{ round($eh, 1) }}" rx="2" fill="var(--gold-bar)"><title>{{ $b['label'] }} expenses {{ $m($b['expenses']) }}</title></rect>
                            @if ($i % max(1, (int) ceil($n / 8)) === 0)<text x="{{ round($pl + $i * $band + $band / 2, 1) }}" y="{{ $H - 6 }}" text-anchor="middle" font-size="11" fill="var(--muted)">{{ $b['label'] }}</text>@endif
                        @endforeach
                    </svg>
                    <p class="bo-note"><span style="color:var(--success-solid)">■</span> Verified revenue &nbsp; <span style="color:var(--gold-bar)">■</span> Management expenses · per {{ $chart['unit'] }} · highest bar {{ $m($chart['max']) }}</p>
                @endif
            </section>

            <section class="bo-card" aria-labelledby="funding-h" id="funding">
                <h2 id="funding-h">Where the money came from <span class="bo-pill {{ $f['status'] === 'verified' ? 'ok' : 'warn' }}">{{ $f['status'] === 'verified' ? 'Verified' : 'Unverified' }}</span></h2>
                <ul class="bo-rows">
                    <li><span>Partner investment</span><span class="n">{{ $m($f['investment']) }}</span></li>
                    <li><span>Customer collections</span><span class="n">{{ $m($f['collections']) }}</span></li>
                    @if ($f['other_inflows'] > 0)<li><span>Other recorded inflows</span><span class="n">{{ $m($f['other_inflows']) }}</span></li>@endif
                    <li><span class="t">Total cash in</span><span class="n t">{{ $m($f['total_in']) }}</span></li>
                    <li><span>Total cash out</span><span class="n">− {{ $m($f['total_out']) }}</span></li>
                    <li><span class="t">Expected closing cash</span><span class="n t">{{ $m($f['remaining']) }}</span></li>
                </ul>
                <p class="bo-note">From recorded movements, not profit and not verified balances. Collections count as money in even while their revenue classification is pending.
                    @if ($f['collections_not_in_ledger'] > 0) {{ $m($f['collections_not_in_ledger']) }} is recorded but not yet in the ledger or any account. @endif</p>
            </section>
        </div>

        <div class="bo-cols">
            <section class="bo-card" aria-labelledby="src-h">
                <h2 id="src-h">Product sourcing margin <span class="bo-pill {{ $pill($status['sourcing']) }}">{{ ucfirst($status['sourcing']) }}</span></h2>
                <ul class="bo-rows">
                    <li><span>Earned (settled lots)</span><span class="n t">{{ $m($data['sourcing']['recognised']) }}</span></li>
                    <li><span class="m">Potential, not earned yet</span><span class="n m">{{ $m($data['sourcing']['potential_total']) }}</span></li>
                    <li><span class="m">Client money received for products</span><span class="n m">{{ $m($split['product_sourcing']['amount']) }}</span></li>
                </ul>
                <p class="bo-note">Margin is earned only when a lot is financially settled and its owner is stated. @if ($data['sourcing']['unknown_owner'] !== []) Owner not stated: {{ implode(', ', $data['sourcing']['unknown_owner']) }}. @endif A product receipt is never counted as revenue.</p>
            </section>

            <section class="bo-card" aria-labelledby="todo-h">
                <h2 id="todo-h">Pending verification <span class="bo-pill {{ count($pending) ? 'warn' : 'ok' }}">{{ count($pending) ? count($pending).' open' : 'All clear' }}</span></h2>
                @if ($pending === [])
                    <p class="bo-note" style="margin:0">Every input of the displayed metrics is verified.</p>
                @else
                    <ul class="bo-todo">
                        @foreach (array_slice($pending, 0, 4) as $u)
                            <li><i></i><div><a href="{{ route($u['route']) }}">{{ $u['label'] }}</a>@if ($u['amount'] !== null) · {{ $m($u['amount']) }}@endif<small>Affects {{ strtolower($u['affects']) }}</small></div></li>
                        @endforeach
                    </ul>
                    @if (count($pending) > 4)<p class="bo-note"><a href="#d-pending" data-open-details>See all {{ count($pending) }} items</a></p>@endif
                @endif
            </section>
        </div>

        <h2 class="sr-only">Details on request</h2>
        <div class="bo-details">
            <details class="bo-detail" id="d-bridge"><summary>Why the result and the cash differ <small>cash-to-profit bridge</small></summary>
                <div class="body"><ul class="bo-rows">
                    @foreach ($data['bridge']['lines'] as $line)<li><span>{{ $line['label'] }}</span><span class="n">{{ $m($line['amount']) }}</span></li>@endforeach
                    <li><span class="t">= Expected closing cash</span><span class="n t">{{ $m($data['bridge']['total']) }}</span></li>
                </ul></div></details>

            <details class="bo-detail" id="d-accounts"><summary>Cash, bKash and bank accounts <small>expected vs verified actual</small></summary>
                <div class="body">
                    <table class="bo-acct"><thead><tr><th>Account</th><th>Opening</th><th>In</th><th>Out</th><th>Transfers</th><th>Expected</th><th>Actual (verified)</th><th>Variance</th></tr></thead><tbody>
                        @forelse ($data['accounts'] as $a)
                            <tr><td>{{ $a['name'] }}<br><small style="color:var(--muted)">{{ $a['group'] }} · {{ $a['state'] }}</small></td><td>{{ $m($a['opening']) }}</td><td>{{ $m($a['inflows']) }}</td><td>{{ $m($a['outflows']) }}</td><td>{{ $m($a['transfers']) }}</td><td><strong>{{ $m($a['expected']) }}</strong></td>
                                <td>@if ($a['actual'] === null) not verified @else {{ $m($a['actual']) }} <small>({{ $a['actual_date'] }})</small> @endif</td>
                                <td>@if ($a['variance'] === null) n/a @else {{ $m($a['variance']) }}@if ($a['unexplained'] !== 0) <small>({{ $m($a['unexplained']) }} unexplained)</small>@endif @endif</td></tr>
                        @empty
                            <tr><td colspan="8" style="text-align:left">No cash movement is recorded against any account.</td></tr>
                        @endforelse
                        @if ($f['collections_not_in_ledger'] > 0)<tr><td>Collections recorded, not yet in any account</td><td colspan="4"></td><td><strong>{{ $m($f['collections_not_in_ledger']) }}</strong></td><td colspan="2">expected only</td></tr>@endif
                    </tbody></table>
                    <p class="bo-note">Actual balances come only from a statement or count verified by a second person (Finance &gt; Historical Reconciliation). Nothing is estimated as an actual balance. Proof: ledger {{ $m($f['ledger_net']) }} + collections not yet recorded {{ $m($f['collections_not_in_ledger']) }} = expected closing cash {{ $m($f['remaining']) }}@if ($f['proof_difference'] !== 0), unexplained difference {{ $m($f['proof_difference']) }}@endif.</p>
                    @if ($data['cash']['unassigned'] !== 0)<p class="bo-note">Cash not assigned to any account: {{ $m($data['cash']['unassigned']) }}.</p>@endif
                </div></details>

            <details class="bo-detail" id="d-balances"><summary>Supplier balances, loans and client money <small>obligations and recoverables</small></summary>
                <div class="body"><ul class="bo-rows">
                    <li><span>Customer invoices outstanding</span><span class="n">{{ $m($data['position']['invoices_outstanding']) }}</span></li>
                    <li><span>Supplier advances and recoverables</span><span class="n">{{ $m($data['position']['supplier_advances']) }}</span></li>
                    <li><span class="m">Supplier shortfall, not yet recorded</span><span class="n m">{{ $m($data['position']['supplier_shortfall']) }}</span></li>
                    <li><span>Unpaid supplier and operational obligations</span><span class="n">{{ $m($data['position']['payables']) }}</span></li>
                    <li><span>Loans receivable</span><span class="n">{{ $m($data['position']['loans_receivable']) }}</span></li>
                    <li><span>Client funds held (ledger)</span><span class="n">{{ $m($data['position']['client_funds_ledger']) }}</span></li>
                    <li><span class="m">Client money received, not yet in the ledger</span><span class="n m">{{ $m($data['position']['client_money_received']) }}</span></li>
                </ul></div></details>

            <details class="bo-detail" id="d-revenue"><summary>Revenue and collections by type <small>what is counted and what is not</small></summary>
                <div class="body"><ul class="bo-rows">
                    <li><span>Onboarding and service fees (verified)</span><span class="n">{{ $m($data['revenue']['service']) }}</span></li>
                    <li><span>Product sourcing margin (settled lots)</span><span class="n">{{ $m($data['revenue']['sourcing']) }}</span></li>
                    <li><span class="t">Verified earned revenue</span><span class="n t">{{ $m($earned) }}</span></li>
                    <li><span class="m">Collected as onboarding</span><span class="n m">{{ $m($split['onboarding']['amount']) }}</span></li>
                    <li><span class="m">Collected as marketing</span><span class="n m">{{ $m($split['marketing']['amount']) }}</span></li>
                    <li><span class="m">Collected for products (client money, never revenue)</span><span class="n m">{{ $m($split['product_sourcing']['amount']) }}</span></li>
                    @if ($split['other']['amount'] > 0)<li><span class="m">Collected, other or unclassified</span><span class="n m">{{ $m($split['other']['amount']) }}</span></li>@endif
                    <li><span class="m">Suggested service fees, not yet classified (not counted)</span><span class="n m">{{ $m($data['receipts']['provisional_service']['amount']) }}</span></li>
                </ul><p class="bo-note">Basis: {{ $data['revenue']['basis'] }}.</p></div></details>

            <details class="bo-detail" id="d-pending"><summary>Everything not verified yet <small>{{ count($pending) }} item(s) with where to resolve them</small></summary>
                <div class="body">
                    @forelse ($pending as $u)
                        @if ($loop->first)<ul class="bo-todo">@endif
                        <li><i></i><div><a href="{{ route($u['route']) }}">{{ $u['label'] }}</a>@if ($u['amount'] !== null) · {{ $m($u['amount']) }}@endif<small>Affects {{ strtolower($u['affects']) }}. {{ $u['detail'] }}</small></div></li>
                        @if ($loop->last)</ul>@endif
                    @empty
                        <p class="bo-note" style="margin:0">Nothing is pending.</p>
                    @endforelse
                </div></details>
        </div>
    </div>

    <script>
        (function () {
            var p = document.getElementById('period'); if (!p) return;
            p.addEventListener('change', function () { var c = p.value === 'custom'; document.getElementById('custom-dates').hidden = !c; if (!c) p.form.submit(); });
            document.querySelectorAll('[data-open-details]').forEach(function (a) {
                a.addEventListener('click', function () { var d = document.querySelector(a.getAttribute('href')); if (d) d.open = true; });
            });
        })();
    </script>
</x-layout>
