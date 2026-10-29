@use('App\Support\Money')
@use('App\Support\DateRange')
@php
    $cur = $currency;
    $rangeQuery = $range->query();

    // Collections chart geometry (SVG user units).
    $buckets = $trend['buckets'];
    $count = max(1, count($buckets));
    $chartW = 720; $chartH = 220; $padL = 64; $padR = 8; $padT = 10; $padB = 26;
    $plotW = $chartW - $padL - $padR; $plotH = $chartH - $padT - $padB;
    $niceMax = 0;
    if ($trend['max'] > 0) {
        $magnitude = 10 ** floor(log10($trend['max']));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($step * $magnitude >= $trend['max']) { $niceMax = (int) ($step * $magnitude); break; }
        }
    }
    $band = $plotW / $count;
    $barW = max(2, min(34, $band * 0.68));
    $labelEvery = (int) ceil($count / 12);
@endphp
<x-layout title="Dashboard">
    <x-page-header title="Dashboard">
        <x-slot:meta>{{ $range->label() }} · {{ $cur }}</x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('clients.create') }}" class="btn"><x-icon name="users" /> New client</a>
            <a href="{{ route('projects.start') }}" class="btn"><x-icon name="folder" /> New project</a>
            <a href="{{ route('invoices.start') }}" class="btn btn-primary"><x-icon name="plus" /> New invoice</a>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" action="{{ route('dashboard') }}" class="toolbar" id="dashboard-filters">
        <div class="field">
            <label for="range">Period</label>
            <select id="range" name="range">
                @foreach (DateRange::PRESETS as $key => $label)
                    <option value="{{ $key }}" @selected($range->preset === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field custom-dates" @if ($range->preset !== 'custom') hidden @endif>
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="{{ $range->from->toDateString() }}">
        </div>
        <div class="field custom-dates" @if ($range->preset !== 'custom') hidden @endif>
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="{{ $range->to->toDateString() }}">
        </div>
        <div class="field">
            <label for="currency">Currency</label>
            <select id="currency" name="currency">
                @foreach ($currencies as $code)
                    <option value="{{ $code }}" @selected($code === $cur)>{{ $code }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn">Apply</button>
    </form>

    <p class="metric-group-label">Selected period ({{ $range->label() }}) · then current balances</p>
    <div class="metrics">
        <a class="metric" href="{{ route('invoices.index', ['currency' => $cur] + $rangeQuery) }}">
            <div class="label"><x-icon name="invoice" class="icon icon-sm" /> Invoiced</div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ Money::format($metrics['invoiced']) }}</div>
            <div class="hint">Issued in period</div>
        </a>
        <a class="metric" href="{{ route('payments.index', ['currency' => $cur] + $rangeQuery) }}">
            <div class="label"><x-icon name="wallet" class="icon icon-sm" /> Received</div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ Money::format($metrics['received']) }}</div>
            <div class="hint">Payments dated in period</div>
        </a>
        <a class="metric" href="{{ route('invoices.index', ['currency' => $cur, 'status' => 'open']) }}">
            <div class="label"><x-icon name="cash" class="icon icon-sm" /> Outstanding</div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ Money::format($metrics['outstanding']) }}</div>
            <div class="hint">Current · {{ $metrics['open_count'] }} open {{ Str::plural('invoice', $metrics['open_count']) }}</div>
        </a>
        <a @class(['metric', 'is-danger' => $metrics['overdue'] > 0]) href="{{ route('invoices.index', ['currency' => $cur, 'overdue' => 1]) }}">
            <div class="label"><x-icon name="alert" class="icon icon-sm" /> Overdue</div>
            <div class="value"><span class="cur">{{ $cur }}</span>{{ Money::format($metrics['overdue']) }}</div>
            <div class="hint">Current · {{ $metrics['overdue_count'] }} past due date</div>
        </a>
        <a class="metric" href="{{ route('clients.index') }}">
            <div class="label"><x-icon name="users" class="icon icon-sm" /> Clients</div>
            <div class="value">{{ number_format($metrics['clients']) }}</div>
            <div class="hint">Current, not archived</div>
        </a>
        <a class="metric" href="{{ route('projects.index', ['status' => 'active']) }}">
            <div class="label"><x-icon name="folder" class="icon icon-sm" /> Active projects</div>
            <div class="value">{{ number_format($metrics['active_projects']) }}</div>
            <div class="hint">Current, all currencies</div>
        </a>
    </div>

    <div class="grid grid-main-side" style="margin-bottom:1.25rem">
        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Invoices requiring attention</h2>
                    <div class="sub">Overdue first, then other unpaid and partially paid · {{ $cur }} · current</div>
                </div>
                <a href="{{ route('invoices.index', ['currency' => $cur, 'status' => 'open']) }}" class="btn btn-sm btn-ghost">View all <x-icon name="arrow-right" class="icon icon-sm" /></a>
            </div>
            @if ($attentionInvoices->isEmpty())
                <x-empty-state icon="check-circle" message="No unpaid {{ $cur }} invoices. Everything is settled." />
            @else
                <div class="table-wrap">
                    <table class="table table-min-640">
                        <thead>
                            <tr><th>Client / Invoice</th><th>Project</th><th>Due</th><th class="num">Remaining</th><th><span class="sr-only">Actions</span></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($attentionInvoices as $invoice)
                                <tr @class(['is-overdue' => $invoice->isOverdue()])>
                                    <td class="primary-cell">
                                        <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->project->client->name }}</a>
                                        <span class="secondary">{{ $invoice->number }}</span>
                                    </td>
                                    <td><a href="{{ route('projects.show', $invoice->project) }}">{{ $invoice->project->name }}</a></td>
                                    <td class="nowrap">
                                        {{ $invoice->due_on?->format('j M Y') ?? 'No due date' }}
                                        <div><x-invoice-status :invoice="$invoice" /></div>
                                    </td>
                                    <td class="num strong">{{ Money::format($invoice->balance()) }}</td>
                                    <td>
                                        <div class="row-actions">
                                            <a href="{{ route('invoices.payments.create', $invoice) }}" class="btn btn-sm"><x-icon name="cash" class="icon icon-sm" /> Record payment</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Recent payments</h2>
                    <div class="sub">In selected period · {{ $cur }}</div>
                </div>
                <a href="{{ route('payments.index', ['currency' => $cur] + $rangeQuery) }}" class="btn btn-sm btn-ghost">All <x-icon name="arrow-right" class="icon icon-sm" /></a>
            </div>
            @if ($recentPayments->isEmpty())
                <x-empty-state icon="wallet" message="No {{ $cur }} payments in this period." />
            @else
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            @foreach ($recentPayments as $payment)
                                <tr>
                                    <td>
                                        <a class="strong" href="{{ $payment->invoice ? route('invoices.show', $payment->invoice) : route('projects.show', $payment->project) }}">{{ $payment->project->client->name }}</a>
                                        <span class="secondary">
                                            {{ $payment->paid_on->format('j M') }} · {{ $payment->invoice?->number ?? $payment->project->name }} · {{ $payment->method->label() }}
                                        </span>
                                    </td>
                                    <td class="num strong">{{ Money::format($payment->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Collections</h2>
                <div class="sub">Payments received per {{ $trend['unit'] }} · {{ $cur }} · {{ $range->label() }}</div>
            </div>
            <div class="strong nowrap">{{ Money::format($trend['total'], $cur) }}</div>
        </div>
        @if ($trend['total'] === 0)
            <x-empty-state icon="chart" message="No {{ $cur }} payments were received in this period, so there is nothing to chart yet.">
                <a href="{{ route('invoices.index', ['currency' => $cur, 'status' => 'open']) }}" class="btn btn-sm">See open invoices</a>
            </x-empty-state>
        @else
            <div class="chart" id="collections-chart">
                <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" role="img" aria-label="Bar chart of payments received per {{ $trend['unit'] }} in {{ $cur }}">
                    @foreach ([0, 0.5, 1] as $fraction)
                        @php
                            $y = $padT + $plotH * (1 - $fraction);
                        @endphp
                        <line class="grid-line" x1="{{ $padL }}" x2="{{ $chartW - $padR }}" y1="{{ $y }}" y2="{{ $y }}" />
                        <text class="axis-label" x="{{ $padL - 8 }}" y="{{ $y + 4 }}" text-anchor="end">{{ number_format($niceMax * $fraction / 100) }}</text>
                    @endforeach
                    @foreach ($buckets as $i => $bucket)
                        @php
                            $cx = $padL + $band * ($i + 0.5);
                            $h = $niceMax > 0 ? $plotH * $bucket['amount'] / $niceMax : 0;
                            $x = $cx - $barW / 2; $base = $padT + $plotH; $top = $base - $h; $r = min(4, $h, $barW / 2);
                        @endphp
                        <g class="bar-group">
                            @if ($h > 0)
                                <path class="bar" d="M{{ $x }},{{ $base }} V{{ $top + $r }} Q{{ $x }},{{ $top }} {{ $x + $r }},{{ $top }} H{{ $x + $barW - $r }} Q{{ $x + $barW }},{{ $top }} {{ $x + $barW }},{{ $top + $r }} V{{ $base }} Z" />
                            @endif
                            <rect class="bar-hit" x="{{ $padL + $band * $i }}" y="{{ $padT }}" width="{{ $band }}" height="{{ $plotH }}"
                                  data-tip="{{ $bucket['title'] }}: {{ Money::format($bucket['amount'], $cur) }}" data-x="{{ $cx }}" data-y="{{ $top ?? $base }}">
                                <title>{{ $bucket['title'] }}: {{ Money::format($bucket['amount'], $cur) }}</title>
                            </rect>
                        </g>
                        @if ($i % $labelEvery === 0)
                            <text class="axis-label" x="{{ $cx }}" y="{{ $chartH - 6 }}" text-anchor="middle">{{ $bucket['label'] }}</text>
                        @endif
                    @endforeach
                    <line x1="{{ $padL }}" x2="{{ $chartW - $padR }}" y1="{{ $padT + $plotH }}" y2="{{ $padT + $plotH }}" stroke="#cfc9bd" />
                </svg>
                <div class="chart-tip" role="tooltip"></div>
            </div>
            <details class="table-toggle">
                <summary>Show as table</summary>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ ucfirst($trend['unit']) }}</th><th class="num">Received ({{ $cur }})</th></tr></thead>
                        <tbody>
                            @foreach (array_filter($buckets, fn ($b) => $b['amount'] > 0) as $bucket)
                                <tr><td>{{ $bucket['title'] }}</td><td class="num">{{ Money::format($bucket['amount']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Active projects</h2>
                <div class="sub">Project balance = agreed segment charges − payments received. This is separate from invoice balances.</div>
            </div>
            <a href="{{ route('projects.index', ['status' => 'active', 'currency' => $cur]) }}" class="btn btn-sm btn-ghost">View all <x-icon name="arrow-right" class="icon icon-sm" /></a>
        </div>
        @if ($activeProjects->isEmpty())
            <x-empty-state icon="folder" message="No active {{ $cur }} projects.">
                <a href="{{ route('projects.start') }}" class="btn btn-sm"><x-icon name="plus" class="icon icon-sm" /> New project</a>
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table table-min-640">
                    <thead>
                        <tr><th>Client / Project</th><th class="num">Agreed charges</th><th class="num">Received</th><th class="num">Project balance</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($activeProjects as $project)
                            @php($charges = (int) $project->segments_sum_amount)
                            @php($received = (int) $project->payments_sum_amount)
                            <tr>
                                <td class="primary-cell">
                                    <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>
                                    <span class="secondary">{{ $project->client->name }}</span>
                                </td>
                                <td class="num">{{ Money::format($charges) }}</td>
                                <td class="num">{{ Money::format($received) }}</td>
                                <td class="num strong">{{ Money::format($charges - $received) }}</td>
                                <td><span class="badge badge-{{ $project->status->value }}">{{ $project->status->label() }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @push('scripts')
        <script>
            (function () {
                const form = document.getElementById('dashboard-filters');
                const range = document.getElementById('range');
                range.addEventListener('change', function () {
                    const custom = range.value === 'custom';
                    form.querySelectorAll('.custom-dates').forEach(el => el.hidden = !custom);
                    if (!custom) form.submit();
                });
                document.getElementById('currency').addEventListener('change', () => form.submit());

                const chart = document.getElementById('collections-chart');
                if (!chart) return;
                const tip = chart.querySelector('.chart-tip');
                const svg = chart.querySelector('svg');
                chart.querySelectorAll('.bar-hit').forEach(function (hit) {
                    hit.addEventListener('mouseenter', function () {
                        const box = svg.getBoundingClientRect();
                        const scaleX = box.width / {{ $chartW }}, scaleY = box.height / {{ $chartH }};
                        tip.textContent = hit.dataset.tip;
                        tip.style.left = (svg.offsetLeft + hit.dataset.x * scaleX) + 'px';
                        tip.style.top = (svg.offsetTop + hit.dataset.y * scaleY - 4) + 'px';
                        tip.classList.add('show');
                    });
                    hit.addEventListener('mouseleave', () => tip.classList.remove('show'));
                });
            })();
        </script>
    @endpush
</x-layout>
