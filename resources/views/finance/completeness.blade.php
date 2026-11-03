@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout title="Financial Completeness">
    <x-page-header title="Financial Completeness">
        <x-slot:meta>A ledger that balances is not the same as accounting that is complete. Each layer below answers a different question and is never merged into one "OK". Only unresolved records that materially affect a month stop that month being closed, verified or allocated; drafts, purchases in progress and partial payments never do.</x-slot:meta>
    </x-page-header>

    <section class="panel"><div class="panel-body">
        <strong>{{ $report['headline'] }}</strong>
        <p class="muted" style="margin-top:.4rem">Unclassified receipts {{ $enforcedFrom ? 'block closing from '.$enforcedFrom.' onwards' : 'are reported only (FINANCE_COMPLETENESS_FROM is not set)' }}. A receipt classified as product sourcing without a sourcing record always blocks its month.</p>
    </div></section>

    @foreach ($report['layers'] as $layer)
    <section class="panel">
        <div class="panel-head"><h2>{{ $layer['title'] }}</h2><div class="strong">{{ ucfirst($layer['status']) }}</div></div>
        @if ($layer['findings'])
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Severity</th><th>Finding</th><th class="num">Amount</th><th>Evidence or decision needed</th></tr></thead>
            <tbody>
            @foreach ($layer['findings'] as $f)
                <tr><td>{{ ucfirst($f['severity']) }}</td><td>{{ $f['message'] }}</td><td class="num">{{ $f['amount'] !== null ? Money::format($f['amount'], $cur) : '' }}</td><td class="muted">{{ $f['evidence'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @else
            <div class="panel-body muted">Nothing outstanding in this layer.</div>
        @endif
    </section>
    @endforeach

    @if ($checklist)
    <section class="panel">
        <div class="panel-head"><h2>Evidence the owner must supply</h2></div>
        <div class="panel-body muted">Nothing is linked, paid or costed from quantities or amounts. For each item below, the records must come from the supplier, the bank and the delivery.</div>
        @foreach ($checklist as $c)
        <div class="panel-body">
            <strong>{{ $c['subject'] }}</strong>
            <p class="muted">{{ $c['known'] }}</p>
            <ul>@foreach ($c['needed'] as $n)<li>{{ $n }}</li>@endforeach</ul>
        </div>
        @endforeach
    </section>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Customer receipts and what they are for</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Invoice</th><th>Project</th><th class="num">Amount</th><th>Classified as</th><th>Sourcing record</th><th></th></tr></thead>
            <tbody>
            @foreach ($receipts as $r)
                <tr><td>{{ $r['payment']->paid_on->format('j M Y') }}</td><td>{{ $r['payment']->invoice?->number ?? 'none' }}</td><td>{{ $r['payment']->project?->name }}</td><td class="num">{{ Money::format($r['payment']->amount) }}</td>
                    <td>{{ $r['nature'] ? ($natures[$r['nature']] ?? $r['nature']) : 'Not classified' }}<span class="muted"> {{ $r['source'] ? '· '.$r['source'] : '' }}</span></td>
                    <td>{{ $r['nature'] === 'product_sourcing' ? ($r['covered'] ? 'Yes' : 'MISSING') : '—' }}</td>
                    <td>@can('reconcile.review')@if ($r['source'] !== 'sourcing billing')
                        <form method="POST" action="{{ route('finance.completeness.classify', $r['payment']) }}" style="display:flex; gap:.3rem; flex-wrap:wrap">@csrf
                            <select name="nature">@foreach ($natures as $k => $label)<option value="{{ $k }}" @selected($r['nature'] === $k)>{{ $label }}</option>@endforeach</select>
                            <input name="notes" placeholder="Note" style="max-width:10rem"><button class="btn btn-sm">Save</button>
                        </form>@endif @endcan</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</x-layout>
