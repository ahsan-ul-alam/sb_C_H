@use('App\Support\Money')
@php
    $fmt = fn (array $r, string $key): string => $r['unit'] === 'count' ? (string) $r[$key] : Money::format($r[$key], config('finance.currency'));
    $bad = collect($rows)->where('ok', false)->count();
@endphp
<x-layout title="Reconciliation">
    <x-page-header title="Financial Reconciliation">
        <x-slot:meta>Read-only. Each line compares what is recorded with what the source records say it should be. Nothing on this page changes data; every correction is a separate, reviewed, reversible posting taken after a backup.</x-slot:meta>
        <x-slot:actions><a class="btn" href="{{ route('finance.reconciliation.csv') }}">Download CSV</a></x-slot:actions>
    </x-page-header>

    <div class="flash {{ $bad ? 'flash-error' : '' }}" role="status"><x-icon name="{{ $bad ? 'alert' : 'check-circle' }}" /> <div>{{ $bad ? $bad.' check(s) need review.' : 'All checks agree.' }}</div></div>

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Area</th><th>Check</th><th class="num">Recorded</th><th class="num">Recalculated</th><th class="num">Difference</th><th>Reason &amp; proposed correction</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr><td>{{ $r['area'] }}</td><td>{{ $r['check'] }} @if ($r['ok'])<span class="badge badge-paid">OK</span>@else<span class="badge badge-unpaid">Review</span>@endif</td>
                    <td class="num">{{ $fmt($r, 'recorded') }}</td><td class="num">{{ $fmt($r, 'recalculated') }}</td>
                    <td class="num {{ $r['ok'] ? 'muted' : 'text-danger strong' }}">{{ $fmt($r, 'difference') }}</td>
                    <td>@unless ($r['ok'])<span class="secondary">{{ $r['reason'] }}</span><span class="secondary"><strong>Proposal:</strong> {{ $r['proposal'] }}</span>@endunless</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</x-layout>
