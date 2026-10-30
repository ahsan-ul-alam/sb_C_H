@use('App\Support\Money')
<x-layout title="Supplier Payments">
    <x-page-header title="Supplier Payments">
        <x-slot:meta>Payments are recorded from the lot's Payments tab. Each one creates a single expense for Board verification; it is not an extra cost on top of the lot's recognised cost.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <div class="panel-head"><h2>Supplier payables</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Supplier</th><th class="num">Purchase value</th><th class="num">Paid</th><th class="num">Outstanding</th></tr></thead>
            <tbody>
            @forelse ($suppliers as $row)
                <tr><td><a href="{{ route('sourcing.suppliers.show', $row['supplier']) }}">{{ $row['supplier']->name }}</a></td><td class="num">{{ Money::format($row['payable']['purchases']) }}</td>
                    <td class="num">{{ Money::format($row['payable']['paid']) }}</td><td class="num {{ $row['payable']['outstanding'] > 0 ? 'text-danger' : '' }}">{{ Money::format($row['payable']['outstanding']) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No supplier purchases yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Payment history</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Date</th><th>Supplier</th><th>Lot</th><th>Method / ref</th><th class="num">Amount</th><th>State</th></tr></thead>
            <tbody>
            @forelse ($payments as $p)
                <tr @if ($p->isVoided()) style="opacity:.55" @endif><td>{{ $p->paid_on->format('j M Y') }}</td><td>{{ $p->supplier->name }}</td>
                    <td>@if ($p->lot)<a href="{{ route('sourcing.lots.show', [$p->lot, 'tab' => 'payments']) }}">{{ $p->lot->number }}</a>@endif</td>
                    <td>{{ $p->method }} {{ $p->reference }}</td><td class="num">{{ Money::format($p->amount) }}</td>
                    <td><x-status :value="$p->isVoided() ? 'voided' : $p->expense->status" /></td></tr>
            @empty
                <tr><td colspan="6" class="muted">No payments yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $payments])
    </section>
</x-layout>
