@use('App\Support\Money')
<x-layout title="Handover #{{ $handover->id }}">
    <x-page-header :title="'Handover #'.$handover->id" :crumbs="[['Client Handovers', route('sourcing.handovers')], ['#'.$handover->id, null]]">
        <x-slot:meta><x-status :value="$handover->status" /> · {{ $handover->lot->number }} · {{ $handover->line->description }} · {{ $handover->quantity }} to {{ $handover->project->client->name }} / {{ $handover->project->name }}</x-slot:meta>
    </x-page-header>

    <section class="panel"><div class="panel-body">
        <dl style="display:grid; grid-template-columns:max-content 1fr; gap:.3rem 1rem">
            <dt>Proposed on</dt><dd>{{ $handover->proposed_on->format('j M Y') }} by {{ $handover->preparer->name }}</dd>
            <dt>Control passed on</dt><dd>{{ $handover->transferred_on->format('j M Y') }}</dd>
            <dt>Evidence</dt><dd>{{ $handover->evidence_reference }} @if ($handover->evidence_note)<span class="muted">· {{ $handover->evidence_note }}</span>@endif</dd>
            <dt>Decision</dt><dd>{{ $handover->approver ? ucfirst($handover->status).' by '.$handover->approver->name.' on '.$handover->approved_at->format('j M Y') : 'Not decided' }} @if ($handover->decision_note)<span class="muted">· {{ $handover->decision_note }}</span>@endif</dd>
            @if ($handover->status === 'approved')
                <dt>Cost released</dt><dd>{{ Money::format($handover->cogs_value) }} (purchase {{ Money::format($handover->purchase_value) }} + landed {{ Money::format($handover->landed_value) }})</dd>
                <dt>Still with the client</dt><dd>{{ $handover->netQuantity() }} (resalable returned {{ $handover->returned_quantity }}, damaged returned {{ $handover->damaged_returned_quantity }})</dd>
            @endif
        </dl>
    </div></section>

    @if ($handover->journalEntry)
    <section class="panel">
        <div class="panel-head"><h2>Source journal entry {{ $handover->journalEntry->reference() }}</h2></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
            @foreach ($handover->journalEntry->lines as $l)<tr><td>{{ $l->account }}</td><td class="num">{{ $l->debit ? Money::format($l->debit) : '' }}</td><td class="num">{{ $l->credit ? Money::format($l->credit) : '' }}</td></tr>@endforeach
        </tbody></table></div>
        <div class="panel-body muted">{{ $handover->journalEntry->memo }} · dated {{ $handover->journalEntry->entry_date->format('j M Y') }}</div>
    </section>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Inventory movements</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>Dated</th><th>Type</th><th>Component</th><th class="num">Quantity</th><th class="num">Value</th><th>Journal</th></tr></thead><tbody>
            @forelse ($movements as $m)<tr><td>{{ $m->effective_on->format('j M Y') }}</td><td>{{ $m->type }}</td><td>{{ $m->component }}</td><td class="num">{{ $m->quantity_delta }}</td><td class="num">{{ Money::format($m->value_delta) }}</td><td>{{ $m->journal_entry_id ? '#'.$m->journal_entry_id : '—' }}</td></tr>
            @empty<tr><td colspan="6" class="muted">None: nothing is posted until the handover is approved.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    @if ($handover->returns->isNotEmpty())
    <section class="panel">
        <div class="panel-head"><h2>Returns</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>#</th><th class="num">Qty</th><th>Condition</th><th>Returned on</th><th>Evidence</th><th class="num">Cost restored</th><th>Status</th></tr></thead><tbody>
            @foreach ($handover->returns as $r)<tr><td>#{{ $r->id }}</td><td class="num">{{ $r->quantity }}</td><td>{{ ucfirst($r->condition) }}</td><td>{{ $r->returned_on->format('j M Y') }}</td><td>{{ $r->evidence_reference }}</td><td class="num">{{ $r->restored_value !== null ? Money::format($r->restored_value) : '—' }}</td><td><x-status :value="$r->status" /></td></tr>@endforeach
        </tbody></table></div>
    </section>
    @endif
</x-layout>
