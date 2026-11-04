@use('App\Support\Money')
<x-layout title="Supplier return #{{ $return->id }}">
    <x-page-header :title="'Supplier return #'.$return->id" :crumbs="[['Supplier Returns', route('sourcing.supplier-returns')], ['#'.$return->id, null]]">
        <x-slot:meta><x-status :value="$return->status" /> · {{ $return->supplier->name }} · {{ $return->lot->number }} · {{ $return->line->description }} · {{ $return->quantity }} units · {{ ucfirst($return->kind) }}</x-slot:meta>
    </x-page-header>

    <section class="panel"><div class="panel-body">
        <dl style="display:grid; grid-template-columns:max-content 1fr; gap:.3rem 1rem">
            <dt>Physical return</dt><dd>{{ $return->physical_returned_on->format('j M Y') }} · document {{ $return->return_reference }} · evidence {{ $return->evidence_reference }}</dd>
            <dt>Reason</dt><dd>{{ $return->reason }}</dd>
            <dt>Requested by</dt><dd>{{ $return->preparer->name }} on {{ $return->created_at->format('j M Y') }}</dd>
            <dt>Claimed</dt><dd>{{ Money::format($return->claimed_amount) }}@if ($return->expected_method) <span class="muted">· expected: {{ str_replace('_', ' ', $return->expected_method) }}</span>@endif</dd>
            <dt>Supplier's answer</dt><dd>{{ ucfirst($return->acceptance) }}@if ($return->accepted_amount !== null) · accepted {{ Money::format($return->accepted_amount) }}@endif @if ($return->supplier_document)· {{ $return->supplier_document }}@endif @if ($return->acceptor)<span class="muted">· recorded by {{ $return->acceptor->name }}</span>@endif</dd>
            <dt>Finance verification</dt><dd>{{ $return->verifier ? $return->verifier->name.' on '.$return->verified_at->format('j M Y').' · '.$return->verify_note : 'Not verified' }}</dd>
            <dt>Decision</dt><dd>{{ $return->approver ? ucfirst($return->status).' by '.$return->approver->name.' on '.$return->approved_at->format('j M Y').($return->decision_note ? ' · '.$return->decision_note : '') : ($return->decision_note ?: 'Not decided') }}</dd>
            @if ($return->status === 'approved' && $return->kind === 'accepted')
                <dt>Cost removed</dt><dd>{{ Money::format($return->carrying_value) }} (purchase {{ Money::format($return->purchase_value) }} + landed {{ Money::format($return->landed_value) }}) · posted {{ $return->posted_on->format('j M Y') }}@if ($return->prior_period) <strong>· physical return in a closed period, recorded in the open period: flagged for Finance review</strong>@endif</dd>
                <dt>Not recovered</dt><dd>{{ Money::format($return->difference_value) }}@if ($return->difference_reason) · {{ $return->difference_reason }}@endif</dd>
            @endif
        </dl>
    </div></section>

    @if ($return->status === 'approved' && $return->kind === 'accepted')
    <section class="panel">
        <div class="panel-head"><h2>Recovery, by category</h2></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Category</th><th class="num">Recognised</th><th class="num">Settled</th><th class="num">Still open</th></tr></thead><tbody>
            <tr><td>Reduction of what we owe the supplier</td><td class="num">{{ Money::format($b['payable_reduction']) }}</td><td class="num">{{ Money::format($b['payable_reduction']) }}</td><td class="num">{{ Money::format(0) }}</td></tr>
            <tr><td>Supplier credit note (applied to a payable)</td><td class="num">{{ Money::format($b['credit_total']) }}</td><td class="num">{{ Money::format($b['credit_applied']) }}</td><td class="num">{{ Money::format($b['credit_open']) }}</td></tr>
            <tr><td>Supplier receivable (cash received)</td><td class="num">{{ Money::format($b['receivable_total']) }}</td><td class="num">{{ Money::format($b['cash_received']) }}</td><td class="num">{{ Money::format($b['receivable_open']) }}</td></tr>
            <tr><td>Claimed but not accepted by the supplier (not booked)</td><td class="num">—</td><td class="num">—</td><td class="num">{{ Money::format($b['disputed']) }}</td></tr>
            <tr><td>Accepted but not allocated (not booked)</td><td class="num">—</td><td class="num">—</td><td class="num">{{ Money::format($b['unallocated']) }}</td></tr>
        </tbody></table></div>
    </section>
    @endif

    @if ($return->journalEntry)
    <section class="panel">
        <div class="panel-head"><h2>Source journal entry {{ $return->journalEntry->reference() }}</h2></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
            @foreach ($return->journalEntry->lines as $l)<tr><td>{{ $l->account }}</td><td class="num">{{ $l->debit ? Money::format($l->debit) : '' }}</td><td class="num">{{ $l->credit ? Money::format($l->credit) : '' }}</td></tr>@endforeach
        </tbody></table></div>
        <div class="panel-body muted">{{ $return->journalEntry->memo }} · dated {{ $return->journalEntry->entry_date->format('j M Y') }}</div>
    </section>
    @elseif ($return->status === 'approved')
    <section class="panel"><div class="panel-body muted">No journal entry: these goods never entered inventory, so there is no inventory to reverse. Any refund or credit for them is handled on the supplier payment.</div></section>
    @endif

    @if ($return->settlements->isNotEmpty())
    <section class="panel">
        <div class="panel-head"><h2>Settlements</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>#</th><th>Event</th><th class="num">Amount</th><th>Reference</th><th>Recorded / approved</th><th>Status</th><th>Journal</th></tr></thead><tbody>
            @foreach ($return->settlements as $s)<tr><td>#{{ $s->id }}</td><td>{{ $s->method === 'cash_refund' ? 'Cash refund' : 'Credit applied' }}</td><td class="num">{{ Money::format($s->amount) }}</td><td>{{ $s->reference }}</td><td>{{ $s->preparer->name }} / {{ $s->approver?->name ?? '—' }}</td><td><x-status :value="$s->status" /></td><td>{{ $s->journalEntry?->reference() ?? '—' }}</td></tr>@endforeach
        </tbody></table></div>
    </section>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Inventory value movements</h2></div>
        <div class="table-wrap"><table class="table table-min-640"><thead><tr><th>Dated</th><th>Type</th><th>Component</th><th class="num">Quantity</th><th class="num">Value</th><th>Journal</th></tr></thead><tbody>
            @forelse ($movements as $m)<tr><td>{{ $m->effective_on->format('j M Y') }}</td><td>{{ $m->type }}</td><td>{{ $m->component }}</td><td class="num">{{ $m->quantity_delta }}</td><td class="num">{{ Money::format($m->value_delta) }}</td><td>{{ $m->journal_entry_id ? '#'.$m->journal_entry_id : '—' }}</td></tr>
            @empty<tr><td colspan="6" class="muted">None: nothing is posted until the return is approved, and goods never accepted into stock have no value to move.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</x-layout>
