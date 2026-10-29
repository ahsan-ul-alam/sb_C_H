@use('App\Support\Money')
<x-layout title="Journal">
    <x-page-header title="Journal">
        <x-slot:actions><a class="btn" href="{{ route('finance.journal.csv') }}"><x-icon name="download" class="icon-sm" /> Export CSV</a></x-slot:actions>
    </x-page-header>
    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Entry</th><th>Date</th><th>Memo</th><th>Account</th><th>Partner</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
            <tbody>
            @forelse ($lines as $l)
                <tr><td>#{{ $l->journal_entry_id }}</td><td class="nowrap">{{ $l->entry->entry_date->format('j M Y') }}</td><td>{{ $l->entry->memo }}</td>
                    <td>{{ $l->account }}</td><td>{{ $l->partner?->code ?? '—' }}</td>
                    <td class="num">{{ $l->debit ? Money::format($l->debit) : '' }}</td><td class="num">{{ $l->credit ? Money::format($l->credit) : '' }}</td></tr>
            @empty
                <tr><td colspan="7" class="muted">Nothing posted yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $lines])
    </section>
</x-layout>
