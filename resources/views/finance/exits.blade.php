@use('App\Support\Money')
<x-layout title="Partner Exits">
    <x-page-header title="Partner Exit Settlements">
        <x-slot:meta>Final = adjusted investment + exit incentive + unpaid approved entitlements + receivables − liabilities. Incentive = last 12 months of verified Company Fund allocations × the partner's profit-fund ratio.</x-slot:meta>
    </x-page-header>

    @can('exit.prepare')
    <section class="panel">
        <div class="panel-head"><h2>Calculate settlement</h2></div>
        <form method="POST" action="{{ route('finance.exits.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Partner</label><select name="partner_id">@foreach ($partners as $p)<option value="{{ $p->id }}">{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></div>
                <div class="field"><label>Exit date</label><input type="date" name="exit_date" value="{{ old('exit_date') }}" required></div>
                <div class="field"><label>Previous capital withdrawals</label><input name="previous_withdrawals" value="{{ old('previous_withdrawals') }}"></div>
                <div class="field"><label>Approved expenses/liabilities against investment</label><input name="expenses_liabilities" value="{{ old('expenses_liabilities') }}"></div>
                <div class="field"><label>Other verified liabilities (partner owes company)</label><input name="other_liabilities" value="{{ old('other_liabilities') }}"></div>
                <div class="field"><label>Other receivables (company owes partner)</label><input name="other_receivables" value="{{ old('other_receivables') }}"></div>
                <div class="field full"><label>Supporting note / document reference</label><input name="adjustment_note" value="{{ old('adjustment_note') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Calculate</button>
        </form>
    </section>
    @endcan

    @forelse ($exits as $e)
        <section class="panel">
            <div class="panel-head">
                <div><h2>{{ $e->partner->code }} · {{ $e->partner->name }}</h2><div class="sub">Exit {{ $e->exit_date->format('j M Y') }} · <x-status :value="$e->status" /> · payment {{ $e->payment_status }}@if ($e->payment_reference) ({{ $e->payment_reference }})@endif</div></div>
                <div class="strong">{{ Money::format($e->net_settlement) }}</div>
            </div>
            <div class="table-wrap"><table class="table"><tbody>
                <tr><td>Recorded investment (verified)</td><td class="num">{{ Money::format($e->recorded_investment) }}</td></tr>
                <tr><td>− Approved unadjusted losses</td><td class="num">{{ Money::format($e->unadjusted_losses) }}</td></tr>
                <tr><td>− Approved expenses/liabilities</td><td class="num">{{ Money::format($e->expenses_liabilities) }}</td></tr>
                <tr><td>− Previous capital withdrawals</td><td class="num">{{ Money::format($e->previous_withdrawals) }}</td></tr>
                <tr><td class="strong">= Adjusted investment</td><td class="num strong">{{ Money::format($e->capital_balance) }}</td></tr>
                <tr><td>Exit incentive ({{ Money::format($e->company_fund_base) }} × {{ number_format($e->profit_bps / 100, 2) }}%)</td><td class="num">{{ Money::format($e->incentive) }}</td></tr>
                <tr><td>+ Unpaid approved entitlements</td><td class="num">{{ Money::format($e->unpaid_entitlements) }}</td></tr>
                <tr><td>+ Other receivables</td><td class="num">{{ Money::format($e->other_receivables) }}</td></tr>
                <tr><td>− Other verified liabilities</td><td class="num">{{ Money::format($e->other_liabilities) }}</td></tr>
                <tr><td class="strong">= Final settlement</td><td class="num strong">{{ Money::format($e->net_settlement) }}</td></tr>
            </tbody></table></div>
            <div class="panel-foot">
                @if ($e->adjustment_note) <div class="muted">{{ $e->adjustment_note }}</div> @endif
                <div style="margin:.4rem 0">
                    <strong>Documents</strong>
                    @foreach ($documents[$e->id] ?? [] as $d)
                        <div><a href="{{ route('finance.documents.download', $d) }}">{{ $d->name }}</a> — {{ $d->review_status }}
                            @if ($d->review_status === 'pending') @can('exit.approve')
                                <form method="POST" action="{{ route('finance.documents.review', $d) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="approve"><button class="btn btn-sm btn-success">Approve</button></form>
                                <form method="POST" action="{{ route('finance.documents.review', $d) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="reject"><button class="btn btn-sm btn-danger">Reject</button></form>
                            @endcan @endif
                        </div>
                    @endforeach
                    @if ($e->status !== 'cancelled') @can('document.upload')
                        <form method="POST" action="{{ route('finance.documents.store') }}" enctype="multipart/form-data" class="compact-form" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-start; margin-top:.3rem">@csrf
                            <input type="hidden" name="subject_type" value="exit"><input type="hidden" name="subject_id" value="{{ $e->id }}">
                            <input name="name" placeholder="Document title" required><span class="upload-wrap"><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png" aria-label="Document file (PDF, JPG or PNG, up to 8 MB)"><span class="upload-note">PDF, JPG or PNG · max 8 MB</span></span><button class="btn btn-sm">Attach</button>
                        </form>
                    @endcan @endif
                </div>
                @if ($e->status === 'cancelled')
                    <div class="muted">Cancelled: {{ $e->cancel_reason }}</div>
                @elseif ($e->payment_status === 'unpaid')
                    @can('exit.prepare')
                        <form method="POST" action="{{ route('finance.exits.cancel', $e) }}" style="display:flex; gap:.4rem; margin:.4rem 0" data-confirm="Cancel this unpaid settlement so it can be recalculated?">@csrf
                            <input name="cancel_reason" placeholder="Reason for cancelling" required><button class="btn btn-sm btn-danger">Cancel &amp; recalculate</button>
                        </form>
                    @endcan
                @endif
                @if ($e->status === 'proposed')
                    @can('exit.approve')
                        <form method="POST" action="{{ route('finance.exits.approve', $e) }}" data-confirm="Approve and mark partner as exited?">@csrf<button class="btn btn-sm btn-success">Approve settlement</button></form>
                    @endcan
                @elseif ($e->status === 'approved' && $e->payment_status === 'unpaid')
                    @can('exit.pay')
                        <form method="POST" action="{{ route('finance.exits.pay', $e) }}" style="display:flex; gap:.5rem; flex-wrap:wrap" data-confirm="Confirm the transfer has actually been made?">@csrf
                            <input type="date" name="paid_on" required><input name="payment_reference" placeholder="Bank/transfer reference" required>
                            <button class="btn btn-sm btn-primary">Record payment made</button>
                        </form>
                    @endcan
                @endif
            </div>
        </section>
    @empty
        <section class="panel"><div class="panel-body muted">No exit settlements.</div></section>
    @endforelse
</x-layout>
