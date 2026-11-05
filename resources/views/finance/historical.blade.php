@use('App\Support\Money')
@php($pct = fn ($n) => $n)
<x-layout title="Historical reconciliation">
    <x-page-header title="Historical reconciliation">
        <x-slot:meta>Preparation only. Nothing on this page posts to the ledger, edits a payment or a posted journal, or creates a balancing entry. Every status below is read from the real records, and each needs evidence or a separate person's approval before it moves.</x-slot:meta>
        <x-slot:actions>@can('account.manage')<form method="POST" action="{{ route('finance.historical.exceptions.register') }}" class="inline-form">@csrf<button class="btn">Register open items</button></form>@endcan</x-slot:actions>
    </x-page-header>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Received but unallocated to an account</div><div class="value">{{ $summary['received_unallocated'] }}</div></div>
        <div class="metric"><div class="label">Financial account verified</div><div class="value">{{ $summary['account_verified'] }}</div></div>
        <div class="metric"><div class="label">Classification approved</div><div class="value">{{ $summary['classification_approved'] }}</div></div>
        <div class="metric"><div class="label">Posted to ledger</div><div class="value">{{ $summary['posted_to_ledger'] }}</div></div>
        <div class="metric"><div class="label">Awaiting supplier-cost reconciliation</div><div class="value">{{ $summary['awaiting_supplier_cost'] }}</div></div>
        <div class="metric"><div class="label">Lots with ownership unresolved</div><div class="value">{{ $summary['ownership_unresolved'] }}</div></div>
        <div class="metric"><div class="label">Historical cash exception</div><div class="value">{{ $summary['historical_cash_exception'] ? 'Open' : 'None' }}</div></div>
    </div>

    <section class="panel"><div class="panel-head"><h2>1. Financial accounts to prepare</h2><span class="sub">No account number, opening balance or historical entry is assumed.</span></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Account</th><th>State</th><th>What is needed</th><th>Actions</th></tr></thead><tbody>
        @foreach ($requirements as $r)
            @php($a = $r['account'])
            <tr><td class="primary-cell">{{ $r['label'] }}<span class="secondary">{{ $accountTypes[$r['type']] }}</span></td>
                <td><span class="badge {{ $r['state'] === 'active' ? 'badge-paid' : 'badge-partial' }}">{{ str_replace('_', ' ', $r['state']) }}</span></td>
                <td>{{ $r['note'] }}@if ($a?->identity_reference)<span class="secondary">Identity: {{ $a->identity_reference }} · opening {{ $a->opening_date?->format('j M Y') }}</span>@endif</td>
                <td>
                    @if ($r['state'] === 'not_prepared')
                        @can('account.manage')<form method="POST" action="{{ route('finance.historical.accounts.prepare') }}" class="inline-form">@csrf<input type="hidden" name="type" value="{{ $r['type'] }}"><input type="hidden" name="name" value="{{ $r['name'] }}"><button class="btn btn-sm">Prepare (inactive)</button></form>@endcan
                    @elseif ($r['state'] === 'pending_confirmation')
                        @can('account.manage')<form method="POST" action="{{ route('finance.historical.accounts.confirm', $a) }}" class="inline-form">@csrf<input name="identity_reference" size="22" placeholder="real account / wallet reference" required><input type="date" name="opening_date" max="{{ today()->toDateString() }}" required><button class="btn btn-sm">Confirm identity</button></form>@endcan
                    @elseif ($r['state'] === 'awaiting_statement')
                        <span class="muted">Record and verify a statement in <a href="{{ route('finance.statements') }}">Account Statements</a>, then:</span>
                        @can('reconcile.review')<form method="POST" action="{{ route('finance.historical.accounts.activate', $a) }}" class="inline-form">@csrf<button class="btn btn-sm btn-primary">Activate</button></form>@endcan
                    @endif
                </td></tr>
        @endforeach
        </tbody></table></div></section>

    <section class="panel"><div class="panel-head"><h2>2. Customer receipts</h2><span class="sub">Owner-confirmed received is not the same as verified in an account, approved, or posted.</span></div>
        @can('account.manage')
        <form method="POST" action="{{ route('finance.historical.receipts.propose') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label for="oc">Owner confirmed received on</label><input type="date" id="oc" name="owner_confirmed_on" max="{{ today()->toDateString() }}" value="{{ old('owner_confirmed_on') }}" required>@error('owner_confirmed_on') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="or">How the owner confirmed (who, how)</label><input id="or" name="owner_reference" value="{{ old('owner_reference') }}" required>@error('owner_reference') <span class="error">{{ $message }}</span> @enderror</div>
            </div>
            <p class="muted">Tick the receipts to propose and choose a classification; the suggestion comes from the invoice text and is only a starting point.</p>
            <div class="table-wrap"><table class="table table-min-760"><thead><tr><th></th><th>Receipt</th><th class="num">Amount</th><th>Suggestion</th><th>Propose as</th></tr></thead><tbody>
            @foreach ($receipts->where('classification', 'none') as $i => $r)
                @php($p = $r['payment'])
                <tr><td><input type="checkbox" name="rows[{{ $i }}][selected]" value="1" @checked($r['suggested']) aria-label="Propose receipt {{ $p->id }}"><input type="hidden" name="rows[{{ $i }}][payment_id]" value="{{ $p->id }}"></td>
                    <td>#{{ $p->id }} · {{ $p->paid_on->format('j M Y') }}</td><td class="num">{{ Money::format($p->amount) }}</td><td class="muted">{{ $r['suggestion_basis'] }}</td>
                    <td><select name="rows[{{ $i }}][nature]"><option value="">—</option>@foreach ($natures as $k => $v)<option value="{{ $k }}" @selected($r['suggested'] === $k)>{{ $v }}</option>@endforeach</select></td></tr>
            @endforeach
            </tbody></table></div>
            <button class="btn btn-primary" style="margin-top:.7rem">Record proposals</button>
        </form>
        @endcan
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Receipt</th><th class="num">Amount</th><th>Method / invoice</th><th>Owner confirmed</th><th>Account</th><th>Classification</th><th>Posted</th><th>Open points</th><th>Actions</th></tr></thead><tbody>
            @forelse ($receipts as $r)
                @php($p = $r['payment'])
                @php($rv = $r['review'])
                <tr><td>#{{ $p->id }}<span class="secondary">{{ $p->paid_on->format('j M Y') }} · {{ $p->project->name }}</span></td>
                    <td class="num">{{ Money::format($p->amount) }}</td>
                    <td>{{ str_replace('_', ' ', $p->method instanceof \BackedEnum ? $p->method->value : $p->method) }}<span class="secondary">{{ $p->invoice?->number ?? 'no invoice' }}@if ($p->reference) · {{ $p->reference }}@endif</span></td>
                    <td>@if ($rv)Yes — {{ $rv->owner_confirmed_on->format('j M Y') }}<span class="secondary">{{ $rv->owner_confirmation_reference }}</span>@else<span class="muted">not recorded</span>@endif</td>
                    <td>{{ $r['account'] ?? 'Not assigned' }}<span class="secondary">{{ $r['account_verified'] ? 'verified' : 'not verified' }}</span></td>
                    <td>{{ $r['nature'] ? ucfirst(str_replace('_', ' ', $r['nature'])) : '—' }}<span class="secondary">{{ $r['classification'] }} · revenue recognition not verified</span></td>
                    <td>{{ $r['posted'] === 'no' ? 'Not posted' : 'Posted '.$r['posted'] }}</td>
                    <td>@if ($r['awaiting_supplier_cost'])<span class="badge badge-partial">Awaiting supplier-cost reconciliation</span>@endif @if ($r['received_unallocated'])<span class="badge badge-archived">Received, unallocated</span>@endif</td>
                    <td>
                        @if ($rv && $rv->status === 'proposed')
                            @can('reconcile.review')<form method="POST" action="{{ route('finance.historical.receipts.review', $rv) }}" class="inline-form">@csrf<button class="btn btn-sm">Review</button></form>@endcan
                        @elseif ($rv && $rv->status === 'reviewed')
                            @can('receipt.classification.approve')<form method="POST" action="{{ route('finance.historical.receipts.approve', $rv) }}" class="inline-form">@csrf<button class="btn btn-sm btn-primary">Approve</button></form>@endcan
                        @elseif ($rv && $rv->status === 'approved')
                            @can('account.manage')<form method="POST" action="{{ route('finance.historical.receipts.apply', $rv) }}" class="inline-form">@csrf<button class="btn btn-sm btn-primary">Apply (no posting)</button></form>@endcan
                        @endif
                        @if ($rv && in_array($rv->status, ['proposed', 'reviewed']))
                            @can('reconcile.review')<form method="POST" action="{{ route('finance.historical.receipts.reject', $rv) }}" class="inline-form"><input type="hidden" name="_token" value="{{ csrf_token() }}"><input name="note" size="12" placeholder="reason" required><button class="btn btn-sm">Reject</button></form>@endcan
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="9" class="muted">No customer receipts.</td></tr>
            @endforelse
            </tbody></table></div></section>

    <section class="panel"><div class="panel-head"><h2>3. Cash attribution</h2><span class="sub">The destination account stays unresolved until a bKash, bank or cash record supports it.</span></div>
        <div class="panel-body">
            <p>Cash in the ledger with no financial account: <strong>{{ Money::format($cash['unassigned']) }}</strong>
                · owner-confirmed receipts not yet in the ledger: {{ Money::format($cash['receipts_not_in_ledger']) }}
                · <span class="muted">unverified projection only, not cash: {{ Money::format($cash['projection']) }}</span></p>
            <p class="{{ $cash['allocation_possible'] ? '' : 'muted' }}">{{ $cash['allocation_note'] }} <a href="{{ route('finance.cash-allocation') }}">Opening cash allocation</a></p>
        </div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Journal</th><th>Date</th><th>Source</th><th class="num">Debit</th><th class="num">Credit</th><th>Attribution</th><th>Candidate account</th><th>Evidence missing</th></tr></thead><tbody>
            @foreach ($cashLines as $l)
                <tr><td>#{{ $l['journal'] }}</td><td>{{ \Illuminate\Support\Carbon::parse($l['date'])->format('j M Y') }}</td><td>{{ $l['source'] }}<span class="secondary">{{ $l['memo'] }}</span></td>
                    <td class="num">{{ $l['debit'] ? Money::format($l['debit']) : '' }}</td><td class="num">{{ $l['credit'] ? Money::format($l['credit']) : '' }}</td>
                    <td>{{ $l['attribution'] }}</td><td class="muted">{{ $l['candidate'] }}</td><td>{{ $l['missing'] ? implode(', ', $l['missing']) : '—' }}</td></tr>
            @endforeach
            </tbody></table></div></section>

    <section class="panel"><div class="panel-head"><h2>4. Lot ownership unresolved</h2><span class="sub">Handover, valuation, supplier returns and client settlement stay blocked for these lots.</span></div>
        @forelse ($lots as $row)
            @php($lot = $row['lot'])
            <div class="panel-body" style="border-top:1px solid var(--border)">
                <h3><a href="{{ route('sourcing.lots.show', $lot) }}">{{ $lot->number }}</a> {{ $lot->title }}</h3>
                <ul>@foreach ($row['evidence'] as $e)<li>{{ $e['met'] ? '✓' : '✗' }} {{ $e['label'] }} <span class="muted">— {{ $e['detail'] }}</span></li>@endforeach</ul>
                <p class="muted">Upload supporting documents under the lot's Receiving &amp; documents tab. Ownership is irreversible, so it needs evidence and a second person's approval.</p>
                @php($req = $row['request'])
                @if ($req && $req->status === 'pending')
                    <p>Request pending: <strong>{{ $req->proposed_ownership === 'company' ? 'Sellbees-owned' : 'Client-owned' }}</strong> — {{ $req->evidence_note }}</p>
                    @can('ownership.approve')<form method="POST" action="{{ route('finance.historical.ownership.decide', $req) }}" class="inline-form">@csrf<input name="note" size="22" placeholder="note (needed to reject)"><button name="decision" value="approve" class="btn btn-sm btn-primary">Approve</button><button name="decision" value="reject" class="btn btn-sm">Reject</button></form>@endcan
                @elseif ($lot->ownership === null)
                    @can('sourcing.manage')
                    <form method="POST" action="{{ route('finance.historical.ownership.propose', $lot) }}" class="form-grid">@csrf
                        <div class="field"><label>Proposed owner</label><select name="ownership"><option value="client">Client-owned</option><option value="company">Sellbees-owned</option></select></div>
                        <div class="field"><label>Client project (if client-owned)</label><select name="project_id"><option value="">—</option>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }} — {{ $p->client->name }}</option>@endforeach</select></div>
                        <div class="field"><label>Evidence (which documents, who signed, what they say)</label><input name="evidence_note" required>@error('evidence_note') <span class="error">{{ $message }}</span> @enderror</div>
                        <button class="btn">Request approval</button>
                    </form>
                    @endcan
                @endif
            </div>
        @empty
            <div class="panel-body muted">No lot has an unknown owner.</div>
        @endforelse
    </section>

    <section class="panel"><div class="panel-head"><h2>5. Voided payments and differences</h2><span class="sub">Pairing is a lead for a person to check, not a conclusion.</span></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Voided payment</th><th class="num">Amount</th><th>Voided receipt dated</th><th>Probable replacement</th><th class="num">Difference</th></tr></thead><tbody>
        @forelse ($voids as $v)
            <tr><td>#{{ $v['void_payment'] }}</td><td class="num">{{ Money::format($v['amount']) }}</td><td>{{ $v['paid_on'] }}</td>
                <td>{{ $v['replacement'] ? '#'.$v['replacement']->id.' ('.Money::format($v['replacement']->amount).')' : 'none found' }}</td>
                <td class="num">{{ $v['difference'] === null ? '—' : ($v['difference'] === 0 ? 'matches' : Money::format($v['difference'])) }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted">No voided payments.</td></tr>
        @endforelse
        </tbody></table></div></section>

    <section class="panel"><div class="panel-head"><h2>6. Open items</h2><span class="sub">An item stays open until someone other than its opener resolves it with a written note.</span></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Item</th><th>Evidence required</th><th>Status</th><th></th></tr></thead><tbody>
        @forelse ($exceptions as $x)
            <tr><td class="primary-cell">{{ $x->subject }}<span class="secondary">{{ $x->detail }}</span></td><td>{{ $x->evidence_required }}</td>
                <td><span class="badge {{ $x->status === 'open' ? 'badge-unpaid' : 'badge-paid' }}">{{ ucfirst($x->status) }}</span>@if ($x->resolution_note)<span class="secondary">{{ $x->resolution_note }}</span>@endif</td>
                <td>@if ($x->status === 'open')@can('reconcile.review')<form method="POST" action="{{ route('finance.historical.exceptions.resolve', $x) }}" class="inline-form">@csrf<input name="note" size="26" placeholder="evidence that resolves it" required><button class="btn btn-sm">Resolve</button></form>@endcan @endif</td></tr>
        @empty
            <tr><td colspan="4" class="muted">No open items registered yet. Use "Register open items" above.</td></tr>
        @endforelse
        </tbody></table></div></section>
</x-layout>
