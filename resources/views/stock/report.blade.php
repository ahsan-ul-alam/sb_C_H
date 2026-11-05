<x-layout :title="$report['title']">
    <x-page-header :title="$report['title']" :crumbs="[['Reports', route('stock.reports')], [$report['title'], null]]">
        <x-slot:actions><a class="btn" href="{{ route('stock.report', array_merge(['type' => $type], request()->query(), ['export' => 'csv'])) }}">Export CSV</a></x-slot:actions>
    </x-page-header>
    <section class="panel"><form method="GET" class="filters"><div class="field"><label>From</label><input type="date" name="from" value="{{ request('from') }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ request('to') }}"></div><div class="field"><label>Lot</label><input name="lot" size="5" value="{{ request('lot') }}"></div><button class="btn">Filter</button></form>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr>@foreach ($report['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>@forelse ($report['rows'] as $row)<tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($report['headers']) }}" class="muted">Nothing to report.</td></tr>@endforelse</tbody>
        </table></div></section>
</x-layout>
