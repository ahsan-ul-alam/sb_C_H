@use('App\Support\Money')
<x-layout title="Finance Automation">
    <x-page-header title="Finance Automation">
        <x-slot:meta>Routine, already-authorised work runs by itself under the Board's standing policies. People are asked only for exceptions. Nothing here is active until the Board approves it.</x-slot:meta>
        @can('period.close')
        <x-slot:actions>
            <form method="POST" action="{{ route('finance.automation.run') }}">@csrf<button class="btn btn-primary">Run now</button></form>
        </x-slot:actions>

        @endcan
    </x-page-header>

    <section class="panel">
        <div class="panel-head"><h2>Standing Board policies</h2><span class="sub">Approved in the Board resolution register (type Other). The Board sets the limits; the software invents none.</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Policy</th><th>State</th><th class="num">Limit</th><th>Authorises</th><th></th></tr></thead>
            <tbody>
            @foreach ($policies as $p)
                <tr><td class="primary-cell">{{ $p['title'] }}</td>
                    <td>@if ($p['active'])<x-status value="active" />@elseif ($p['latest']?->status === 'pending')<span class="badge badge-partial">Awaiting Board</span>@elseif ($p['latest'] && str_starts_with((string) $p['latest']->notes, 'REVOKED'))<x-status value="cancelled" /> Revoked @else<span class="muted">Not set</span>@endif</td>
                    <td class="num">{{ $p['active']?->amount ? Money::format($p['active']->amount) : '—' }}@if ($p['active'] && $p['key'] === 'expenses')<span class="secondary">{{ $p['active']->notes }}</span>@endif</td>
                    <td>{{ $p['purpose'] }}</td>
                    <td class="num">
                        @if ($p['active'])
                            @can('resolution.decide')<form method="POST" action="{{ route('finance.automation.revoke', $p['key']) }}" data-confirm="Revoke this standing policy? Automatic processing under it stops."> @csrf<button class="btn btn-sm btn-danger">Revoke</button></form>@endcan
                        @elseif ($p['latest']?->status !== 'pending')
                            @can('resolution.create')
                            <form method="POST" action="{{ route('finance.resolutions.store') }}" class="inline-form">@csrf
                                <input type="hidden" name="type" value="other"><input type="hidden" name="title" value="{{ $p['title'] }}">
                                @if (! str_starts_with($p['amount_meaning'], 'Not used'))<input name="amount" size="9" placeholder="limit" inputmode="decimal" required>@endif
                                @if ($p['key'] === 'expenses')<input name="notes" size="16" placeholder="allowed categories, comma separated" required>@endif
                                <button class="btn btn-sm">Propose to Board</button></form>
                            @endcan
                        @endif
                    </td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Month-end pipeline</h2><span class="sub">Close → verify → allocate (20% Salary, 5% Office &amp; Growth, 25% Emergency, 50% Partners 40/30/30) → post</span></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Month</th><th>State</th><th>Blocked by</th></tr></thead>
            <tbody>@foreach ($months as $m)<tr><td>{{ $m['month'] }}</td><td>{{ $m['state'] }}</td><td class="{{ $m['blocker'] ? 'text-danger' : 'muted' }}">{{ $m['blocker'] ?? '—' }}</td></tr>@endforeach</tbody>
        </table></div>
    </section>

    @if (count($holdback))
    <section class="panel">
        <div class="panel-head"><h2>Profit held back by sourcing obligations</h2><span class="sub">Profit is not distributed while these are open. Each line clears itself when its cause is resolved.</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Lot</th><th>Supplier / item</th><th class="num">Amount</th><th>Why</th><th>Next action</th></tr></thead>
            <tbody>@foreach ($holdback as $h)<tr><td>@if ($h['lot_id'])<a href="{{ route('sourcing.lots.show', [$h['lot_id'], 'tab' => 'payments']) }}">{{ $h['lot'] }}</a>@else {{ $h['lot'] }} @endif</td><td>{{ $h['who'] }}</td><td class="num">{{ Money::format($h['amount']) }}</td><td>{{ $h['reason'] }}</td><td>{{ $h['action'] }}</td></tr>@endforeach</tbody>
        </table></div>
    </section>
    @endif

    @if ($stranded->isNotEmpty() || $heldCoverage > 0)
    <section class="panel">
        <div class="panel-head"><h2>Emergency coverage waiting for a decision</h2></div>
        <div class="panel-body muted">{{ Money::format($heldCoverage) }} of utilised coverage is held and not yet applied to any partner loss. @if ($stranded->isNotEmpty()) The lots below are in months that closed profitable, so no loss allocation can claim them. The Board either approves the release policy above (automatic) or Finance releases each one on the Investment Overview. @endif</div>
        @if ($stranded->isNotEmpty())<div class="table-wrap"><table class="table"><tbody>@foreach ($stranded as $r)<tr><td>{{ $r->lot->number }}</td><td class="num">{{ Money::format($r->amount) }}</td></tr>@endforeach</tbody></table></div>@endif
    </section>
    @endif

    @if ($pendingExpenses->isNotEmpty())
    <section class="panel">
        <div class="panel-head"><h2>Expenses waiting for the Board</h2><span class="sub">Outside every standing policy (or no policy yet).</span></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Amount</th></tr></thead>
            <tbody>@foreach ($pendingExpenses as $e)<tr><td>{{ $e->spent_on->format('j M Y') }}</td><td>{{ $e->category }}</td><td>{{ $e->description }}</td><td class="num">{{ Money::format($e->amount) }}</td></tr>@endforeach</tbody>
        </table><a class="btn btn-sm" href="{{ route('finance.expenses') }}">Open expenses</a></div>
    </section>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Recent automatic actions and exceptions</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>When</th><th>Event</th><th>Details</th></tr></thead>
            <tbody>@forelse ($recent as $a)<tr><td class="nowrap">{{ $a->created_at->format('j M Y H:i') }}</td><td>{{ str_replace(['automation.', 'expense.', 'order.'], '', $a->action) }}</td><td>{{ $a->details }}</td></tr>@empty<tr><td colspan="3" class="muted">Nothing yet.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
</x-layout>
