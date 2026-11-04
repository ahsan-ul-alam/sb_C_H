@use('App\Support\Money')
<x-layout title="Damage & Write-offs">
    <x-page-header title="Damage, Impairment & Write-offs">
        <x-slot:meta>Inventory/Operations records the physical finding, Finance reviews the evidence and the recoverable value, and a different Board member decides. Nothing is posted before that decision. A case with no supported recoverable value stays pending: the units stay set apart and are never released to sale or assumed worthless. Client-owned stock is only an incident: Sellbees posts no inventory, cost or loss for goods it does not own.</x-slot:meta>
    </x-page-header>

    @can('inventory.loss.propose')
    <section class="panel">
        <div class="panel-head"><h2>Record damage or loss</h2></div>
        <form method="POST" action="{{ route('sourcing.losses.store') }}" class="panel-body">@csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="form-grid">
                <div class="field"><label for="scope">What is affected <span class="req" aria-hidden="true">*</span></label>
                    <select id="scope" name="scope" required><option value="owned">Sellbees-owned stock</option><option value="return">A damaged return (control came back)</option><option value="custody">Client-owned stock in our warehouse</option></select></div>
                <div class="field"><label for="sourcing_lot_line_id">Sellbees stock line <span class="opt">(owned)</span></label>
                    <select id="sourcing_lot_line_id" name="sourcing_lot_line_id"><option value="">—</option>@foreach ($lines as $r)<option value="{{ $r['line']->id }}">{{ $r['line']->lot->number }} · {{ $r['line']->description }} — sellable {{ $r['own']['available'] }}, set apart {{ $r['own']['damaged'] }}</option>@endforeach</select></div>
                <div class="field"><label for="from_bucket">Units now <span class="opt">(owned)</span></label><select id="from_bucket" name="from_bucket"><option value="damaged">Already set apart as damaged</option><option value="available">In sellable stock</option></select></div>
                <div class="field"><label for="inventory_handover_return_id">Damaged return <span class="opt">(return)</span></label>
                    <select id="inventory_handover_return_id" name="inventory_handover_return_id"><option value="">—</option>@foreach ($returns as $r)<option value="{{ $r->id }}">Return #{{ $r->id }} of handover #{{ $r->inventory_handover_id }} · {{ $r->handover->line->description }} · {{ $r->quantity }} · {{ $r->handover->project->client->name }}</option>@endforeach</select></div>
                <div class="field"><label for="inventory_handover_id">Custody stock <span class="opt">(client-owned)</span></label>
                    <select id="inventory_handover_id" name="inventory_handover_id"><option value="">—</option>@foreach ($custody as $h)<option value="{{ $h->id }}">Handover #{{ $h->id }} · {{ $h->line->description }} · {{ $h->project->client->name }} / {{ $h->project->name }}</option>@endforeach</select></div>
                <div class="field"><label for="quantity">Quantity <span class="req" aria-hidden="true">*</span></label><input id="quantity" name="quantity" inputmode="decimal" required></div>
                <div class="field"><label for="outcome">Outcome <span class="opt">(not for client stock)</span></label>
                    <select id="outcome" name="outcome"><option value="write_off">Write off (nothing recoverable)</option><option value="impaired">Impaired (recoverable at a lower value)</option><option value="repairable">Repairable (recoverable after repair)</option></select></div>
                <div class="field"><label for="classification">Loss by cause <span class="opt">(not for client stock)</span></label>
                    <select id="classification" name="classification"><option value="abnormal">Abnormal: theft, unexpected damage, negligence, unexplained shortage</option><option value="normal">Normal: documented, unavoidable handling loss</option></select></div>
                <div class="field"><label for="recoverable_value">Recoverable value for these units <span class="opt">(leave empty if not yet assessed; 0 for a verified zero)</span></label><input id="recoverable_value" name="recoverable_value" inputmode="decimal"></div>
                <div class="field"><label for="valuation_basis">Basis of that value</label><input id="valuation_basis" name="valuation_basis" placeholder="Quote, repair estimate, salvage offer, or “no buyer, scrapped”"></div>
                <div class="field"><label for="event_on">Date it happened <span class="opt">(owned / custody; a return uses its own date)</span></label><input type="date" id="event_on" name="event_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}"></div>
                <div class="field"><label for="evidence_reference">Evidence <span class="req" aria-hidden="true">*</span></label><input id="evidence_reference" name="evidence_reference" required minlength="5" placeholder="Inspection report, police report, signed return note"></div>
                <div class="field full"><label for="cause">Cause <span class="req" aria-hidden="true">*</span></label><input id="cause" name="cause" required></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Submit for review</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Open cases</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>#</th><th>What</th><th class="num">Qty</th><th>Outcome</th><th class="num">Recoverable</th><th>Status</th><th>People</th><th></th></tr></thead>
            <tbody>
            @forelse ($open as $c)
                <tr><td><a href="{{ route('sourcing.losses.show', $c) }}">#{{ $c->id }}</a></td><td>{{ ucfirst($c->scope) }} · {{ $c->line->description }}@if ($c->handover) · {{ $c->handover->project->client->name }}@endif</td><td class="num">{{ $c->quantity }}</td>
                    <td>{{ str_replace('_', ' ', $c->outcome) }}{{ $c->classification ? ' · '.$c->classification : '' }}</td><td class="num">{{ $c->recoverable_value !== null ? Money::format($c->recoverable_value) : 'pending' }}</td><td><x-status :value="$c->status" /></td>
                    <td>Proposed {{ $c->preparer->name }}@if ($c->reviewer) · reviewed {{ $c->reviewer->name }}@endif</td>
                    <td class="num">
                        @if (in_array($c->status, ['pending_valuation', 'pending_review'], true))
                            @can('inventory.loss.review') @if ($c->prepared_by !== auth()->id())
                                <form method="POST" action="{{ route('sourcing.losses.review', $c) }}" style="display:inline-grid; gap:.3rem">@csrf
                                    <input name="review_note" placeholder="What Finance checked" required>@if ($c->scope !== 'custody')<input name="recoverable_value" placeholder="Recoverable value" inputmode="decimal" value="{{ $c->recoverable_value !== null ? Money::toDecimal($c->recoverable_value) : '' }}"><input name="valuation_basis" placeholder="Basis" value="{{ $c->valuation_basis }}">@endif
                                    <button class="btn btn-sm btn-primary">Mark reviewed</button></form>
                            @endif @endcan
                        @else
                            @can('inventory.loss.approve') @if ($c->prepared_by !== auth()->id() && $c->reviewed_by !== auth()->id())
                                <form method="POST" action="{{ route('sourcing.losses.decide', $c) }}" style="display:inline-flex; gap:.3rem; flex-wrap:wrap">@csrf<input name="reason" placeholder="Reason (needed to reject)" size="16"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
                            @else <span class="muted">You proposed or reviewed this</span> @endif @endcan
                        @endif
                        @if ($c->prepared_by === auth()->id())<form method="POST" action="{{ route('sourcing.losses.cancel', $c) }}" style="display:inline-flex; gap:.3rem">@csrf<input name="reason" placeholder="Reason" required size="12"><button class="btn btn-sm">Cancel</button></form>@endif
                    </td></tr>
            @empty
                <tr><td colspan="8" class="muted">No open cases.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Ownership and custody</h2></div>
        <div class="panel-body muted">Every approved handover's units, by where they stand now. Ownership passed to the client at handover; the goods stay in our warehouse as custody stock unless they physically left. The units of one handover are never counted in another's.</div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Handover</th><th>Client / project</th><th>Mode</th><th class="num">Still the client's</th><th class="num">Available</th><th class="num">Reserved</th><th class="num">Dispatched</th><th class="num">Delivered / with client</th><th class="num">Returned</th><th class="num">Damaged</th><th>Check</th></tr></thead>
            <tbody>
            @forelse ($ownership as $o)
                @php($h = $o['handover'])
                <tr><td><a href="{{ route('sourcing.handovers.show', $h) }}">#{{ $h->id }}</a> {{ $h->line->description }}</td><td>{{ $h->project->client->name }} / {{ $h->project->name }}</td><td>{{ $h->custody_mode === 'released' ? 'left with client' : 'our warehouse' }}</td><td class="num">{{ $o['check']['expected'] }}</td>
                    @foreach (['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'] as $b)<td class="num">{{ $o['check']['held'][$b] }}</td>@endforeach
                    <td>@if ($o['check']['ok'])<span class="badge badge-paid">OK</span>@else<span class="badge badge-partial" title="{{ implode(' ', $o['check']['differences']) }}">Differs</span>@endif</td></tr>
            @empty
                <tr><td colspan="11" class="muted">No approved handovers.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Decided cases</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th>Outcome</th><th class="num">Carrying</th><th class="num">Recoverable</th><th class="num">Loss</th><th>Treatment</th><th>Dated</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($history as $c)
                <tr><td><a href="{{ route('sourcing.losses.show', $c) }}">#{{ $c->id }}</a></td><td>{{ ucfirst($c->scope) }} · {{ $c->line->description }}</td><td class="num">{{ $c->quantity }}</td><td>{{ str_replace('_', ' ', $c->outcome) }}{{ $c->classification ? ' · '.$c->classification : '' }}</td>
                    <td class="num">{{ $c->carrying_value !== null ? Money::format($c->carrying_value) : '—' }}</td><td class="num">{{ $c->recoverable_value !== null ? Money::format($c->recoverable_value) : '—' }}</td><td class="num">{{ $c->loss_value !== null ? Money::format($c->loss_value) : '—' }}</td>
                    <td>{{ $c->treatment ? str_replace('_', ' ', $c->treatment) : '—' }}{{ $c->prior_period ? ' · prior period' : '' }}</td><td>{{ $c->posted_on?->format('j M Y') ?? '—' }}</td><td><x-status :value="$c->status" /></td></tr>
            @empty
                <tr><td colspan="10" class="muted">No decided cases yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Reconciliation status</h2><span class="badge badge-{{ $reconciliation['ok'] ? 'paid' : 'partial' }}">{{ $reconciliation['ok'] ? 'Reconciled' : 'Differences' }}</span></div>
        <div class="panel-body">
            <p>Impaired inventory in the ledger {{ Money::format($reconciliation['impaired_gl']) }} · approved cases {{ Money::format($reconciliation['impaired_cases']) }}. Inventory loss expense in the ledger {{ Money::format($reconciliation['abnormal_loss_gl']) }} · approved abnormal losses {{ Money::format($reconciliation['abnormal_loss_cases']) }}.</p>
            @if ($reconciliation['pending_valuation'] > 0)<p class="muted">{{ $reconciliation['pending_valuation'] }} case(s) are waiting for a supported recoverable value; their units stay set apart and nothing is recognised.</p>@endif
            @if ($reconciliation['prior_period'] > 0)<p class="muted">{{ $reconciliation['prior_period'] }} case(s) concern an event in a closed period and were recorded in the open period: Finance and the Board should consider whether a prior-period treatment is needed.</p>@endif
            @foreach ($reconciliation['problems'] as $p)<p class="text-danger">{{ $p }}</p>@endforeach
        </div>
    </section>
</x-layout>
