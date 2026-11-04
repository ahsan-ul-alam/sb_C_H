@use('App\Support\Money')
<x-layout title="Client Handovers">
    <x-page-header title="Client Handovers (transfer of control)">
        <x-slot:meta>Cost of goods sold is recognised only when control of Sellbees-owned goods passes to a client, shown by evidence such as a signed receiving document. An invoice, a payment or an allocation does not move stock or cost. A request posts nothing: a different Board member decides it, and only then is the cost released from inventory.</x-slot:meta>
    </x-page-header>

    @can('inventory.handover.propose')
    <section class="panel">
        <div class="panel-head"><h2>New handover request</h2></div>
        @if ($eligible->isEmpty())
            <div class="panel-body muted">No allocation can be handed over yet. It needs Sellbees-owned stock that the inventory engine values, fully settled and accepted, allocated to a client project.</div>
        @else
        <form method="POST" action="{{ route('sourcing.handovers.store') }}" class="panel-body">@csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="form-grid">
                <div class="field full"><label for="sourcing_allocation_id">Allocation <span class="req" aria-hidden="true">*</span></label>
                    <select id="sourcing_allocation_id" name="sourcing_allocation_id" required>
                        <option value="">Choose the allocation…</option>
                        @foreach ($eligible as $r)
                            <option value="{{ $r['allocation']->id }}" @selected((int) old('sourcing_allocation_id') === $r['allocation']->id)>{{ $r['allocation']->lot->number }} · {{ $r['allocation']->description }} → {{ $r['allocation']->project->client->name }} / {{ $r['allocation']->project->name }} — {{ $r['eligible'] }} left to hand over</option>
                        @endforeach
                    </select>
                    @error('sourcing_allocation_id') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="quantity">Quantity <span class="req" aria-hidden="true">*</span></label><input id="quantity" name="quantity" inputmode="decimal" value="{{ old('quantity') }}" required>@error('quantity') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="proposed_on">Proposed handover date <span class="req" aria-hidden="true">*</span></label><input type="date" id="proposed_on" name="proposed_on" value="{{ old('proposed_on', today()->toDateString()) }}" required></div>
                <div class="field"><label for="transferred_on">Date control actually passed <span class="req" aria-hidden="true">*</span></label><input type="date" id="transferred_on" name="transferred_on" max="{{ today()->toDateString() }}" value="{{ old('transferred_on') }}" required>@error('transferred_on') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="evidence_reference">Evidence reference <span class="req" aria-hidden="true">*</span></label><input id="evidence_reference" name="evidence_reference" value="{{ old('evidence_reference') }}" placeholder="Signed receiving note no., contract clause…" required minlength="5">@error('evidence_reference') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="custody_mode">Where are the goods?</label><select id="custody_mode" name="custody_mode"><option value="warehouse" @selected(old('custody_mode', 'warehouse') === 'warehouse')>Still in our warehouse (client's custody stock)</option><option value="released" @selected(old('custody_mode') === 'released')>Physically left with the client</option></select></div>
                <div class="field full"><label for="evidence_note">Note <span class="opt">(optional)</span></label><input id="evidence_note" name="evidence_note" value="{{ old('evidence_note') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Submit for approval</button>
        </form>
        @endif
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Awaiting approval</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>#</th><th>Lot / product</th><th>Client / project</th><th class="num">Qty</th><th>Control passed</th><th>Evidence</th><th>Proposed by</th><th></th></tr></thead>
            <tbody>
            @forelse ($pending as $h)
                <tr><td><a href="{{ route('sourcing.handovers.show', $h) }}">#{{ $h->id }}</a></td><td>{{ $h->lot->number }} · {{ $h->line->description }}</td><td>{{ $h->project->client->name }} / {{ $h->project->name }}</td><td class="num">{{ $h->quantity }}</td>
                    <td>{{ $h->transferred_on->format('j M Y') }}</td><td>{{ $h->evidence_reference }}</td><td>{{ $h->preparer->name }}</td>
                    <td class="num">
                        @can('inventory.handover.approve')
                            @if ($h->prepared_by !== auth()->id())
                                <form method="POST" action="{{ route('sourcing.handovers.decide', $h) }}" style="display:inline-flex; gap:.3rem; flex-wrap:wrap">@csrf<input name="reason" placeholder="Reason (needed to reject)" size="16"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
                            @else <span class="muted">You proposed this</span> @endif
                        @endcan
                        @if ($h->prepared_by === auth()->id())<form method="POST" action="{{ route('sourcing.handovers.cancel', $h) }}" style="display:inline-flex; gap:.3rem">@csrf<input name="reason" placeholder="Reason" required size="12"><button class="btn btn-sm">Cancel</button></form>@endif
                    </td></tr>
            @empty
                <tr><td colspan="8" class="muted">Nothing awaiting approval.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if ($pendingReturns->isNotEmpty())
        <div class="panel-body"><strong>Returns awaiting approval</strong></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Return</th><th>Handover</th><th class="num">Qty</th><th>Condition</th><th>Returned on</th><th>Evidence</th><th>Reason</th><th></th></tr></thead>
            <tbody>
            @foreach ($pendingReturns as $r)
                <tr><td>#{{ $r->id }}</td><td><a href="{{ route('sourcing.handovers.show', $r->handover) }}">#{{ $r->handover->id }}</a> {{ $r->handover->line->description }}</td><td class="num">{{ $r->quantity }}</td><td>{{ ucfirst($r->condition) }}</td><td>{{ $r->returned_on->format('j M Y') }}</td><td>{{ $r->evidence_reference }}</td><td>{{ $r->reason }}</td>
                    <td class="num">@can('inventory.handover.approve')@if ($r->prepared_by !== auth()->id())<form method="POST" action="{{ route('sourcing.handovers.returns.decide', $r) }}" style="display:inline-flex; gap:.3rem">@csrf<input name="reason" placeholder="Reason (to reject)" size="14"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>@endif @endcan
                        @if ($r->prepared_by === auth()->id())<form method="POST" action="{{ route('sourcing.handovers.returns.cancel', $r) }}" style="display:inline-flex; gap:.3rem">@csrf<input name="reason" placeholder="Reason" required size="12"><button class="btn btn-sm">Cancel</button></form>@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Handover history</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>#</th><th>Lot / product</th><th>Client / project</th><th class="num">Qty</th><th class="num">Cost of goods sold</th><th class="num">With client</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($history as $h)
                <tr><td><a href="{{ route('sourcing.handovers.show', $h) }}">#{{ $h->id }}</a></td><td>{{ $h->lot->number }} · {{ $h->line->description }}</td><td>{{ $h->project->client->name }} / {{ $h->project->name }}</td><td class="num">{{ $h->quantity }}</td>
                    <td class="num">{{ $h->cogs_value !== null ? Money::format($h->cogs_value) : '—' }}</td><td class="num">{{ $h->status === 'approved' ? $h->netQuantity() : '—' }}</td><td><x-status :value="$h->status" /></td>
                    <td class="num">@if ($h->status === 'approved' && bccomp($h->netQuantity(), '0', 2) > 0) @can('inventory.handover.propose')
                        <details><summary class="btn btn-sm">Return goods</summary>
                            <form method="POST" action="{{ route('sourcing.handovers.returns.store', $h) }}" style="display:grid; gap:.4rem; margin-top:.4rem; min-width:15rem">@csrf
                                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input name="quantity" placeholder="Quantity (max {{ $h->netQuantity() }})" inputmode="decimal" required>
                                <select name="condition" required aria-label="Condition"><option value="resalable">Resalable: cost restored</option><option value="damaged">Damaged: nothing restored</option></select>
                                <input type="date" name="returned_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required>
                                <input name="evidence_reference" placeholder="Return / control-transfer evidence" required minlength="5"><input name="reason" placeholder="Reason" required>
                                <button class="btn btn-sm btn-danger">Request return</button></form></details>@endcan @endif</td></tr>
            @empty
                <tr><td colspan="8" class="muted">No decided handovers yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Reconciliation status</h2><span class="badge badge-{{ $reconciliation['ok'] ? 'paid' : 'partial' }}">{{ $reconciliation['ok'] ? 'Reconciled' : 'Differences' }}</span></div>
        <div class="panel-body">
            <p>Cost of goods sold in the ledger {{ Money::format($reconciliation['gl_cogs']) }} · from approved handovers, net of resalable returns {{ Money::format($reconciliation['handover_net_cogs']) }} · difference {{ Money::format($reconciliation['difference']) }}.</p>
            @if ($reconciliation['damaged_awaiting_classification'] > 0)<p class="muted">{{ $reconciliation['damaged_awaiting_classification'] }} handover(s) have damaged goods returned: no cost was restored, and the loss or supplier claim still needs to be classified.</p>@endif
            @foreach ($reconciliation['problems'] as $p)<p class="text-danger">{{ $p }}</p>@endforeach
            @if ($reconciliation['by_project'])
                <table class="table"><thead><tr><th>Project</th><th class="num">Cost of goods sold</th></tr></thead><tbody>
                @foreach ($reconciliation['by_project'] as $projectId => $cogs)<tr><td>{{ \App\Models\Project::find($projectId)?->name ?? '#'.$projectId }}</td><td class="num">{{ Money::format($cogs) }}</td></tr>@endforeach
                </tbody></table>
            @endif
        </div>
    </section>
</x-layout>
