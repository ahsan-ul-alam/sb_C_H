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
                            @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)
                                <form method="POST" action="{{ route($r['route'], $r['param']) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>
                            @endforeach
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
</x-layout>
