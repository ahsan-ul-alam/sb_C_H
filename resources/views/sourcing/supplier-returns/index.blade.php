@use('App\Support\Money')
<x-layout title="Supplier Returns">
    <x-page-header title="Supplier Returns, Credit Notes & Refunds">
        <x-slot:meta>The goods leaving, the supplier agreeing to give something back, the claim being recognised and cash arriving are four separate events. Nothing is posted until Finance has recorded the supplier's answer and verified the allocation and a different Board member approves. Payable reduction, credit note, receivable and cash received are kept apart: a proposed or disputed recovery is never shown as collected.</x-slot:meta>
    </x-page-header>

    @can('supplier.return.propose')
    <section class="panel">
        <div class="panel-head"><h2>Request a supplier return</h2></div>
        @if ($lines->isEmpty())
            <div class="panel-body muted">No line can be returned yet. It needs Sellbees-owned, valued stock that is still sellable (not reserved, handed over, written off or already in a return), or goods rejected at arrival.</div>
        @else
        <form method="POST" action="{{ route('sourcing.supplier-returns.store') }}" class="panel-body">@csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="form-grid">
                <div class="field full"><label for="sourcing_lot_line_id">Lot line <span class="req" aria-hidden="true">*</span></label>
                    <select id="sourcing_lot_line_id" name="sourcing_lot_line_id" required><option value="">Choose the line…</option>
                        @foreach ($lines as $r)<option value="{{ $r['line']->id }}">{{ $r['line']->lot->number }} · {{ $r['line']->description }} · {{ $r['line']->supplier?->name }} — sellable {{ $r['sellable'] }}, rejected at arrival {{ $r['rejected'] }}</option>@endforeach</select></div>
                <div class="field"><label for="kind">Goods <span class="req" aria-hidden="true">*</span></label><select id="kind" name="kind"><option value="accepted">Accepted into stock (inventory leaves)</option><option value="unaccepted">Rejected at arrival (a claim only, never inventory)</option></select></div>
                <div class="field"><label for="quantity">Quantity <span class="req" aria-hidden="true">*</span></label><input id="quantity" name="quantity" inputmode="decimal" required></div>
                <div class="field"><label for="physical_returned_on">Date the goods left <span class="req" aria-hidden="true">*</span></label><input type="date" id="physical_returned_on" name="physical_returned_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required></div>
                <div class="field"><label for="return_reference">Return document no. <span class="req" aria-hidden="true">*</span></label><input id="return_reference" name="return_reference" required minlength="3" placeholder="Supplier's or carrier's return note"></div>
                <div class="field"><label for="evidence_reference">Physical return evidence <span class="req" aria-hidden="true">*</span></label><input id="evidence_reference" name="evidence_reference" required minlength="5" placeholder="Signed carrier receipt, photos"></div>
                <div class="field"><label for="claimed_amount">Amount claimed from the supplier</label><input id="claimed_amount" name="claimed_amount" inputmode="decimal"></div>
                <div class="field"><label for="expected_method">Expected recovery <span class="opt">(an expectation only)</span></label><select id="expected_method" name="expected_method"><option value="">—</option><option value="payable">Reduce what we owe</option><option value="credit_note">Credit note</option><option value="refund">Cash refund</option><option value="mixed">Mixed</option></select></div>
                <div class="field full"><label for="reason">Reason <span class="req" aria-hidden="true">*</span></label><input id="reason" name="reason" required></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Submit return</button>
        </form>
        @endif
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Open returns</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>#</th><th>Supplier / line</th><th class="num">Qty</th><th class="num">Claimed</th><th>Supplier's answer</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($open as $r)
                <tr><td><a href="{{ route('sourcing.supplier-returns.show', $r) }}">#{{ $r->id }}</a></td><td>{{ $r->supplier->name }} · {{ $r->line->description }} · {{ ucfirst($r->kind) }}<br><span class="muted">{{ $r->return_reference }} · by {{ $r->preparer->name }}</span></td>
                    <td class="num">{{ $r->quantity }}</td><td class="num">{{ Money::format($r->claimed_amount) }}</td>
                    <td>{{ ucfirst($r->acceptance) }}@if ($r->accepted_amount !== null) · accepted {{ Money::format($r->accepted_amount) }}@endif @if ($r->supplier_document)<br><span class="muted">{{ $r->supplier_document }}</span>@endif</td><td><x-status :value="$r->status" /></td>
                    <td class="num">
                        @if ($r->status === 'requested')
                            @can('supplier.return.review')
                                <details><summary class="btn btn-sm">Supplier's answer</summary>
                                    <form method="POST" action="{{ route('sourcing.supplier-returns.accept', $r) }}" style="display:grid; gap:.4rem; margin-top:.4rem; min-width:15rem">@csrf
                                        <select name="acceptance" aria-label="Answer"><option value="accepted">Accepted in full</option><option value="partial">Partly accepted</option><option value="disputed">Disputed</option><option value="rejected">Rejected</option></select>
                                        <input name="accepted_amount" placeholder="Amount accepted" inputmode="decimal"><input name="supplier_document" placeholder="Credit note no. / written acceptance"><input name="acceptance_note" placeholder="Note"><button class="btn btn-sm">Record</button></form></details>
                                @if ($r->prepared_by !== auth()->id() && in_array($r->acceptance, ['accepted', 'partial'], true))
                                <details><summary class="btn btn-sm btn-primary">Verify &amp; allocate</summary>
                                    <form method="POST" action="{{ route('sourcing.supplier-returns.verify', $r) }}" style="display:grid; gap:.4rem; margin-top:.4rem; min-width:15rem">@csrf
                                        @if ($r->kind === 'accepted')<input name="payable_reduction" placeholder="Reduces what we owe" inputmode="decimal"><input name="credit_note_amount" placeholder="Credit note (not cash)" inputmode="decimal"><input name="receivable_amount" placeholder="Refund owed (receivable)" inputmode="decimal"><input name="difference_reason" placeholder="Why any cost is not recovered">@endif
                                        <input name="verify_note" placeholder="What Finance checked" required><button class="btn btn-sm btn-primary">Verify</button></form></details>
                                @endif
                            @endcan
                        @else
                            @can('supplier.return.approve') @if ($r->prepared_by !== auth()->id() && $r->verified_by !== auth()->id())
                                <form method="POST" action="{{ route('sourcing.supplier-returns.decide', $r) }}" style="display:inline-flex; gap:.3rem; flex-wrap:wrap">@csrf<input name="reason" placeholder="Reason (needed to reject)" size="16"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
                            @else <span class="muted">You requested or verified this</span> @endif @endcan
                        @endif
                        @if ($r->prepared_by === auth()->id())<form method="POST" action="{{ route('sourcing.supplier-returns.cancel', $r) }}" style="display:inline-flex; gap:.3rem">@csrf<input name="reason" placeholder="Reason" required size="12"><button class="btn btn-sm">Cancel</button></form>@endif
                    </td></tr>
            @empty
                <tr><td colspan="7" class="muted">No open returns.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Recovery position of approved returns</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Return</th><th class="num">Cost removed</th><th class="num">Payable reduced</th><th class="num">Credit note</th><th class="num">Credit still open</th><th class="num">Receivable</th><th class="num">Cash received</th><th class="num">Receivable open</th><th class="num">Not accepted</th><th class="num">Not recovered</th><th></th></tr></thead>
            <tbody>
            @forelse ($approved as $a)
                @php($r = $a['return'])@php($b = $a['b'])
                <tr><td><a href="{{ route('sourcing.supplier-returns.show', $r) }}">#{{ $r->id }}</a> {{ $r->supplier->name }}</td><td class="num">{{ Money::format($r->carrying_value) }}</td><td class="num">{{ Money::format($b['payable_reduction']) }}</td><td class="num">{{ Money::format($b['credit_total']) }}</td><td class="num">{{ Money::format($b['credit_open']) }}</td>
                    <td class="num">{{ Money::format($b['receivable_total']) }}</td><td class="num">{{ Money::format($b['cash_received']) }}</td><td class="num">{{ Money::format($b['receivable_open']) }}</td><td class="num">{{ Money::format($b['disputed']) }}</td><td class="num">{{ Money::format($b['difference']) }}</td>
                    <td class="num">@can('supplier.return.review') @if ($b['credit_available'] > 0 || $b['receivable_available'] > 0)
                        <details><summary class="btn btn-sm">Record settlement</summary>
                            <form method="POST" action="{{ route('sourcing.supplier-returns.settle', $r) }}" style="display:grid; gap:.4rem; margin-top:.4rem; min-width:15rem">@csrf
                                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <select name="method" aria-label="Event">@if ($b['credit_available'] > 0)<option value="credit_applied">Credit applied to a payable</option>@endif @if ($b['receivable_available'] > 0)<option value="cash_refund">Cash refund received</option>@endif</select>
                                <input name="amount" placeholder="Amount" inputmode="decimal" required><input name="reference" placeholder="Payable ref, or bank receipt" required minlength="5"><input type="date" name="settled_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required>
                                <select name="financial_account_id" aria-label="Account received into"><option value="">Account (refunds)</option>@foreach ($accounts as $acct)<option value="{{ $acct->id }}">{{ $acct->name }}</option>@endforeach</select><button class="btn btn-sm">Submit</button></form></details>
                    @endif @endcan</td></tr>
            @empty
                <tr><td colspan="11" class="muted">No approved returns of inventory.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if ($pendingSettlements->isNotEmpty())
        <div class="panel-body"><strong>Settlements awaiting the Board</strong></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>#</th><th>Return</th><th>Event</th><th class="num">Amount</th><th>Reference</th><th>Recorded by</th><th></th></tr></thead>
            <tbody>
            @foreach ($pendingSettlements as $s)
                <tr><td>#{{ $s->id }}</td><td><a href="{{ route('sourcing.supplier-returns.show', $s->supplierReturn) }}">#{{ $s->supplier_return_id }}</a> {{ $s->supplierReturn->supplier->name }}</td><td>{{ $s->method === 'cash_refund' ? 'Cash refund (not yet received in the books)' : 'Credit applied to a payable' }}</td><td class="num">{{ Money::format($s->amount) }}</td><td>{{ $s->reference }}</td><td>{{ $s->preparer->name }}</td>
                    <td class="num">@can('supplier.return.approve') @if ($s->prepared_by !== auth()->id())<form method="POST" action="{{ route('sourcing.supplier-returns.settlements.decide', $s) }}" style="display:inline-flex; gap:.3rem">@csrf<input name="reason" placeholder="Reason (to reject)" size="14"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>@else<span class="muted">You recorded this</span>@endif @endcan</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Return history</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>#</th><th>Supplier / line</th><th>Kind</th><th class="num">Qty</th><th class="num">Cost removed</th><th>Dated</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($history as $r)
                <tr><td><a href="{{ route('sourcing.supplier-returns.show', $r) }}">#{{ $r->id }}</a></td><td>{{ $r->supplier->name }} · {{ $r->line->description }}</td><td>{{ ucfirst($r->kind) }}</td><td class="num">{{ $r->quantity }}</td><td class="num">{{ $r->carrying_value !== null ? Money::format($r->carrying_value) : '—' }}</td><td>{{ $r->posted_on?->format('j M Y') ?? '—' }}{{ $r->prior_period ? ' · prior period' : '' }}</td><td><x-status :value="$r->status" /></td></tr>
            @empty
                <tr><td colspan="7" class="muted">No decided returns yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Reconciliation status</h2><span class="badge badge-{{ $reconciliation['ok'] ? 'paid' : 'partial' }}">{{ $reconciliation['ok'] ? 'Reconciled' : 'Differences' }}</span></div>
        <div class="panel-body">
            <p>Supplier receivable in the ledger {{ Money::format($reconciliation['receivable_gl']) }} · open on the returns {{ Money::format($reconciliation['receivable_open']) }}. Supplier credit from returns in the ledger {{ Money::format($reconciliation['credit_gl']) }} · open on the returns {{ Money::format($reconciliation['credit_open']) }}. Cost of returned goods not recovered: ledger {{ Money::format($reconciliation['difference_gl']) }} · returns {{ Money::format($reconciliation['difference_cases']) }}.</p>
            @foreach ($reconciliation['problems'] as $p)<p class="text-danger">{{ $p }}</p>@endforeach
        </div>
    </section>
</x-layout>
