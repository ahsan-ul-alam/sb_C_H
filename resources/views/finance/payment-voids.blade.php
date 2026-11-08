@use('App\Support\Money')
<x-layout title="Payment Voids">
    <x-page-header title="Payment Voids">
        <x-slot:meta>A payment is never deleted. Finance proposes a void with a reason and statement evidence; a different Board member approves or rejects it. An approved void removes the payment from every active total once; the original record, its allocations and this history are kept. {{ $voided }} voided to date.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Proposed</th><th>Client / Project</th><th>Payment</th><th class="num">Amount</th><th>Reason</th><th>Evidence</th><th>By</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($requests as $r)
                @php($p = $r->payment)
                <tr><td class="nowrap">{{ $r->created_at->format('j M Y') }}</td>
                    <td>{{ $p->project->client->name }}<span class="secondary">{{ $p->project->name }}</span></td>
                    <td>#{{ $p->id }} · {{ $p->paid_on->format('j M Y') }}<span class="secondary">{{ $p->invoice?->number ?? 'no invoice' }}</span></td>
                    <td class="num">{{ Money::format($r->amount, $p->project->currency) }}</td>
                    <td>{{ $r->reason }}</td><td>{{ $r->evidence }}</td>
                    <td>{{ $r->requester->name }}@if ($r->decider)<span class="secondary">{{ ucfirst($r->status) }} by {{ $r->decider->name }}@if ($r->decision_note) · {{ $r->decision_note }}@endif</span>@endif</td>
                    <td><x-status :value="$r->status" /></td>
                    <td class="num">@if ($r->status === 'pending') @can('payment.void.approve')
                        <form method="POST" action="{{ route('finance.payment-voids.decide', $r) }}" style="display:flex; gap:.25rem; flex-wrap:wrap">@csrf
                            <input name="note" size="14" maxlength="255" placeholder="note (required to reject)">
                            <button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button>
                            <button class="btn btn-sm btn-danger" name="decision" value="reject">Reject</button>
                        </form>
                    @endcan @endif</td></tr>
            @empty
                <tr><td colspan="9" class="muted">No void requests.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
