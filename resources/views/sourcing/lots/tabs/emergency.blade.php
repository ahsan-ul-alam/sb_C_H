@use('App\Support\Money')
<section class="panel">
    <div class="panel-head"><h2>Emergency cost adjustment</h2></div>
    <div class="table-wrap"><table class="table">
        <tr><td>Original available lot margin (before unexpected costs)</td><td class="num">{{ Money::format($f['original_margin']) }}</td></tr>
        <tr><td>Unexpected costs recorded</td><td class="num">{{ Money::format($f['unexpected']) }}</td></tr>
        <tr><td>Client recovery agreed</td><td class="num">{{ Money::format($f['recoveries']) }}</td></tr>
        <tr><td>Unexpected cost absorbed by the margin</td><td class="num">{{ Money::format($f['margin_absorbed']) }}</td></tr>
        <tr><td><strong>Lot accounting profit / (loss)</strong></td><td class="num strong {{ $f['profit'] < 0 ? 'text-danger' : '' }}">{{ Money::format($f['profit']) }}</td></tr>
        <tr><td>Remaining uncovered loss</td><td class="num">{{ Money::format($f['uncovered_loss']) }}</td></tr>
        <tr><td>Emergency Fund: pending / approved / utilised</td><td class="num">{{ Money::format($f['fund_pending']) }} / {{ Money::format($f['fund_approved']) }} / {{ Money::format($f['fund_utilised']) }}</td></tr>
        <tr><td><strong>Suggested further coverage</strong></td><td class="num strong">{{ Money::format($f['fund_suggested']) }}</td></tr>
        <tr><td>Loss still uncovered after utilised coverage</td><td class="num">{{ Money::format($f['pending_loss_after_fund']) }}</td></tr>
        <tr><td>Actual cash still required (separate from profit)</td><td class="num">{{ Money::format($f['cash_required']) }}</td></tr>
        <tr><td>Emergency Reserve balance</td><td class="num">{{ Money::format($balance) }}</td></tr>
    </table></div>
    <p class="panel-body muted">Coverage finances the shortfall. It is not revenue and the lot keeps its accounting loss. Nothing is reserved or deducted until the Board approves and Finance utilises the request.</p>
</section>

@can('sourcing.fund.request') @if ($f['fund_suggested'] > 0 && in_array($lot->status, ['approved', 'in_progress', 'completed']))
<section class="panel">
    <div class="panel-head"><h2>Request Emergency Fund coverage</h2></div>
    <form method="POST" action="{{ route('sourcing.lots.fund.store', $lot) }}" class="panel-body">@csrf
        <div class="form-grid">
            <div class="field"><label>Amount (max {{ Money::format($f['fund_suggested']) }})</label><input name="amount" inputmode="decimal" value="{{ Money::toDecimal($f['fund_suggested']) }}" required></div>
            <div class="field"><label>Reason</label><input name="reason" required></div>
        </div>
        <button class="btn btn-primary" style="margin-top:.9rem">Submit for Board approval</button>
    </form>
</section>
@endif @endcan

<section class="panel">
    <div class="panel-head"><h2>Requests</h2></div>
    <div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Date</th><th>Requested by</th><th class="num">Amount</th><th>Reason</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($lot->fundRequests as $r)
            <tr><td>{{ $r->created_at->format('j M Y') }}</td><td>{{ $r->requester->name }}</td><td class="num">{{ Money::format($r->amount) }}</td>
                <td>{{ $r->reason }}@if ($r->decision_note)<span class="secondary">Decision: {{ $r->decision_note }}</span>@endif @if ($r->reverse_reason)<span class="secondary">Reversed: {{ $r->reverse_reason }}</span>@endif</td>
                <td><x-status :value="$r->status" /></td>
                <td class="num">
                    @if ($r->status === 'pending') @can('sourcing.fund.approve') @if ($r->requested_by !== auth()->id())
                        <form method="POST" action="{{ route('sourcing.fund.decide', $r) }}" class="inline-form">@csrf<input name="note" placeholder="note">
                            <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button></form>
                    @endif @endcan @endif
                    @if ($r->status === 'approved') @can('sourcing.fund.utilize')
                        <form method="POST" action="{{ route('sourcing.fund.utilise', $r) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary">Utilise</button></form>
                    @endcan @endif
                    @if ($r->status === 'utilised') @can('sourcing.fund.utilize')
                        <form method="POST" action="{{ route('sourcing.fund.reverse', $r) }}" class="inline-form">@csrf<input name="reason" placeholder="reason" required><button class="btn btn-sm btn-danger">Reverse</button></form>
                    @endcan @endif
                    @if (in_array($r->status, ['pending', 'approved']) && ($r->requested_by === auth()->id() || auth()->user()->can('sourcing.fund.utilize')))
                        <form method="POST" action="{{ route('sourcing.fund.cancel', $r) }}" style="display:inline">@csrf<button class="btn btn-sm">Cancel</button></form>
                    @endif
                </td></tr>
        @empty
            <tr><td colspan="6" class="muted">No Emergency Fund requests for this lot.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
