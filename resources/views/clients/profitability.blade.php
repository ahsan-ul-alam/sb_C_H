<x-layout title="Client profitability">
    <x-page-header title="Client profitability">
        <x-slot:meta>{{ $client->name }} · {{ $client->sb_number }}</x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('clients.expenses', $client) }}" class="btn">Client expenses</a>
            <a href="{{ route('clients.show', $client) }}" class="btn btn-ghost">Back to client</a>
        </x-slot:actions>
    </x-page-header>

    @php($f = $result['figures'])
    <section class="panel">
        <form method="GET" class="filters">
            <div class="field"><label for="project_id">Project</label>
                <select id="project_id" name="project_id"><option value="">All projects</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected(($filters['project_id'] ?? null) == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"></div>
            <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"></div>
            <button class="btn" type="submit">Show</button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Result: {{ ['complete' => 'Complete', 'incomplete' => 'Incomplete', 'no_sales' => 'No sales in this period'][$result['status']] }}</h2></div>
        <div class="panel-body">
            @if ($result['status'] === 'complete')
                <p><strong>Client net {{ $result['net_profit_minor'] >= 0 ? 'profit' : 'loss' }}: {{ \App\Support\Money::format($result['net_profit_minor']) }}</strong> (verified, realized, no estimates)</p>
            @elseif ($result['status'] === 'incomplete')
                <p><strong>No profit figure is given.</strong> The figures below are recorded components only. Resolve:</p>
                <ul>@foreach ($result['missing_data'] as $m)<li>{{ $m }}</li>@endforeach</ul>
            @endif
            <table class="table">
                <tbody>
                <tr><td>Net verified delivered sales</td><td class="num">{{ \App\Support\Money::format($f['net_sales_minor']) }}</td></tr>
                <tr><td>Sold-unit acquisition cost</td><td class="num">- {{ \App\Support\Money::format($f['sold_product_cost_minor']) }}</td></tr>
                <tr><td>Customer compensation (goodwill refunds)</td><td class="num">- {{ \App\Support\Money::format($f['customer_compensation_minor']) }}</td></tr>
                <tr><td>Client-borne operating expenses (approved)</td><td class="num">- {{ \App\Support\Money::format($f['client_borne_expenses_minor']) }}</td></tr>
                <tr><td>Sellbees commission</td><td class="num">- {{ \App\Support\Money::format($f['sellbees_commission_minor']) }}</td></tr>
                <tr><td>Sellbees service fees (storage)</td><td class="num">- {{ \App\Support\Money::format($f['sellbees_service_fees_minor']) }}</td></tr>
                <tr><td class="strong">Net profit / loss</td><td class="num strong">{{ $result['net_profit_minor'] === null ? 'not available' : \App\Support\Money::format($result['net_profit_minor']) }}</td></tr>
                <tr><td>Unsold inventory at acquisition cost (asset, not an expense)</td><td class="num">{{ \App\Support\Money::format($result['unsold_inventory']['cost_minor']) }}@if ($result['unsold_inventory']['lines_without_verified_cost'])<span class="secondary">{{ $result['unsold_inventory']['lines_without_verified_cost'] }} line(s) without verified cost</span>@endif</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    @if ($result['products'])
    <section class="panel">
        <div class="panel-head"><h2>By product</h2></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Product</th><th class="num">Units sold</th><th class="num">Net sales</th><th class="num">Cost</th><th class="num">Margin</th></tr></thead><tbody>
            @foreach ($result['products'] as $p)
                <tr><td>{{ $p['name'] }}</td><td class="num">{{ $p['units_sold'] }} {{ $p['unit'] }}</td><td class="num">{{ \App\Support\Money::format($p['net_sales_minor']) }}</td>
                    <td class="num">{{ $p['cost_verified'] ? \App\Support\Money::format($p['sold_cost_minor']) : 'unverified' }}</td>
                    <td class="num">{{ $p['cost_verified'] ? \App\Support\Money::format($p['net_sales_minor'] - $p['sold_cost_minor']) : '—' }}</td></tr>
            @endforeach
        </tbody></table></div>
    </section>
    @endif

    @can('client.profit.declare')
    <section class="panel">
        <div class="panel-head"><h2>Completeness declaration</h2></div>
        <div class="panel-body">
            <p>Declaration for this scope and period: <strong>{{ $result['declaration']['status'] }}</strong>. Declaring confirms that every applicable client-borne expense is recorded. It is voided automatically if sales, costs, refunds, commission or expenses change afterwards.</p>
            <form method="POST" action="{{ route('clients.profit.declare', $client) }}" class="stack">
                @csrf
                <input type="hidden" name="project_id" value="{{ $filters['project_id'] ?? '' }}"><input type="hidden" name="from" value="{{ $filters['from'] ?? '' }}"><input type="hidden" name="to" value="{{ $filters['to'] ?? '' }}">
                @if ($result['zero_categories_needed'])
                    <fieldset class="field"><legend>These categories have no approved expense. Tick each one that genuinely had no cost:</legend>
                        @foreach ($result['zero_categories_needed'] as $c)<label class="check"><input type="checkbox" name="zero[]" value="{{ $c }}"> {{ $categories[$c] }}</label>@endforeach</fieldset>
                @endif
                <div class="field"><label for="note">Note</label><input id="note" name="note"></div>
                <div><button class="btn btn-primary" type="submit">Declare complete</button></div>
            </form>
        </div>
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Declaration history</h2></div>
        @forelse ($declarations as $d)
            <div class="panel-body">{{ $d->declared_at->format('j M Y H:i') }} · {{ $d->declarer?->name }} · {{ $d->period_from?->toDateString() ?? 'start' }} to {{ $d->period_to?->toDateString() ?? 'now' }} · {{ $d->project_id ? 'project '.$d->project_id : 'all projects' }}
                @if ($d->revoked_at) <span class="badge badge-archived">Withdrawn</span>
                @else @can('client.profit.declare')<form method="POST" action="{{ route('clients.profit.revoke', $d) }}" style="display:inline">@csrf<button class="btn btn-sm">Withdraw</button></form>@endcan @endif</div>
        @empty
            <x-empty-state icon="wallet" message="No declarations yet." />
        @endforelse
    </section>
</x-layout>
