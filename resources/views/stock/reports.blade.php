<x-layout title="Inventory reports">
    <x-page-header title="Inventory &amp; sales reports">
        <x-slot:meta>Every report uses the same formulas as the dashboards and can be exported as CSV.</x-slot:meta>
    </x-page-header>
    <section class="panel"><div class="table-wrap"><table class="table">
        <thead><tr><th>Report</th><th></th></tr></thead>
        <tbody>@foreach ($types as $key => $title)
            <tr><td><a href="{{ route('stock.report', $key) }}">{{ $title }}</a></td><td><a class="btn" href="{{ route('stock.report', ['type' => $key, 'export' => 'csv']) }}">Export CSV</a></td></tr>
        @endforeach</tbody></table></div></section>
</x-layout>
