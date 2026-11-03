@php($cur = config('finance.currency'))
<x-layout title="Pending Approvals">
    <x-page-header title="Pending Approvals">
        <x-slot:meta>Everything waiting for a second person. You can approve an item only if your role allows it and you did not create it yourself. {{ $total }} waiting, {{ $mine }} for you to decide.</x-slot:meta>
    </x-page-header>

    @forelse ($groups as $group => $rows)
    <section class="panel">
        <div class="panel-head"><h2>{{ $group }}</h2><span class="muted">{{ $rows->count() }} waiting</span></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>What</th><th>Type</th><th>Entered by</th><th>Date</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>@if ($r['link'])<a href="{{ $r['link'] }}">{{ $r['title'] }}</a>@else{{ $r['title'] }}@endif</td>
                    <td class="muted">{{ $r['detail'] }}</td>
                    <td>{{ $r['by_name'] ?? '—' }}@if ($r['is_mine']) <span class="badge badge-partial">you</span>@endif</td>
                    <td class="nowrap">{{ $r['date'] }}</td>
                    <td class="num">{{ $r['amount_text'] }}</td>
                    <td class="num">
                        @if ($r['can_decide'])
                            <form method="POST" action="{{ route($r['route'], $r['param']) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="verify"><button class="btn btn-sm btn-success">Verify</button></form>
                            <form method="POST" action="{{ route($r['route'], $r['param']) }}" style="display:inline-flex;gap:.25rem;flex-wrap:wrap">@csrf<input type="hidden" name="decision" value="reject"><input type="hidden" name="reason_required" value="1"><input name="reason" required maxlength="200" placeholder="Reason for rejecting" class="input" style="min-width:10rem"><button class="btn btn-sm btn-danger">Reject</button></form>
                        @elseif ($r['is_mine'])
                            <span class="muted">Waiting for another approver</span>
                        @else
                            <span class="muted">Your role cannot approve this</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
    @empty
        <section class="panel"><div class="panel-body muted">Nothing is waiting for approval.</div></section>
    @endforelse

    <section class="panel">
        <div class="panel-head"><h2>Approval history</h2><span class="muted">Latest {{ count($history) }} decisions</span></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>What</th><th>Decision</th><th>Entered by</th><th>Decided by</th><th>Reason</th><th class="num">Amount</th></tr></thead>
            <tbody>
            @forelse ($history as $h)
                <tr>
                    <td>@if ($h['link'])<a href="{{ $h['link'] }}">{{ $h['title'] }}</a>@else{{ $h['title'] }}@endif <span class="muted">{{ $h['reference'] }}</span></td>
                    <td><span class="badge {{ $h['status'] === 'rejected' ? 'badge-danger' : 'badge-paid' }}">{{ ucfirst($h['status']) }}</span> <span class="muted nowrap">{{ $h['decided_at'] }}</span></td>
                    <td>{{ $h['by_name'] ?? '—' }}</td>
                    <td>{{ $h['decided_by_name'] ?? '—' }}</td>
                    <td>{{ $h['note'] ?? '—' }}</td>
                    <td class="num">{{ $h['amount_text'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No decisions yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
