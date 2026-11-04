@use('App\Support\Money')
<x-layout title="Earned Revenue">
    <x-page-header title="Earned Revenue, Credit Notes & Refunds">
        <x-slot:meta>{{ $cutover ? 'Accrual engine active from '.$cutover.'. Customer money from that date is a liability until revenue is earned; earlier money keeps its cash-basis booking.' : 'The accrual engine is OFF (no cutover configured): nothing here can be posted, and customer payments keep their cash-basis booking.' }} Finance proposes, a different Board member approves, and nothing posts before that.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <div class="panel-head"><h2>Projects: classification and positions</h2></div>
        <div class="table-wrap"><table class="table table-min-760"><thead><tr><th>Project</th><th>Sellbees acts as</th><th class="num">Cash collected</th><th class="num">Customer advance held</th><th class="num">Receivable</th><th class="num">Contract asset</th><th class="num">Earned revenue (net)</th></tr></thead><tbody>
            @foreach ($projects as $r)
                @php($p = $r['project'])@php($b = $r['b'])
                <tr><td>{{ $p->client->name }} / {{ $p->name }}</td>
                    <td>@if ($p->revenue_arrangement){{ ucfirst($p->revenue_arrangement) }}@else<span class="badge badge-partial">classification required</span>
                        @can('revenue.event.propose')<details><summary class="btn btn-sm">Classify</summary><form method="POST" action="{{ route('finance.revenue-events.classify', $p) }}" style="display:grid; gap:.3rem; margin-top:.3rem; min-width:15rem">@csrf<select name="arrangement"><option value="principal">Principal</option><option value="agent">Agent</option></select><input name="evidence" placeholder="Contract terms, control, fulfilment responsibility" required minlength="10"><button class="btn btn-sm">Save</button></form></details>@endcan @endif</td>
                    <td class="num">{{ Money::format($b['collected']) }}</td><td class="num">{{ Money::format($b['advances']) }}</td><td class="num">{{ Money::format($b['receivable']) }}</td><td class="num">{{ Money::format($b['contract_asset']) }}</td><td class="num">{{ Money::format($b['revenue']) }}</td></tr>
            @endforeach
        </tbody></table></div>
    </section>

    @can('revenue.event.propose')
    <section class="panel">
        <div class="panel-head"><h2>Propose an event</h2></div>
        <form method="POST" action="{{ route('finance.revenue-events.store') }}" class="panel-body">@csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="form-grid">
                <div class="field"><label for="project_id">Project <span class="req" aria-hidden="true">*</span></label><select id="project_id" name="project_id" required><option value="">Choose…</option>@foreach ($projects as $r)<option value="{{ $r['project']->id }}">{{ $r['project']->client->name }} / {{ $r['project']->name }}</option>@endforeach</select></div>
                <div class="field"><label for="kind">Event <span class="req" aria-hidden="true">*</span></label><select id="kind" name="kind"><option value="recognition">Revenue earned</option><option value="bill">Bill earned revenue on an invoice</option><option value="credit_note">Credit note / sales return</option><option value="refund">Customer refund</option></select></div>
                <div class="field"><label for="amount">Amount <span class="req" aria-hidden="true">*</span></label><input id="amount" name="amount" inputmode="decimal" required></div>
                <div class="field"><label for="effective_on">Date <span class="req" aria-hidden="true">*</span></label><input type="date" id="effective_on" name="effective_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required></div>
                <div class="field"><label for="basis">Basis <span class="opt">(earned revenue)</span></label><select id="basis" name="basis"><option value="">—</option><option value="sale">Sale of goods (needs a handover)</option><option value="service">Service</option><option value="commission">Commission</option><option value="service_fee">Service fee</option></select></div>
                <div class="field"><label for="invoice_id">Invoice</label><select id="invoice_id" name="invoice_id"><option value="">None (unbilled)</option>@foreach ($invoices as $i)<option value="{{ $i->id }}">{{ $i->number }} · {{ $i->project?->name }} · {{ Money::format($i->total) }}</option>@endforeach</select></div>
                <div class="field"><label for="related_event_id">Revenue being credited</label><select id="related_event_id" name="related_event_id"><option value="">—</option>@foreach ($recognitions as $e)<option value="{{ $e->id }}">#{{ $e->id }} · {{ $e->project->name }} · {{ Money::format($e->amount) }}</option>@endforeach</select></div>
                <div class="field"><label for="inventory_handover_id">Handover (principal sale)</label><input id="inventory_handover_id" name="inventory_handover_id" inputmode="numeric"></div>
                <div class="field"><label for="financial_account_id">Account (refund)</label><select id="financial_account_id" name="financial_account_id"><option value="">—</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                <div class="field full"><label for="evidence_reference">Evidence <span class="req" aria-hidden="true">*</span></label><input id="evidence_reference" name="evidence_reference" minlength="5" placeholder="Completion or delivery record, return note, bank reference"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Submit for approval</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Awaiting approval</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>#</th><th>Project</th><th>Event</th><th class="num">Amount</th><th>Evidence</th><th>Proposed by</th><th></th></tr></thead><tbody>
            @forelse ($open as $e)
                <tr><td>#{{ $e->id }}</td><td>{{ $e->project->name }}</td><td>{{ str_replace('_', ' ', $e->kind) }}{{ $e->basis ? ' · '.$e->basis : '' }}</td><td class="num">{{ Money::format($e->amount) }}</td><td>{{ $e->evidence_reference }}</td><td>{{ $e->preparer?->name }}</td>
                    <td class="num">@can('revenue.event.approve') @if ($e->prepared_by !== auth()->id())<form method="POST" action="{{ route('finance.revenue-events.decide', $e) }}" style="display:inline-flex; gap:.3rem">@csrf<input name="reason" placeholder="Reason (to reject)" size="14"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>@else<span class="muted">You proposed this</span>@endif @endcan</td></tr>
            @empty<tr><td colspan="7" class="muted">Nothing awaiting approval.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>History</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>#</th><th>Project</th><th>Event</th><th class="num">Amount</th><th>Dated</th><th>Status</th><th>Journal</th></tr></thead><tbody>
            @forelse ($history as $e)
                <tr><td>#{{ $e->id }}</td><td>{{ $e->project->name }}</td><td>{{ str_replace('_', ' ', $e->kind) }}{{ $e->basis ? ' · '.$e->basis : '' }}</td><td class="num">{{ Money::format($e->amount) }}</td><td>{{ $e->posted_on?->format('j M Y') ?? '—' }}{{ $e->prior_period ? ' · prior period' : '' }}</td><td><x-status :value="$e->status" /></td><td>{{ $e->journal_entry_id ? '#'.$e->journal_entry_id : '—' }}</td></tr>
            @empty<tr><td colspan="7" class="muted">No events yet.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Reconciliation status</h2><span class="badge badge-{{ $reconciliation['ok'] ? 'paid' : 'partial' }}">{{ $reconciliation['ok'] ? 'Reconciled' : 'Differences' }}</span></div>
        <div class="panel-body"><p>Since the cutover: earned {{ Money::format($reconciliation['revenue']) }}, credited {{ Money::format($reconciliation['returns']) }}, receivables {{ Money::format($reconciliation['receivable']) }}, contract assets {{ Money::format($reconciliation['contract_asset']) }}, customer advances {{ Money::format($reconciliation['advances']) }}.</p>
            @foreach ($reconciliation['problems'] as $p)<p class="text-danger">{{ $p }}</p>@endforeach</div>
    </section>

    @can('revenue.event.propose')
    <section class="panel">
        <div class="panel-head"><h2>Void an invoice</h2></div>
        <div class="panel-body muted">A void is recorded, never a delete, and only for an invoice with no revenue, credit or payment against it.</div>
        <div class="table-wrap"><table class="table table-min-640"><tbody>
            @foreach ($invoices->take(15) as $i)
                <tr><td>{{ $i->number }}</td><td>{{ $i->project?->name }}</td><td class="num">{{ Money::format($i->total) }}</td><td class="num"><form method="POST" action="{{ route('finance.revenue-events.void', $i) }}" style="display:inline-flex; gap:.3rem">@csrf<input name="reason" placeholder="Reason" required minlength="5"><button class="btn btn-sm btn-danger">Void</button></form></td></tr>
            @endforeach
        </tbody></table></div>
    </section>
    @endcan
</x-layout>