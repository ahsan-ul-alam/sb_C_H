@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout title="Shared Cost Allocation">
    <x-page-header title="Shared Project Cost Allocation">
        <x-slot:meta>Shared office costs are never charged to projects automatically. Split a verified expense across projects here if you want a fully loaded view; the split is for reporting only, appears separately on each project, and never enters gross profit or the ledger. Saving a split replaces the previous one.</x-slot:meta>
    </x-page-header>
    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Expense</th><th class="num">Amount</th><th>Split across projects</th></tr></thead>
            <tbody>
            @forelse ($expenses as $e)
                @php($split = $e->allocations->pluck('amount', 'project_id'))
                <tr><td>{{ $e->description }}<span class="secondary">{{ $e->category }} · {{ $e->spent_on->format('j M Y') }}</span></td>
                    <td class="num">{{ Money::format($e->amount, $cur) }}<span class="secondary">Allocated {{ Money::format((int) $split->sum()) }}</span></td>
                    <td>
                        @can('expense.record')
                        <details @if ($split->isNotEmpty()) open @endif><summary class="btn btn-sm">{{ $split->isNotEmpty() ? 'Edit split' : 'Split' }}</summary>
                            <form method="POST" action="{{ route('finance.expenses.allocate', $e) }}" style="display:grid; gap:.35rem; margin-top:.4rem; max-width:22rem">@csrf
                                @foreach ($projects as $p)
                                    <label style="display:flex; justify-content:space-between; gap:.6rem; align-items:center">{{ $p->name }}<input name="allocations[{{ $p->id }}]" inputmode="decimal" value="{{ $split->has($p->id) ? Money::toDecimal((int) $split[$p->id]) : '' }}" style="max-width:8rem" placeholder="0.00"></label>
                                @endforeach
                                <button class="btn btn-sm btn-primary">Save split</button>
                            </form></details>
                        @else
                            @foreach ($e->allocations as $a){{ $a->project->name }}: {{ Money::format($a->amount) }}<br>@endforeach
                        @endcan
                    </td></tr>
            @empty
                <tr><td colspan="3" class="muted">No shared expenses to split.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $expenses])
    </section>
</x-layout>