@use('App\Support\Money')
@php
    // The simple client-lot page: progress, the one action for the current stage, the lot, its numbers, an always-available Edit and the
    // activity history. Every figure comes from LotFinancials ($f), the same numbers the reports use.
    $lines = $lot->lines->sortBy([['position', 'asc'], ['id', 'asc']])->values();
    $line = $lines->first();
    $several = $lines->count() > 1;
    $fmtQty = fn ($q) => rtrim(rtrim((string) $q, '0'), '.');
    $stage = $lot->stage();
    $replaced = $lot->costs->pluck('replaces_id')->filter()->all();
    $delivery = (int) $lot->costs->reject(fn ($c) => in_array($c->id, $replaced, true))->where('kind', 'actual')->where('category', 'Delivery')->sum('amount');
    $otherCosts = $f['additional'] - $delivery;
    $isLoss = $f['profit'] < 0;
    $profitWord = $stage === 'completed' ? ($isLoss ? 'Final loss' : 'Final profit') : ($isLoss ? 'Expected loss' : 'Expected profit');
    $qty = rtrim(rtrim((string) $line?->quantity, '0'), '.');
    $arrival = $line ? \App\Models\SourcingArrivalEvent::with('location')->where('sourcing_lot_line_id', $line->id)->latest('id')->first() : null;
    $canManage = auth()->user()?->can('sourcing.manage');
    $history = \App\Models\AuditLog::with('user:id,name')->where('subject', 'lot '.$lot->number)->latest('id')->limit(15)->get();
    $steps = [
        ['label' => 'Processing', 'when' => $lot->created_at, 'who' => $lot->creator?->name, 'now' => 'Ready to order'],
        ['label' => 'In order', 'when' => $lot->ordered_at, 'who' => $lot->orderedBy?->name, 'now' => 'Awaiting product receiving'],
        ['label' => 'Completed', 'when' => $lot->completed_at ?? ($stage === 'completed' ? $lot->updated_at : null), 'who' => $lot->completedBy?->name, 'now' => ''],
    ];
    $position = ['processing' => 0, 'in_order' => 1, 'completed' => 3, 'cancelled' => -1][$stage];
@endphp

@if ($stage === 'cancelled')
    <div class="flash flash-error" role="status"><x-icon name="alert" /> <div><strong>Cancelled.</strong> {{ $lot->cancel_reason }}</div></div>
@else
<ol class="lot-stepper" aria-label="Lot progress">
    @foreach ($steps as $i => $s)
        <li @class(['is-done' => $position > $i, 'is-current' => $position === $i]) @if ($position === $i) aria-current="step" @endif>
            <span class="dot" aria-hidden="true">{{ $position > $i ? '✓' : $i + 1 }}</span>
            <span><strong>{{ $s['label'] }}</strong><small>{{ $position === $i ? $s['now'] : ($s['when'] && $position > $i ? $s['when']->format('j M Y').($s['who'] ? ' · '.$s['who'] : '') : 'Not yet') }}</small></span>
        </li>
    @endforeach
</ol>
@endif

@foreach (\App\Services\ClientLot::reviewFlags($lot) as $flag)
    <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div><strong>Needs review:</strong> {{ $flag }}</div></div>
@endforeach

{{-- The one thing to do next, and Edit — always available. --}}
<section class="panel">
    <div class="panel-body lot-next">
        @if (in_array($lot->status, ['priced', 'approved'], true))
            <p><strong>Ready to order.</strong> <span class="muted">Enter the delivery charge to put the lot in order.</span></p>
            @if ($canManage)<button type="button" class="btn btn-primary" data-open-dialog="order-dialog">Mark In order</button>@endif
        @elseif ($lot->status === 'in_progress')
            <p><strong>Awaiting product receiving.</strong> <span class="muted">When the products have arrived, mark the lot completed.</span></p>
            @if ($canManage)<button type="button" class="btn btn-primary" data-open-dialog="complete-dialog">Mark Completed</button>@endif
        @elseif ($stage === 'completed')
            <p><strong>Completed.</strong> <span class="muted">Goods received into stock.</span></p>
        @endif
        @if ($canManage && $stage !== 'cancelled')
            <button type="button" class="btn" data-open-dialog="edit-dialog"><x-icon name="edit" /> Edit lot</button>
        @endif
    </div>
</section>

<div class="lot-grid">
    <section class="panel">
        <div class="panel-head"><h2>Lot information</h2></div>
        <div class="panel-body"><table class="lot-figures">
            <tr><td>{{ $lines->pluck('supplier_id')->unique()->count() > 1 ? 'Suppliers' : 'Supplier' }}</td><td>{{ $lines->map(fn ($l) => $l->supplier?->name)->filter()->unique()->implode(', ') ?: '—' }}</td></tr>
            @if ($several)
                <tr><td>Products</td><td>{{ $lines->count() }} products <span class="muted">· listed below</span></td></tr>
            @else
                <tr><td>Product</td><td>{{ $line?->description ?? '—' }}</td></tr>
            @endif
            <tr><td>Project</td><td>{{ $lot->project?->name ?? '—' }}@if ($lot->project?->client) <span class="muted">· {{ $lot->project->client->name }}</span>@endif</td></tr>
            @unless ($several)<tr><td>Quantity</td><td>{{ $qty }} {{ $line?->unit }}</td></tr>@endunless
            @if ($line?->received_quantity !== null)
                @unless ($several)<tr><td>Received</td><td>{{ rtrim(rtrim((string) $line->received_quantity, '0'), '.') }} · {{ $line->arrived_on?->format('j M Y') }}</td></tr>@endunless
                @if ($arrival)<tr><td>Delivery note / GRN</td><td>{{ $arrival->reference }}@if ($arrival->location) <span class="muted">· {{ $arrival->location->name }}</span>@endif</td></tr>@endif
            @endif
            @if ($lot->notes)<tr><td>Notes</td><td>{{ $lot->notes }}</td></tr>@endif
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Financial summary</h2></div>
        <div class="panel-body"><table class="lot-figures">
            <tr><td>Total base cost @unless ($several)<span class="muted">({{ $qty }} × {{ Money::format((int) $line?->unit_price) }})</span>@else<span class="muted">({{ $lines->count() }} products)</span>@endunless</td><td>{{ Money::format($f['purchase']) }}</td></tr>
            <tr><td>Delivery charge</td><td>{{ $lot->ordered_at || $delivery > 0 ? Money::format($delivery) : 'Added when ordered' }}</td></tr>
            @if ($otherCosts !== 0)<tr><td>Other costs</td><td>{{ Money::format($otherCosts) }}</td></tr>@endif
            <tr><td><strong>Total lot cost</strong></td><td><strong>{{ Money::format($f['landed']) }}</strong></td></tr>
            <tr><td>Client budget</td><td>{{ Money::format($f['revenue']) }}</td></tr>
            <tr class="total"><td>{{ $profitWord }}</td><td @class(['is-loss' => $isLoss, 'is-profit' => ! $isLoss])>{{ Money::format($f['profit']) }}</td></tr>
        </table></div>
    </section>
</div>

@if ($several)
<section class="panel">
    <div class="panel-head"><h2>Products</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Product</th><th>Supplier</th><th class="num">Quantity</th><th class="num">Unit base cost</th><th class="num">Base cost</th><th class="num">Received</th></tr></thead>
        <tbody>
        @foreach ($lines as $l)
            <tr><td>{{ $l->description }}</td><td>{{ $l->supplier?->name ?? '—' }}</td><td class="num">{{ $fmtQty($l->quantity) }} {{ $l->unit }}</td><td class="num">{{ Money::format((int) $l->unit_price) }}</td>
                <td class="num">{{ Money::format($l->purchaseCost()) }}</td>
                <td class="num">{{ $l->received_quantity !== null ? $fmtQty($l->received_quantity).' · '.$l->arrived_on?->format('j M Y') : '—' }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
</section>
@endif

<section class="panel">
    <div class="panel-head"><h2>Activity</h2></div>
    <div class="table-wrap"><table class="table">
        <tbody>
        @forelse ($history as $h)
            <tr><td class="nowrap muted">{{ $h->created_at?->format('j M Y H:i') }}</td><td>{{ $h->user?->name ?? 'System' }}</td>
                <td>{{ ucfirst(str_replace(['sourcing.client_lot.', 'sourcing.', '_', '.'], ['', '', ' ', ' '], $h->action)) }}@if ($h->details)<span class="secondary">{{ \Illuminate\Support\Str::limit($h->details, 220) }}</span>@endif</td></tr>
        @empty
            <tr><td class="muted">No activity yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

{{-- Mark In order: only the delivery charge (0 = free delivery); the totals update as it is typed. --}}
@if ($canManage && in_array($lot->status, ['priced', 'approved'], true))
<dialog class="confirm lot-dialog" id="order-dialog" aria-labelledby="order-title" data-landed="{{ $f['landed'] }}" data-budget="{{ $f['revenue'] }}">
    <form method="POST" action="{{ route('sourcing.lots.order', $lot) }}">@csrf
        <div class="dlg-body">
            <h2 id="order-title">Mark In order</h2>
            <table class="lot-figures">
                @if ($several)
                    @foreach ($lines as $l)<tr><td>{{ $l->description }}</td><td>{{ $fmtQty($l->quantity) }} × {{ Money::format((int) $l->unit_price) }}</td></tr>@endforeach
                @else
                <tr><td>Product base cost</td><td>{{ Money::format((int) $line->unit_price) }} / unit</td></tr>
                <tr><td>Quantity</td><td>{{ $qty }}</td></tr>
                @endif
                <tr><td>Total base cost</td><td>{{ Money::format($f['purchase']) }}</td></tr>
            </table>
            <div class="field"><label for="delivery_charge">Delivery charge (BDT)</label>
                <input id="delivery_charge" name="delivery_charge" inputmode="decimal" required value="{{ old('delivery_charge') }}" placeholder="0 for free delivery" autocomplete="off">
                @error('delivery_charge')<span class="error text-danger">{{ $message }}</span>@enderror</div>
            <table class="lot-figures" style="margin-top:.8rem">
                <tr><td>Total lot cost</td><td id="od-total">—</td></tr>
                <tr><td>Client budget</td><td>{{ Money::format($f['revenue']) }}</td></tr>
                <tr class="total"><td id="od-profit-label">Expected profit</td><td id="od-profit">—</td></tr>
            </table>
        </div>
        <div class="dlg-actions">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            <button class="btn btn-primary">Confirm</button>
        </div>
    </form>
</dialog>
@endif

{{-- Mark Completed: only the receiving details. --}}
@if ($canManage && $lot->status === 'in_progress')
<dialog class="confirm lot-dialog" id="complete-dialog" aria-labelledby="complete-title">
    <form method="POST" action="{{ route('sourcing.lots.complete', $lot) }}">@csrf
        <div class="dlg-body">
            <h2 id="complete-title">Mark Completed</h2>
            <table class="lot-figures">
                @foreach ($lines as $l)
                <tr><td>{{ $l->description }}</td><td>{{ $fmtQty(\App\Services\Receiving::expected($l)) ?: $fmtQty($l->quantity) }} {{ $l->unit }}</td></tr>
                @endforeach
                <tr><td><strong>Total lot cost</strong></td><td><strong>{{ Money::format($f['landed']) }}</strong></td></tr>
                <tr><td>Client budget</td><td>{{ Money::format($f['revenue']) }}</td></tr>
                <tr class="total"><td>{{ $isLoss ? 'Final loss' : 'Final profit' }}</td><td @class(['is-loss' => $isLoss, 'is-profit' => ! $isLoss])>{{ Money::format($f['profit']) }}</td></tr>
            </table>
            <div class="form-grid">
                <div class="field"><label for="arrived_on">Received on</label><input id="arrived_on" type="date" name="arrived_on" value="{{ old('arrived_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required>
                    @error('arrived_on')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="reference">Delivery note / GRN no.</label><input id="reference" name="reference" value="{{ old('reference') }}" required>
                    @error('reference')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                @if ($locations->count() > 1 || ($locations->isNotEmpty() && \App\Services\StockLocations::configured()))
                <div class="field"><label for="location_id">Stock location</label>
                    <select id="location_id" name="location_id" @if (\App\Services\StockLocations::configured()) required @endif>
                        @if ($locations->count() > 1)<option value="">Choose…</option>@endif
                        @foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected((int) old('location_id') === $loc->id)>{{ $loc->name }}</option>@endforeach
                    </select>
                    @error('location_id')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                @endif
            </div>
        </div>
        <div class="dlg-actions">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            <button class="btn btn-primary">Confirm — goods received</button>
        </div>
    </form>
</dialog>
@endif

{{-- Edit lot: everything, at any stage. Profit, project figures and reports follow; received stock is reconciled, never received twice. --}}
@if ($canManage && $stage !== 'cancelled')
@php
    $editProducts = \App\Models\SupplierProduct::with('supplier:id,name')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $lines->pluck('supplier_product_id')->filter()))->orderBy('name')->get();
    $editSuppliers = \App\Models\Supplier::where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $lines->pluck('supplier_id')->filter()))->orderBy('name')->get(['id', 'name']);
    $editProjects = \App\Models\Project::with('client:id,name')->where(fn ($q) => $q->whereNull('archived_at')->orWhere('id', $lot->project_id))->orderBy('name')->get();
@endphp
<dialog class="confirm lot-dialog" id="edit-dialog" aria-labelledby="edit-title">
    <form method="POST" action="{{ route('sourcing.lots.correct', $lot) }}">@csrf
        <div class="dlg-body">
            <h2 id="edit-title">Edit lot {{ $lot->number }}</h2>
            <div class="form-grid">
                @unless ($several)
                <div class="field"><label for="e_supplier">Supplier</label>
                    <select id="e_supplier" name="supplier_id" data-supplier-select>@foreach ($editSuppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id', $line?->supplier_id) === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                <div class="field"><label for="e_product">Product</label>
                    <select id="e_product" name="supplier_product_id" data-product-select>@foreach ($editProducts as $p)<option value="{{ $p->id }}" data-supplier="{{ $p->supplier_id }}" @selected((int) old('supplier_product_id', $line?->supplier_product_id) === $p->id)>{{ $p->label() }}</option>@endforeach</select></div>
                @endunless
                <div class="field"><label for="e_project">Project</label>
                    <select id="e_project" name="project_id">@foreach ($editProjects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id', $lot->project_id) === $p->id)>{{ $p->name }}{{ $p->client ? ' — '.$p->client->name : '' }}</option>@endforeach</select></div>
                @unless ($several)
                <div class="field"><label for="e_quantity">Quantity</label><input id="e_quantity" name="quantity" inputmode="numeric" required value="{{ old('quantity', $qty) }}">@error('quantity')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="e_unit">Unit base cost (BDT)</label><input id="e_unit" name="unit_cost" inputmode="decimal" required value="{{ old('unit_cost', Money::toDecimal((int) $line?->unit_price)) }}">@error('unit_cost')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                @endunless
                <div class="field"><label for="e_budget">Client budget (BDT)</label><input id="e_budget" name="client_budget" inputmode="decimal" required value="{{ old('client_budget', Money::toDecimal((int) $lot->client_budget)) }}">@error('client_budget')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="e_delivery">Delivery charge (BDT)</label><input id="e_delivery" name="delivery_charge" inputmode="decimal" required value="{{ old('delivery_charge', Money::toDecimal($delivery)) }}">@error('delivery_charge')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="e_stage">Stage</label>
                    <select id="e_stage" name="stage">@foreach (\App\Services\ClientLotCorrection::STAGE_STATUS as $value => $s)<option value="{{ $value }}" @selected(old('stage', $stage) === $value)>{{ \App\Models\SourcingLot::STAGES[$value] }}</option>@endforeach</select></div>
                @if ($line?->received_quantity !== null)
                    <div class="field"><label for="e_arrived">Received on</label><input id="e_arrived" type="date" name="arrived_on" max="{{ today()->toDateString() }}" value="{{ old('arrived_on', $line->arrived_on?->toDateString()) }}"></div>
                    <div class="field"><label for="e_reference">Delivery note / GRN</label><input id="e_reference" name="reference" value="{{ old('reference', $arrival?->reference) }}"></div>
                    @if ($locations->isNotEmpty())
                    <div class="field"><label for="e_location">Stock location</label>
                        <select id="e_location" name="location_id">@foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected((int) old('location_id', $arrival?->stock_location_id) === $loc->id)>{{ $loc->name }}</option>@endforeach</select></div>
                    @endif
                @endif
                @if ($several)
                <div class="field full"><div class="table-wrap"><table class="table">
                    <thead><tr><th>Supplier</th><th>Product</th><th class="num">Quantity</th><th class="num">Unit base cost (BDT)</th></tr></thead>
                    <tbody>
                    @foreach ($lines as $l)
                        <tr>
                            <td><select name="lines[{{ $l->id }}][supplier_id]" data-supplier-select aria-label="Supplier">@foreach ($editSuppliers as $s)<option value="{{ $s->id }}" @selected((int) old("lines.{$l->id}.supplier_id", $l->supplier_id) === $s->id)>{{ $s->name }}</option>@endforeach</select></td>
                            <td><select name="lines[{{ $l->id }}][supplier_product_id]" data-product-select aria-label="Product">@foreach ($editProducts as $p)<option value="{{ $p->id }}" data-supplier="{{ $p->supplier_id }}" @selected((int) old("lines.{$l->id}.supplier_product_id", $l->supplier_product_id) === $p->id)>{{ $p->label() }}</option>@endforeach</select>
                                @error("lines.{$l->id}.supplier_product_id")<span class="error text-danger">{{ $message }}</span>@enderror</td>
                            <td class="num"><input name="lines[{{ $l->id }}][quantity]" inputmode="numeric" required size="8" aria-label="Quantity" value="{{ old("lines.{$l->id}.quantity", $fmtQty($l->quantity)) }}">
                                @error("lines.{$l->id}.quantity")<span class="error text-danger">{{ $message }}</span>@enderror</td>
                            <td class="num"><input name="lines[{{ $l->id }}][unit_cost]" inputmode="decimal" required size="10" aria-label="Unit base cost (BDT)" value="{{ old("lines.{$l->id}.unit_cost", Money::toDecimal((int) $l->unit_price)) }}">
                                @error("lines.{$l->id}.unit_cost")<span class="error text-danger">{{ $message }}</span>@enderror</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div></div>
                @endif
                <div class="field full"><label for="e_notes">Notes</label><input id="e_notes" name="notes" maxlength="5000" value="{{ old('notes', $lot->notes) }}"></div>
                <div class="field full"><label for="e_reason">Note for the activity history <span class="opt">(optional)</span></label><input id="e_reason" name="reason" maxlength="500" value="{{ old('reason') }}" placeholder="e.g. supplier gave a discount"></div>
            </div>
            <p class="muted" style="margin-top:.6rem">Profit, the project figures and reports update at once. Changing the quantity or location after receiving adjusts the existing stock; nothing is received twice. Payments are not changed here.</p>
        </div>
        <div class="dlg-actions">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            <button class="btn btn-primary">Save changes</button>
        </div>
    </form>
</dialog>
@endif

@push('scripts')
@include('sourcing.lots.partials.money-js')
<script>
(function () {
    document.querySelectorAll('[data-open-dialog]').forEach(function (b) {
        b.addEventListener('click', function () { var d = document.getElementById(b.dataset.openDialog); if (d) { d.showModal(); } });
    });
    document.querySelectorAll('dialog.lot-dialog [data-close-dialog]').forEach(function (b) {
        b.addEventListener('click', function () { b.closest('dialog').close(); });
    });
    var order = document.getElementById('order-dialog');
    if (order) {
        var M = window.LotMoney, input = order.querySelector('#delivery_charge');
        var landed = parseInt(order.dataset.landed, 10), budget = parseInt(order.dataset.budget, 10);
        var update = function () {
            var d = M.toMinor(input.value), total = order.querySelector('#od-total'), profit = order.querySelector('#od-profit');
            if (d === null) { total.textContent = '—'; profit.textContent = '—'; profit.className = ''; return; }
            var p = budget - (landed + d);
            total.textContent = M.format(landed + d);
            profit.textContent = M.format(p); profit.className = p < 0 ? 'is-loss' : 'is-profit';
            order.querySelector('#od-profit-label').textContent = p < 0 ? 'Expected loss' : 'Expected profit';
        };
        input.addEventListener('input', update); update();
        @if ($errors->has('delivery_charge') && old('stage') === null) order.showModal(); @endif
    }
    var complete = document.getElementById('complete-dialog');
    @if ($errors->hasAny(['arrived_on', 'reference', 'location_id']) && old('stage') === null) if (complete) { complete.showModal(); } @endif
    var edit = document.getElementById('edit-dialog');
    if (edit) {
        // Each product list offers only the products of the supplier chosen beside it (its row, or the single-product supplier field).
        edit.querySelectorAll('[data-product-select]').forEach(function (prod) {
            var row = prod.closest('tr'), sup = row ? row.querySelector('[data-supplier-select]') : edit.querySelector('#e_supplier');
            var filter = function () {
                var first = null;
                Array.prototype.forEach.call(prod.options, function (o) { var show = o.dataset.supplier === sup.value; o.hidden = !show; o.disabled = !show; if (show && !first) { first = o; } });
                if (prod.selectedOptions.length === 0 || prod.selectedOptions[0].disabled) { if (first) { first.selected = true; } }
            };
            sup.addEventListener('change', filter); filter();
        });
        @if (old('stage') !== null && $errors->any()) edit.showModal(); @endif
    }
})();
</script>
@endpush
