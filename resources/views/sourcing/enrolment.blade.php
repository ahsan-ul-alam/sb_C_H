@use('App\Support\Money')
<x-layout title="Inventory enrolment">
    <x-page-header title="Inventory enrolment">
        <x-slot:meta>A Sellbees-owned lot is valued only after Finance requests it and a different Board member approves. Enrolment posts no journal entry and moves no stock: value follows the goods as they are accepted and the supplier is settled.</x-slot:meta>
    </x-page-header>

    @if (! $engineFrom)
        <div class="flash flash-warning" role="status"><x-icon name="alert" /> <div>The inventory valuation engine is switched off (<code>FINANCE_INVENTORY_ENGINE_FROM</code> is not set), so no lot can be enrolled yet.</div></div>
    @else
        <p class="muted">The engine values lots from {{ \Illuminate\Support\Carbon::parse($engineFrom)->format('F Y') }} onward.</p>
    @endif

    <section class="panel"><div class="panel-head"><h2>Lots</h2></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Lot</th><th>Products &amp; accepted quantity</th><th>Project</th><th>Ownership</th><th class="num">Supplier paid / settled</th><th>Enrolment</th><th>Readiness / action</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            @php($lot = $r['lot'])
            @php($e = $r['enrolment'])
            <tr>
                <td class="primary-cell"><a href="{{ route('sourcing.lots.show', $lot) }}">{{ $lot->number }}</a>{{ $lot->title }}<span class="secondary">{{ $lot->statusLabel() }} · {{ $r['documents'] }} document(s)</span></td>
                <td>@foreach ($r['lines'] as $l){{ $l['description'] }}: {{ $l['accepted'] ?? 'not received' }} accepted of {{ $l['ordered'] }} ordered<br>@endforeach</td>
                <td>{{ $r['projects'] ? implode(', ', $r['projects']) : '—' }}</td>
                <td>@if ($lot->ownership === 'company') Sellbees-owned @elseif ($lot->ownership === 'client') Client-owned @else <span class="badge badge-partial">Not stated</span> @endif</td>
                <td class="num">{{ Money::format($r['paid']) }} of {{ Money::format($r['purchase']) }}<span class="secondary">settled {{ Money::format($r['settled']) }}</span></td>
                <td>@if ($e)<span class="badge {{ $e->status === 'approved' ? 'badge-paid' : ($e->status === 'rejected' ? 'badge-unpaid' : 'badge-partial') }}">{{ ucfirst($e->status) }}</span><span class="secondary">from {{ $e->effective_on->format('j M Y') }}</span>@else — @endif</td>
                <td>
                    @if ($e && $e->status === 'pending')
                        <span class="muted">Evidence: {{ $e->classification_source }}</span>
                        @can('sourcing.advance.approve')
                        <form method="POST" action="{{ route('sourcing.enrolment.decide', $e) }}" class="inline-form">@csrf<input name="note" size="18" placeholder="note (needed to reject)"><button name="decision" value="approve" class="btn btn-sm btn-primary">Approve</button><button name="decision" value="reject" class="btn btn-sm">Reject</button></form>
                        @endcan
                    @elseif ($e && $e->status === 'approved')
                        <span class="badge badge-paid">Valued as Sellbees-owned stock</span>
                    @else
                        @foreach ($r['blockers'] as $b)<div class="error">{{ $b }}</div>@endforeach
                        @foreach ($r['warnings'] as $w)<div class="muted">{{ $w }}</div>@endforeach
                        @if ($e && $e->status === 'rejected')<div class="muted">Rejected: {{ $e->decision_note }}</div>@endif
                        @if ($r['ready'])
                            @can('sourcing.manage')
                            <form method="POST" action="{{ route('sourcing.enrolment.store', $lot) }}" class="inline-form">@csrf
                                <input name="classification_source" size="26" placeholder="evidence: signed PO, resolution, delivery note" required>
                                <input type="date" name="effective_on" value="{{ max($engineFrom ?? '', today()->toDateString()) }}" min="{{ $engineFrom }}" required>
                                <button class="btn btn-sm btn-primary">Request enrolment</button></form>
                            @endcan
                        @endif
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">There are no sourcing lots yet.</td></tr>
        @endforelse
        </tbody></table></div></section>

    <section class="panel"><div class="panel-head"><h2>Enrolment history</h2><span class="sub">Every request, decision and re-request, with who and when.</span></div><div class="table-wrap"><table class="table">
        <thead><tr><th>When</th><th>Who</th><th>Event</th><th>Lot</th><th>Detail</th></tr></thead><tbody>
        @forelse ($history as $h)
            <tr><td>{{ $h->created_at->format('j M Y H:i') }}</td><td>{{ $h->user?->name ?? 'system' }}</td><td>{{ str_replace('inventory.engine_', '', $h->action) }}</td><td>{{ $h->subject }}</td><td>{{ $h->details }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted">No enrolment activity yet.</td></tr>
        @endforelse
        </tbody></table></div></section>
</x-layout>
