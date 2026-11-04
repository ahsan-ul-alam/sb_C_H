@use('App\Support\Money')
<x-layout title="Case #{{ $case->id }}">
    <x-page-header :title="'Damage / loss case #'.$case->id" :crumbs="[['Damage & Write-offs', route('sourcing.losses')], ['#'.$case->id, null]]">
        <x-slot:meta><x-status :value="$case->status" /> · {{ ucfirst($case->scope) }} · {{ $case->lot->number }} · {{ $case->line->description }} · {{ $case->quantity }} units</x-slot:meta>
    </x-page-header>

    <section class="panel"><div class="panel-body">
        <dl style="display:grid; grid-template-columns:max-content 1fr; gap:.3rem 1rem">
            <dt>Outcome</dt><dd>{{ str_replace('_', ' ', $case->outcome) }}{{ $case->classification ? ' · '.$case->classification.' loss' : '' }}</dd>
            <dt>Cause</dt><dd>{{ $case->cause }}</dd>
            <dt>Evidence</dt><dd>{{ $case->evidence_reference }} @if ($case->assessment_note)<span class="muted">· {{ $case->assessment_note }}</span>@endif</dd>
            <dt>Happened / found</dt><dd>{{ $case->event_on->format('j M Y') }} / {{ $case->discovered_on->format('j M Y') }}@if ($case->posted_on) · posted {{ $case->posted_on->format('j M Y') }}@endif @if ($case->prior_period)<strong>· event in a closed period, recorded in the open period: flagged for Finance and Board review</strong>@endif</dd>
            @if ($case->handover)<dt>Handover</dt><dd><a href="{{ route('sourcing.handovers.show', $case->handover) }}">#{{ $case->handover->id }}</a> · {{ $case->handover->project->client->name }} / {{ $case->handover->project->name }}@if ($case->handoverReturn) · return #{{ $case->handoverReturn->id }}@endif</dd>@endif
            <dt>Recoverable value</dt><dd>{{ $case->recoverable_value !== null ? Money::format($case->recoverable_value) : 'Not yet assessed' }} @if ($case->valuation_basis)<span class="muted">· {{ $case->valuation_basis }}</span>@endif</dd>
            <dt>Proposed</dt><dd>{{ $case->preparer->name }} on {{ $case->created_at->format('j M Y') }}</dd>
            <dt>Finance review</dt><dd>{{ $case->reviewer ? $case->reviewer->name.' on '.$case->reviewed_at->format('j M Y').' · '.$case->review_note : 'Not reviewed' }}</dd>
            <dt>Decision</dt><dd>{{ $case->approver ? ucfirst($case->status).' by '.$case->approver->name.' on '.$case->approved_at->format('j M Y').($case->decision_note ? ' · '.$case->decision_note : '') : ($case->decision_note ?: 'Not decided') }}</dd>
            @if ($case->status === 'approved')
                <dt>Treatment</dt><dd>{{ str_replace('_', ' ', (string) $case->treatment) }} — carrying {{ Money::format($case->carrying_value) }}, recoverable {{ Money::format((int) $case->recoverable_value) }}, loss {{ Money::format($case->loss_value) }}@if ($case->cogs_reversed) , cost of goods sold reversed {{ Money::format($case->cogs_reversed) }}@endif</dd>
            @endif
        </dl>
    </div></section>

    @if ($case->journalEntry)
    <section class="panel">
        <div class="panel-head"><h2>Source journal entry {{ $case->journalEntry->reference() }}</h2></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
            @foreach ($case->journalEntry->lines as $l)<tr><td>{{ $l->account }}</td><td class="num">{{ $l->debit ? Money::format($l->debit) : '' }}</td><td class="num">{{ $l->credit ? Money::format($l->credit) : '' }}</td></tr>@endforeach
        </tbody></table></div>
        <div class="panel-body muted">{{ $case->journalEntry->memo }} · dated {{ $case->journalEntry->entry_date->format('j M Y') }}</div>
    </section>
    @elseif ($case->status === 'approved')
    <section class="panel"><div class="panel-body muted">No journal entry: {{ $case->treatment === 'custody_incident' ? 'this is client-owned stock, so Sellbees records the incident only.' : 'the loss stays in cost of goods sold as a normal loss.' }}</div></section>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Inventory value movements</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>Dated</th><th>Type</th><th>Component</th><th class="num">Quantity</th><th class="num">Value</th><th>Journal</th></tr></thead><tbody>
            @forelse ($movements as $m)<tr><td>{{ $m->effective_on->format('j M Y') }}</td><td>{{ $m->type }}</td><td>{{ $m->component }}</td><td class="num">{{ $m->quantity_delta }}</td><td class="num">{{ Money::format($m->value_delta) }}</td><td>{{ $m->journal_entry_id ? '#'.$m->journal_entry_id : '—' }}</td></tr>
            @empty<tr><td colspan="6" class="muted">None: either nothing is posted until the case is approved, or the units were not in the lot's cost layers.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</x-layout>
