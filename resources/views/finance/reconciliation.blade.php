@use('App\Support\Money')
@php
    $fmt = fn (array $r, string $key): string => $r['unit'] === 'count' ? (string) $r[$key] : Money::format($r[$key], config('finance.currency'));
    $bad = collect($rows)->where('ok', false)->count();
@endphp
<x-layout title="Reconciliation">
    <x-page-header title="Financial Reconciliation">
        <x-slot:meta>Each line compares what is recorded with what the source records say it should be. A review records that a person looked at a finding; it never changes a figure. Every correction is a separate, reviewed, reversible posting taken after a backup.</x-slot:meta>
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
                    <td>@unless ($r['ok'])
                        <span class="secondary">{{ $r['reason'] }}</span><span class="secondary"><strong>Proposal:</strong> {{ $r['proposal'] }}</span>
                        @if ($r['review'])
                            <span class="secondary">@if ($r['changed_since_review'])<span class="badge badge-unpaid">Changed since review</span>@else<x-status :value="$r['review']->status" />@endif {{ $r['review']->note }} — {{ $r['review']->reviewer?->email }}, {{ $r['review']->updated_at->format('j M Y') }}</span>
                        @endif
                        @can('reconcile.review')
                            <details><summary class="btn btn-sm">{{ $r['review'] ? 'Update review' : 'Review' }}</summary>
                                <form method="POST" action="{{ route('finance.reconciliation.review') }}" style="display:grid; gap:.4rem; margin-top:.4rem; min-width:15rem">@csrf
                                    <input type="hidden" name="key" value="{{ $r['key'] }}">
                                    <select name="status" aria-label="Status"><option value="acknowledged">Acknowledged — understood, left as is</option><option value="resolved">Resolved — corrected separately</option></select>
                                    <input name="note" placeholder="What you found / did" required aria-label="Note">
                                    <button class="btn btn-sm btn-primary">Save review</button>
                                </form></details>
                        @endcan
                    @endunless</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</x-layout>
