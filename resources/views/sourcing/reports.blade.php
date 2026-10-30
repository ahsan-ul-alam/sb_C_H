@use('App\Support\Money')
<x-layout title="Sourcing Reports">
    <x-page-header title="Sourcing Financial Reports">
        <x-slot:meta>Accrual view per lot. Product and supplier profit use line-level figures (direct and allocated shared costs; client cost recovery is lot-level and excluded there).</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <form method="GET" class="filters">
            <div class="field"><label>From</label><input type="date" name="from" value="{{ request('from') }}"></div>
            <div class="field"><label>To</label><input type="date" name="to" value="{{ request('to') }}"></div>
            <div class="field"><label>Supplier</label><select name="supplier"><option value="">All</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="field"><label>Product</label><select name="product"><option value="">All</option>@foreach ($products as $p)<option value="{{ $p->id }}" @selected(request('product') == $p->id)>{{ $p->name }} {{ $p->variant }}</option>@endforeach</select></div>
            <div class="field"><label>Client</label><select name="client"><option value="">All</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="field"><label>Status</label><select name="status"><option value="">All</option>@foreach (\App\Models\SourcingLot::STATUSES as $k => $label)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label>Result</label><select name="result"><option value="">All</option><option value="profit" @selected(request('result') === 'profit')>Profit</option><option value="loss" @selected(request('result') === 'loss')>Loss</option></select></div>
            <button class="btn">Apply</button>
        </form>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Lot (most profitable first)</th><th>Status</th><th class="num">Landed cost</th><th class="num">Sales value</th><th class="num">Profit / loss</th><th class="num">Unexpected</th><th class="num">Fund utilised</th></tr></thead>
            <tbody>
            @forelse ($lots as $lot)
                @php($f = $figures[$lot->id])
                <tr><td class="primary-cell"><a href="{{ route('sourcing.lots.show', $lot) }}">{{ $lot->number }}</a><span class="secondary">{{ $lot->title }}</span></td><td><x-status :value="$lot->status" /></td>
                    <td class="num">{{ Money::format($f['landed']) }}</td><td class="num">{{ Money::format($f['revenue']) }}</td>
                    <td class="num {{ $f['profit'] < 0 ? 'text-danger' : 'text-success' }}">{{ Money::format($f['profit']) }}</td>
                    <td class="num">{{ Money::format($f['unexpected']) }}</td><td class="num">{{ Money::format($f['fund_utilised']) }}</td></tr>
            @empty
                <tr><td colspan="7" class="muted">No lots match.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr><td colspan="4">Total (profit and loss netted)</td><td class="num">{{ Money::format((int) $lots->sum(fn ($l) => $figures[$l->id]['profit'])) }}</td><td colspan="2"></td></tr></tfoot>
        </table></div>
    </section>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:1rem">
        <section class="panel">
            <div class="panel-head"><h2>Product-wise profitability</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Product</th><th class="num">Lots</th><th class="num">Cost</th><th class="num">Sales</th><th class="num">Profit</th></tr></thead>
                <tbody>@forelse ($byProduct as $r)<tr><td>{{ $r['name'] }}</td><td class="num">{{ $r['lots'] }}</td><td class="num">{{ Money::format($r['landed']) }}</td><td class="num">{{ Money::format($r['revenue']) }}</td><td class="num {{ $r['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::format($r['profit']) }}</td></tr>@empty<tr><td colspan="5" class="muted">No data.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Supplier-wise profitability</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Supplier</th><th class="num">Purchases</th><th class="num">Cost</th><th class="num">Sales</th><th class="num">Profit</th></tr></thead>
                <tbody>@forelse ($bySupplier as $r)<tr><td>{{ $r['name'] }}</td><td class="num">{{ Money::format($r['purchase']) }}</td><td class="num">{{ Money::format($r['landed']) }}</td><td class="num">{{ Money::format($r['revenue']) }}</td><td class="num {{ $r['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::format($r['profit']) }}</td></tr>@empty<tr><td colspan="5" class="muted">No data.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Unexpected expense trend</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Month</th><th class="num">Unexpected costs</th></tr></thead>
                <tbody>@forelse ($unexpectedByMonth as $month => $amount)<tr><td>{{ $month }}</td><td class="num">{{ Money::format($amount) }}</td></tr>@empty<tr><td colspan="2" class="muted">None recorded.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Emergency Fund usage</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Lot</th><th class="num">Amount</th><th>Status</th></tr></thead>
                <tbody>@forelse ($fundRequests as $r)<tr><td><a href="{{ route('sourcing.lots.show', [$r->lot, 'tab' => 'emergency']) }}">{{ $r->lot->number }}</a></td><td class="num">{{ Money::format($r->amount) }}</td><td><x-status :value="$r->status" /></td></tr>@empty<tr><td colspan="3" class="muted">No requests.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
    </div>
</x-layout>
