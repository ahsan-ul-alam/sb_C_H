{{-- Propose a void. Nothing changes until a different Board member approves it; the payment is never deleted. --}}
@php($pendingVoid = \App\Models\PaymentVoidRequest::where('payment_id', $payment->id)->where('status', 'pending')->exists())
@if ($pendingVoid)
    <span class="badge" title="A Board member must decide this void">Void pending</span>
@else
    <details class="void-proposal">
        <summary class="btn btn-sm {{ $compact ? 'btn-ghost' : 'btn-danger' }}" style="{{ $compact ? 'color:var(--danger)' : '' }}">Request void</summary>
        <form method="POST" action="{{ route('payments.void-requests.store', $payment) }}" style="display:flex; flex-direction:column; gap:.25rem; margin-top:.25rem">
            @csrf
            <input name="reason" required maxlength="255" placeholder="Reason (e.g. duplicate entry)">
            <input name="evidence" required maxlength="255" placeholder="Evidence: bank / bKash statement reference">
            <button type="submit" class="btn btn-sm btn-danger">Propose void</button>
            <span class="secondary">The payment stays active until a different Board member approves. It is never deleted.</span>
        </form>
    </details>
@endif
