@use('App\Support\Money')
@php($editable = $lot->linesEditable())
<section class="panel">
    <div class="panel-head"><h2>Product lines &amp; supplier base costs</h2></div>
    <div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Product</th><th>Supplier</th><th class="num">Qty bought</th><th class="num">Usable qty</th><th class="num">Supplier unit price</th><th class="num">Catalog price then</th><th class="num">Purchase cost</th><th></th></tr></thead>
        <tbody>
        @forelse ($f['lines'] as $id => $row)
            @php($line = $row['line'])
            <tr>
                <td>{{ $line->description }}<span class="secondary">{{ $line->unit }}</span></td><td>{{ $line->supplier->name }}</td>
                @if ($editable && auth()->user()->can('sourcing.manage'))
                    <td class="num"><input form="line-{{ $id }}" name="quantity" value="{{ $line->quantity }}" size="8" inputmode="decimal"></td>
                    <td class="num"><input form="line-{{ $id }}" name="usable_quantity" value="{{ $line->usable_quantity }}" size="8" inputmode="decimal"></td>
                    <td class="num"><input form="line-{{ $id }}" name="unit_price" value="{{ Money::toDecimal($line->unit_price) }}" size="8" inputmode="decimal" @cannot('sourcing.override') readonly @endcannot></td>
                @else
                    <td class="num">{{ $line->quantity }}</td><td class="num">{{ $line->usable_quantity }}</td><td class="num">{{ Money::format($line->unit_price) }}</td>
                @endif
                <td class="num muted">{{ Money::format($line->reference_price) }}</td>
                <td class="num">{{ Money::format($row['purchase']) }}</td>
                <td class="num">
                    @if ($editable && auth()->user()->can('sourcing.manage'))
                        <button form="line-{{ $id }}" class="btn btn-sm">Save</button>
                        <form method="POST" action="{{ route('sourcing.lines.destroy', $line) }}" style="display:inline">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Remove</button></form>
                    @endif
                </td>
            </tr>
            @if (bccomp($row['damaged_qty'], '0', 2) > 0)
                <tr class="subrow"><td colspan="8">{{ $row['damaged_qty'] }} {{ $line->unit }} damaged/missing: cost of {{ Money::format($row['damage_loss']) }} stays in the lot and is spread over the usable units (visible in the unit cost).</td></tr>
            @endif
        @empty
            <tr><td colspan="8" class="muted">No product lines yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @if ($editable && auth()->user()->can('sourcing.manage'))
        @foreach ($f['lines'] as $id => $row)
            <form id="line-{{ $id }}" method="POST" action="{{ route('sourcing.lines.update', $row['line']) }}">@csrf @method('PUT')</form>
        @endforeach
    @endif
    @if ($editable) @can('sourcing.manage')
    <form method="POST" action="{{ route('sourcing.lots.lines.store', $lot) }}" class="panel-body">@csrf
        <div class="form-grid">
            <div class="field"><label>Catalog product</label>
                <select name="supplier_product_id" id="product-pick"><option value="">— custom line —</option>
                    @foreach ($products as $p)<option value="{{ $p->id }}" data-supplier="{{ $p->supplier_id }}" data-unit="{{ $p->unit }}">{{ $p->supplier->name }} · {{ $p->label() }} · {{ Money::format($p->reference_price) }}</option>@endforeach
                </select></div>
            <div class="field"><label>Supplier (custom line)</label>
                <select name="supplier_id" id="supplier-pick"><option value="">—</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
            <div class="field"><label>Description (custom line)</label><input name="description"></div>
            <div class="field"><label>Unit</label><input name="unit" placeholder="pcs"></div>
            <div class="field"><label>Quantity</label><input name="quantity" inputmode="decimal" required></div>
            <div class="field"><label>Usable qty (if different)</label><input name="usable_quantity" inputmode="decimal"></div>
            @can('sourcing.override')<div class="field"><label>Override supplier price (optional)</label><input name="unit_price" inputmode="decimal"></div>@endcan
        </div>
        <button class="btn btn-primary" style="margin-top:.9rem">Add line</button>
    </form>
    @push('scripts')
    <script>
        document.getElementById('product-pick')?.addEventListener('change', function () {
            var o = this.selectedOptions[0];
            if (o && o.dataset.supplier) { document.getElementById('supplier-pick').value = o.dataset.supplier; }
        });
    </script>
    @endpush
    @endcan @endif
</section>

<section class="panel">
    <div class="panel-head"><h2>Additional cost segments</h2>
        <span class="muted">Shared costs are spread by {{ $lot->allocation_basis === 'quantity' ? 'quantity' : 'purchase value' }}; the parts always sum to the whole.</span></div>
    <div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Date</th><th>Category</th><th>Applies to</th><th>Kind</th><th class="num">Amount</th><th>Payment</th><th></th></tr></thead>
        <tbody>
        @php($replaced = $lot->costs->pluck('replaces_id')->filter()->all())
        @forelse ($lot->costs as $c)
            @php($isReplaced = in_array($c->id, $replaced, true))
            <tr @if ($isReplaced) style="opacity:.55" @endif>
                <td class="nowrap">{{ $c->incurred_on->format('j M Y') }}</td>
                <td>{{ $c->category }}@if ($c->description)<span class="secondary">{{ $c->description }} {{ $c->payee ? '· '.$c->payee : '' }} {{ $c->reference ? '· ref '.$c->reference : '' }}</span>@endif</td>
                <td>{{ $c->sourcing_lot_line_id ? ($f['lines'][$c->sourcing_lot_line_id]['line']->description ?? 'Line') : 'Shared' }}</td>
                <td>{{ ucfirst($c->kind) }} @if ($c->is_unexpected)<span class="badge badge-unpaid">Unexpected</span>@endif @if ($isReplaced)<span class="badge badge-archived">Replaced by actual</span>@endif</td>
                <td class="num">{{ Money::format($c->amount) }}</td>
                <td>@if ($c->kind === 'actual' && ! $isReplaced)
                        @if ($c->isPaid())<x-status :value="$c->expense->status === 'verified' ? 'paid' : 'pending'" />
                        @else <span class="muted">Unpaid</span>
                            @can('sourcing.payment.record') @unless ($lot->isClosed())
                            <form method="POST" action="{{ route('sourcing.costs.pay', $c) }}" class="inline-form">@csrf<input type="date" name="paid_on" value="{{ today()->toDateString() }}"><button class="btn btn-sm">Pay</button></form>
                            @endunless @endcan
                        @endif
                    @endif</td>
                <td class="num">
                    @if (! $lot->isClosed()) @can('sourcing.manage')
                        @if ($c->kind === 'estimate' && ! $isReplaced)
                            <form method="POST" action="{{ route('sourcing.lots.costs.store', $lot) }}" class="inline-form">@csrf
                                <input type="hidden" name="kind" value="actual"><input type="hidden" name="replaces_id" value="{{ $c->id }}"><input type="hidden" name="category" value="{{ $c->category }}">
                                <input name="amount" size="8" placeholder="actual" inputmode="decimal" required><input type="date" name="incurred_on" value="{{ today()->toDateString() }}"><button class="btn btn-sm">Record actual</button></form>
                        @endif
                        @if (! $c->isPaid() && ! $isReplaced)
                            <form method="POST" action="{{ route('sourcing.costs.destroy', $c) }}" style="display:inline">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Remove</button></form>
                        @endif
                    @endcan @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No additional costs yet.</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr><td colspan="4">Counted additional costs (estimates replaced by actuals are not counted twice)</td><td class="num">{{ Money::format($f['additional']) }}</td><td colspan="2"></td></tr></tfoot>
    </table></div>
    @if (! $lot->isClosed()) @can('sourcing.manage')
    <form method="POST" action="{{ route('sourcing.lots.costs.store', $lot) }}" class="panel-body">@csrf
        <div class="form-grid">
            <div class="field"><label>Category</label><input name="category" list="cost-cats" required><datalist id="cost-cats">@foreach (\App\Models\SourcingLotCost::CATEGORIES as $cat)<option value="{{ $cat }}">@endforeach</datalist></div>
            <div class="field"><label>Description</label><input name="description"></div>
            <div class="field"><label>Amount</label><input name="amount" inputmode="decimal" required></div>
            <div class="field"><label>Estimate or actual</label><select name="kind"><option value="actual">Actual</option><option value="estimate">Estimate</option></select></div>
            <div class="field"><label>Date</label><input type="date" name="incurred_on" value="{{ today()->toDateString() }}" required></div>
            <div class="field"><label>Applies to</label><select name="line_id"><option value="">Shared across lines</option>@foreach ($f['lines'] as $id => $row)<option value="{{ $id }}">{{ $row['line']->description }}</option>@endforeach</select></div>
            <div class="field"><label>Payee</label><input name="payee"></div>
            <div class="field"><label>Reference</label><input name="reference"></div>
            <label class="check"><input type="checkbox" name="is_unexpected" value="1"> Unexpected expense</label>
        </div>
        <button class="btn btn-primary" style="margin-top:.9rem">Add cost</button>
        @if (in_array($lot->status, ['approved', 'in_progress', 'completed'])) <p class="muted">Actual costs added after pricing approval are flagged unexpected automatically and never change the approved client prices.</p> @endif
    </form>
    @endcan @endif
</section>
