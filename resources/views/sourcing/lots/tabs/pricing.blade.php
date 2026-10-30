@use('App\Support\Money')
@use('App\Support\Costing')
@php
    $editable = $lot->linesEditable();
    $method = $preview['method'] ?? $lot->pricing_method;
    $valueInput = $preview['raw'] ?? ($lot->pricing_method === 'markup' ? bcdiv((string) $lot->pricing_value, '100', 2) : Money::toDecimal($lot->pricing_value));
@endphp
<section class="panel">
    <div class="panel-head"><h2>Unit cost calculator</h2>
        <span class="muted">Landed unit cost = (purchase + direct costs + allocated shared costs) ÷ usable quantity</span></div>
    <div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Product</th><th class="num">Supplier base price</th><th class="num">Additional cost / unit</th><th class="num">Landed unit cost</th><th class="num">Break-even price</th><th class="num">Client price</th>@if ($preview)<th class="num">Preview price</th>@endif<th class="num">Unit profit</th><th class="num">Line profit</th></tr></thead>
        <tbody>
        @forelse ($f['lines'] as $id => $row)
            @php($breakdown = Money::format($row['purchase']).' + direct '.Money::format($row['direct']).' + shared '.Money::format($row['shared']).' = '.Money::format($row['landed']))
            <tr><td>{{ $row['line']->description }}<span class="secondary" title="{{ $breakdown }}">{{ $breakdown }}</span></td>
                <td class="num">{{ Money::format($row['line']->unit_price) }}</td>
                <td class="num">{{ $row['additional_per_unit'] }}</td>
                <td class="num strong">{{ $row['unit_cost'] }}</td>
                <td class="num">{{ Money::format($row['break_even']) }}</td>
                <td class="num">{{ $row['price'] === null ? '—' : Money::format($row['price']) }}@if ($row['price'] !== null && $row['price'] < $row['break_even'])<span class="secondary text-danger">Below break-even</span>@endif</td>
                @if ($preview)
                    @php($pp = $preview['prices'][$id])
                    <td class="num {{ $pp < $row['break_even'] ? 'text-danger' : '' }}">{{ Money::format($pp) }}<span class="secondary">margin {{ Costing::percent((int) bcsub((string) $pp, $row['unit_cost'], 0), $pp) ?? '—' }}%</span></td>
                @endif
                <td class="num">{{ $row['unit_profit'] ?? '—' }}</td>
                <td class="num {{ $row['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::format($row['profit']) }}</td></tr>
        @empty
            <tr><td colspan="9" class="muted">Add product lines first.</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr><td colspan="{{ $preview ? 8 : 7 }}">Lot profit (incl. client cost recovery)</td><td class="num">{{ Money::format($f['profit']) }}</td></tr></tfoot>
    </table></div>
    @if ($f['shared_total'] > 0)
        <p class="panel-body muted">Shared cost of {{ Money::format($f['shared_total']) }} is spread by {{ $lot->allocation_basis === 'quantity' ? 'quantity' : 'purchase value' }}: {{ collect($f['lines'])->map(fn ($r) => $r['line']->description.' '.Money::format($r['shared']))->implode(' · ') }}.</p>
    @endif
</section>

@if ($editable) @can('sourcing.manage')
<section class="panel">
    <div class="panel-head"><h2>Client pricing</h2>
        <span class="muted">Fixed profit adds an amount per unit. Markup is a percentage ON COST (not margin on the selling price).</span></div>
    <form method="POST" action="{{ route('sourcing.lots.pricing', $lot) }}" class="panel-body">@csrf
        <div class="form-grid">
            <div class="field"><label>Method</label><select name="method"><option value="fixed" @selected($method === 'fixed')>Fixed profit per unit</option><option value="markup" @selected($method === 'markup')>Markup % on cost</option></select></div>
            <div class="field"><label>Value (profit per unit, or markup %)</label><input name="value" value="{{ $valueInput }}" inputmode="decimal" required></div>
            @can('sourcing.override')
                @foreach ($f['lines'] as $id => $row)
                    <div class="field"><label>Manual price override — {{ $row['line']->description }}</label><input name="overrides[{{ $id }}]" inputmode="decimal" placeholder="leave blank to use the method"></div>
                @endforeach
            @endcan
        </div>
        <div style="margin-top:.9rem; display:flex; gap:.5rem; flex-wrap:wrap">
            <button class="btn btn-primary">Save pricing</button>
            <button class="btn" formmethod="get" formaction="{{ route('sourcing.lots.show', $lot) }}" name="preview" value="1">Preview only</button>
            <input type="hidden" name="tab" value="pricing">
        </div>
    </form>
</section>
@endcan @endif
