@use('App\Support\Money')
@use('Illuminate\Support\Str')
@php
    // The simple client-lot page: progress, the one action for the current stage, the lot, its numbers and its money. Every figure comes from
    // LotFinancials ($f), the same numbers the reports use; nothing here is calculated a second way.
    $line = $lot->lines->first();
    $stage = $lot->stage();
    $replaced = $lot->costs->pluck('replaces_id')->filter()->all();
    $costs = $lot->costs->reject(fn ($c) => in_array($c->id, $replaced, true))->where('kind', 'actual');
    $delivery = (int) $costs->where('category', 'Delivery')->sum('amount');
    $otherCosts = $f['additional'] - $delivery;
    $isLoss = $f['profit'] < 0;
    $profitWord = $stage === 'completed' ? ($isLoss ? 'Final loss' : 'Final profit') : ($isLoss ? 'Expected loss' : 'Expected profit');
    $invoices = $lot->allocations->pluck('invoice')->filter()->unique('id');
    $supplierPaid = \App\Services\SourcingPayments::paidTotal($lot->payments);
    $costsToPay = $f['costs_unpaid'];
    $unpaidCosts = $costs->filter(fn ($c) => $c->remainingUnpaid() > 0 && ! $c->isPayable());
    $settleBlockers = $lot->status === 'completed' ? \App\Services\FinancialCompleteness::lotBlockers($lot) : [];
    $paidUp = $f['supplier_outstanding'] <= 0 && $f['costs_unpaid'] <= 0 && $f['estimates_open'] <= 0;
    $open = ! $lot->isClosed();
    $qty = rtrim(rtrim((string) $line?->quantity, '0'), '.');
    $badge = fn (bool $done, string $yes, string $no) => '<span class="badge badge-'.($done ? 'paid' : 'unpaid').'">'.e($done ? $yes : $no).'</span>';
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
        @php($done = $position > $i)
        <li @class(['is-done' => $done, 'is-current' => $position === $i]) @if ($position === $i) aria-current="step" @endif>
            <span class="dot" aria-hidden="true">{{ $done ? '✓' : $i + 1 }}</span>
            <span><strong>{{ $s['label'] }}</strong><small>{{ $position === $i ? $s['now'] : ($s['when'] && $done ? $s['when']->format('j M Y').($s['who'] ? ' · '.$s['who'] : '') : 'Not yet') }}</small></span>
        </li>
    @endforeach
</ol>
@endif

@foreach (\App\Services\ClientLot::reviewFlags($lot) as $flag)
    <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div><strong>Needs review:</strong> {{ $flag }}</div></div>
@endforeach

{{-- The one thing to do next. --}}
<section class="panel">
    <div class="panel-body lot-next">
        @switch($lot->status)
            @case('priced')
            @case('approved')
                <p><strong>Ready to order.</strong> <span class="muted">Confirm the delivery charge to place the order.</span></p>
                @can('sourcing.manage')<button type="button" class="btn btn-primary" data-open-dialog="order-dialog">Mark In order</button>@endcan
                @break
            @case('in_progress')
                <p><strong>Awaiting product receiving.</strong> <span class="muted">When the products have physically arrived, mark the lot completed.</span></p>
                @can('sourcing.manage')<button type="button" class="btn btn-primary" data-open-dialog="complete-dialog">Mark Completed</button>@endcan
                @break
            @case('completed')
                @if ($paidUp && ! $settleBlockers)
                    <p><strong>Completed.</strong> <span class="muted">Goods received and everything is paid.</span></p>
                    @can('sourcing.settle')<form method="POST" action="{{ route('sourcing.lots.settle', $lot) }}">@csrf<button class="btn btn-primary">Close lot</button></form>@endcan
                @else
                    <p><strong>Completed.</strong> <span class="muted">Goods received. @if (! $paidUp) Still to pay: {{ Money::format($f['supplier_outstanding'] + $f['costs_unpaid']) }} (record it under Money below). @endif {{ implode(' ', $settleBlockers) }}</span></p>
                @endif
                @break
            @case('settled')
                <p><strong>Completed.</strong> <span class="muted">Goods received, paid and closed.</span></p>
                @break
            @default
                <p class="muted">This lot is managed from the detailed view.</p>
        @endswitch
        @if (auth()->user()?->hasRole('super_admin'))
            <button type="button" class="btn" data-open-dialog="correct-dialog"><x-icon name="edit" /> Edit lot</button>
        @endif
    </div>
</section>

<div class="lot-grid">
    <section class="panel">
        <div class="panel-head"><h2>Lot information</h2></div>
        <div class="panel-body"><table class="lot-figures">
            <tr><td>Supplier</td><td>{{ $line?->supplier?->name ?? '—' }}</td></tr>
            <tr><td>Product</td><td>{{ $line?->description ?? '—' }}</td></tr>
            <tr><td>Project</td><td>{{ $lot->project?->name ?? '—' }}@if ($lot->project?->client) <span class="muted">· {{ $lot->project->client->name }}</span>@endif</td></tr>
            <tr><td>Quantity</td><td>{{ $qty }} {{ $line?->unit }}</td></tr>
            @if ($line?->received_quantity !== null)<tr><td>Received</td><td>{{ rtrim(rtrim((string) $line->received_quantity, '0'), '.') }} · {{ $line->arrived_on?->format('j M Y') }}</td></tr>@endif
            @if ($lot->purchase_order)<tr><td>Purchase order</td><td>{{ $lot->purchase_order }}</td></tr>@endif
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Financial summary</h2></div>
        <div class="panel-body"><table class="lot-figures">
            <tr><td>Total base cost <span class="muted">({{ $qty }} × {{ Money::format((int) $line?->unit_price) }})</span></td><td>{{ Money::format($f['purchase']) }}</td></tr>
            <tr><td>Delivery charge</td><td>{{ $lot->ordered_at || $delivery > 0 ? Money::format($delivery) : 'Added when ordered' }}</td></tr>
            @if ($otherCosts !== 0)<tr><td>Other costs</td><td>{{ Money::format($otherCosts) }}</td></tr>@endif
            <tr><td><strong>Total lot cost</strong></td><td><strong>{{ Money::format($f['landed']) }}</strong></td></tr>
            <tr><td>Client budget</td><td>{{ Money::format($f['revenue']) }}</td></tr>
            <tr class="total"><td>{{ $profitWord }}</td><td @class(['is-loss' => $isLoss, 'is-profit' => ! $isLoss])>{{ Money::format($f['profit']) }}</td></tr>
        </table></div>
    </section>
</div>

{{-- Real money only: what has been paid and received, and a place to record it. --}}
<section class="panel">
    <div class="panel-head"><h2>Money</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th></th><th class="num">Amount</th><th class="num">Paid / received</th><th class="num">Remaining</th><th></th></tr></thead>
        <tbody>
            <tr><td>Supplier</td><td class="num">{{ Money::format($f['purchase']) }}</td><td class="num">{{ Money::format($supplierPaid) }}</td><td class="num">{{ Money::format($f['supplier_outstanding']) }}</td>
                <td>{!! $badge($f['supplier_outstanding'] <= 0, 'Paid', 'To pay') !!}</td></tr>
            @if ($f['additional'] > 0)
            <tr><td>Delivery &amp; costs</td><td class="num">{{ Money::format($f['additional']) }}</td><td class="num">{{ Money::format($f['costs_paid']) }}</td><td class="num">{{ Money::format($costsToPay) }}</td>
                <td>{!! $badge($costsToPay <= 0, 'Paid', 'To pay') !!}</td></tr>
            @endif
            {{-- Client lots are not invoiced; only a lot billed separately (older lots, or manually under Advanced) shows its invoice here. --}}
            @if ($f['billed'] > 0)
            <tr><td>Client @if ($invoices->isNotEmpty())<span class="secondary">@foreach ($invoices as $inv)<a href="{{ route('invoices.show', $inv) }}">{{ $inv->number }}</a> @endforeach</span>@endif</td>
                <td class="num">{{ Money::format($f['revenue']) }}</td><td class="num">{{ Money::format($f['received']) }}</td><td class="num">{{ Money::format($f['revenue'] - $f['received']) }}</td>
                <td>@if ($f['revenue'] > 0 && $f['received'] >= $f['revenue'])<span class="badge badge-paid">Received</span>@elseif ($f['received'] > 0)<span class="badge badge-partial">Part received</span>@elseif ($f['billed'] > 0)<span class="badge badge-partial">Billed</span>@else<span class="badge badge-unpaid">Not billed</span>@endif</td></tr>
            @endif
        </tbody>
    </table></div>

    @if ($open && ($f['supplier_outstanding'] > 0 || $unpaidCosts->isNotEmpty() || $f['receivable'] > 0))
    <details class="panel-body">
        <summary style="cursor:pointer; font-weight:600">Record a payment</summary>
        <div class="lot-grid" style="margin-top:.8rem">
            @if ($f['supplier_outstanding'] > 0)
                @can('sourcing.payment.record')
                <form method="POST" action="{{ route('sourcing.lots.payments.store', $lot) }}" class="form-grid">@csrf
                    <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}"><input type="hidden" name="supplier_id" value="{{ $line->supplier_id }}">
                    <input type="hidden" name="purchase_order" value="{{ $lot->purchase_order ?: $lot->number }}">
                    <div class="field"><label for="sp-amount">Paid to supplier</label><input id="sp-amount" name="amount" inputmode="decimal" value="{{ Money::toDecimal($f['supplier_outstanding']) }}" required></div>
                    <div class="field"><label for="sp-date">Paid on</label><input id="sp-date" type="date" name="paid_on" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
                    <div class="field"><label for="sp-ref">Reference</label><input id="sp-ref" name="reference" placeholder="bank / bKash ref" oninput="this.form.evidence_reference.value = this.value"><input type="hidden" name="evidence_reference"></div>
                    <div class="field" style="align-self:end"><button class="btn btn-primary">Save supplier payment</button></div>
                </form>
                @endcan
            @endif
            @foreach ($unpaidCosts as $c)
                @can('sourcing.payment.record')
                <form method="POST" action="{{ route('sourcing.costs.pay', $c) }}" class="form-grid">@csrf
                    <div class="field"><label for="cost-{{ $c->id }}">{{ $c->category }} {{ Money::format($c->amount) }} paid on</label><input id="cost-{{ $c->id }}" type="date" name="paid_on" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
                    <div class="field" style="align-self:end"><button class="btn btn-primary">Save {{ strtolower($c->category) }} payment</button></div>
                </form>
                @endcan
            @endforeach
            @if ($f['receivable'] > 0)
                @can('clienthub')
                <form method="POST" action="{{ route('sourcing.lots.client-payment', $lot) }}" class="form-grid">@csrf
                    <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
                    <div class="field"><label for="cp-amount">Received from client</label><input id="cp-amount" name="amount" inputmode="decimal" placeholder="{{ Money::toDecimal($f['receivable']) }}" title="Leave blank for the full balance"></div>
                    <div class="field"><label for="cp-date">Received on</label><input id="cp-date" type="date" name="paid_on" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
                    <div class="field"><label for="cp-method">Method</label><select id="cp-method" name="method">@foreach (\App\Enums\PaymentMethod::cases() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select></div>
                    <div class="field"><label for="cp-ref">Reference</label><input id="cp-ref" name="reference"></div>
                    <div class="field" style="align-self:end"><button class="btn btn-primary">Save client payment</button></div>
                </form>
                @endcan
            @endif
        </div>
    </details>
    @endif
</section>


{{-- Mark In order: the delivery charge is required (0 = free delivery); the totals update as it is typed. --}}
@if (in_array($lot->status, ['priced', 'approved'], true))
<dialog class="confirm lot-dialog" id="order-dialog" aria-labelledby="order-title" data-landed="{{ $f['landed'] }}" data-budget="{{ $f['revenue'] }}">
    <form method="POST" action="{{ route('sourcing.lots.order', $lot) }}">@csrf
        <div class="dlg-body">
            <h2 id="order-title">Mark In order</h2>
            <table class="lot-figures">
                <tr><td>Product base cost</td><td>{{ Money::format((int) $line->unit_price) }} / unit</td></tr>
                <tr><td>Quantity</td><td>{{ $qty }}</td></tr>
                <tr><td>Total base cost</td><td>{{ Money::format($f['purchase']) }}</td></tr>
                @if ($f['additional'] > 0)<tr><td>Costs already recorded</td><td>{{ Money::format($f['additional']) }}</td></tr>@endif
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

{{-- Mark Completed: goods into stock, the final result shown before confirming, and the payments already made recorded in the same step. --}}
@if ($lot->status === 'in_progress')
<dialog class="confirm lot-dialog" id="complete-dialog" aria-labelledby="complete-title">
    <form method="POST" action="{{ route('sourcing.lots.complete', $lot) }}">@csrf
        <div class="dlg-body">
            <h2 id="complete-title">Mark Completed</h2>
            <table class="lot-figures">
                <tr><td>{{ $line->description }} <span class="muted">· {{ $line->supplier?->name }}</span></td><td>{{ rtrim(rtrim(\App\Services\Receiving::expected($line), '0'), '.') ?: $qty }} {{ $line->unit }}</td></tr>
                <tr><td>Project</td><td>{{ $lot->project?->name }}</td></tr>
                <tr><td>Total base cost</td><td>{{ Money::format($f['purchase']) }}</td></tr>
                <tr><td>Delivery charge</td><td>{{ Money::format($delivery) }}</td></tr>
                @if ($otherCosts !== 0)<tr><td>Other costs</td><td>{{ Money::format($otherCosts) }}</td></tr>@endif
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
            @if ($f['supplier_outstanding'] > 0)
                <label class="check"><input type="checkbox" name="supplier_paid" value="1" @checked(old('supplier_paid'))> <span>Supplier has been paid the remaining <strong>{{ Money::format($f['supplier_outstanding']) }}</strong></span></label>
            @endif
            @if ($unpaidCosts->isNotEmpty())
                <label class="check"><input type="checkbox" name="delivery_paid" value="1" @checked(old('delivery_paid'))> <span>Delivery charge <strong>{{ Money::format((int) $unpaidCosts->sum(fn ($c) => $c->remainingUnpaid())) }}</strong> has been paid</span></label>
            @endif
            @if ($f['supplier_outstanding'] > 0 || $unpaidCosts->isNotEmpty())
                <div class="form-grid">
                    <div class="field"><label for="paid_on">Paid on</label><input id="paid_on" type="date" name="paid_on" value="{{ old('paid_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}"></div>
                    <div class="field"><label for="payment_reference">Payment reference</label><input id="payment_reference" name="payment_reference" value="{{ old('payment_reference') }}" placeholder="optional"></div>
                </div>
            @endif
        </div>
        <div class="dlg-actions">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            <button class="btn btn-primary">Confirm — goods received</button>
        </div>
    </form>
</dialog>
@endif

{{-- Super administrator correction at any stage: every figure, posted payment and stock count follows the new numbers. --}}
@if (auth()->user()?->hasRole('super_admin'))
<dialog class="confirm lot-dialog" id="correct-dialog" aria-labelledby="correct-title">
    <form method="POST" action="{{ route('sourcing.lots.correct', $lot) }}">@csrf
        <div class="dlg-body">
            <h2 id="correct-title">Edit lot {{ $lot->number }}</h2>
            <p class="muted">Change anything, at any stage. Profit, the project figures, reports and the client portal update at once. Supplier and delivery
                payments already posted are rewritten to the new amounts (a lot paid in full stays paid in full), and received stock follows the new quantity.</p>
            <div class="form-grid">
                <div class="field"><label for="c_budget">Client budget (BDT)</label><input id="c_budget" name="client_budget" inputmode="decimal" required value="{{ old('client_budget', Money::toDecimal((int) $lot->client_budget)) }}">@error('client_budget')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="c_quantity">Quantity</label><input id="c_quantity" name="quantity" inputmode="numeric" required value="{{ old('quantity', $qty) }}">@error('quantity')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="c_unit">Unit base cost (BDT)</label><input id="c_unit" name="unit_cost" inputmode="decimal" required value="{{ old('unit_cost', Money::toDecimal((int) $line?->unit_price)) }}">@error('unit_cost')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="c_delivery">Delivery charge (BDT)</label><input id="c_delivery" name="delivery_charge" inputmode="decimal" required value="{{ old('delivery_charge', Money::toDecimal($delivery)) }}">@error('delivery_charge')<span class="error text-danger">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="c_stage">Stage</label>
                    <select id="c_stage" name="stage">
                        @foreach (['processing' => 'Processing', 'in_order' => 'In order', 'completed' => 'Completed', 'settled' => 'Completed — books closed', 'cancelled' => 'Cancelled'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('stage', $lot->status === 'settled' ? 'settled' : $stage) === $value)>{{ $label }}</option>
                        @endforeach
                    </select></div>
                <div class="field"><label for="c_project">Project</label>
                    <select id="c_project" name="project_id">
                        @foreach (\App\Models\Project::with('client:id,name')->whereNull('archived_at')->orWhere('id', $lot->project_id)->orderBy('name')->get() as $p)
                            <option value="{{ $p->id }}" @selected((int) old('project_id', $lot->project_id) === $p->id)>{{ $p->name }}{{ $p->client ? ' · '.$p->client->name : '' }}</option>
                        @endforeach
                    </select></div>
                <div class="field full"><label for="c_reason">Reason for the correction</label><input id="c_reason" name="reason" required minlength="5" maxlength="500" value="{{ old('reason') }}" placeholder="e.g. supplier gave a discount after delivery">@error('reason')<span class="error text-danger">{{ $message }}</span>@enderror</div>
            </div>
        </div>
        <div class="dlg-actions">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            <button class="btn btn-primary">Save correction</button>
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
        @if ($errors->has('delivery_charge')) order.showModal(); @endif
    }
    var complete = document.getElementById('complete-dialog');
    @if ($errors->hasAny(['arrived_on', 'reference', 'location_id', 'paid_on', 'payment_reference'])) if (complete) { complete.showModal(); } @endif
    var correct = document.getElementById('correct-dialog');
    @if ($errors->hasAny(['client_budget', 'quantity', 'unit_cost', 'stage', 'project_id', 'reason']) || ($errors->has('delivery_charge') && old('reason') !== null)) if (correct) { correct.showModal(); } @endif
})();
</script>
@endpush
