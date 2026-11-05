@use('App\Services\Receiving')
<x-layout title="Receiving">
    <x-page-header title="Receiving">
        <x-slot:meta>A lot is not stock. Record each physical arrival here: only the accepted quantity becomes available. Rejected and damaged units stay out of sellable stock, and a delivery reference can be used only once per product.</x-slot:meta>
    </x-page-header>

    <section class="panel"><div class="panel-head"><h2>Expected arrivals</h2></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Lot / product</th><th>Project</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Accepted</th><th class="num">Still expected</th>@can('sourcing.manage')<th>Record an arrival</th>@endcan</tr></thead>
        <tbody>
        @forelse ($expected as $l)
            <tr>
                <td class="primary-cell"><a href="{{ route('sourcing.lots.show', $l->lot) }}">{{ $l->lot->number }}</a> {{ $l->description }}<span class="secondary">{{ $l->supplier->name }}</span></td>
                <td>{{ $l->lot->project?->name ?? '—' }}</td>
                <td class="num">{{ $l->quantity }}</td><td class="num">{{ $l->received_quantity ?? 'none' }}</td><td class="num">{{ $l->received_quantity === null ? '—' : $l->usable_quantity }}</td><td class="num strong">{{ Receiving::expected($l) }}</td>
                @can('sourcing.manage')
                <td>
                    <form method="POST" action="{{ route('stock.receive', $l) }}" class="inline-form">@csrf
                        <input name="reference" size="10" placeholder="delivery ref" value="{{ old('reference') }}" required>
                        <input name="received_quantity" size="5" inputmode="decimal" placeholder="received" required>
                        <input name="accepted_quantity" size="5" inputmode="decimal" placeholder="accepted" required>
                        <input name="damaged_quantity" size="5" inputmode="decimal" placeholder="damaged">
                        <input type="date" name="arrived_on" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required>
                        <select name="stock_location_id" @required($locations->isNotEmpty()) aria-label="Warehouse location">@if ($locations->isEmpty())<option value="">Main warehouse (created on first receipt)</option>@endif @foreach ($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach</select>
                        <input name="evidence_reference" size="12" placeholder="evidence / photo ref">
                        <button class="btn btn-primary">Receive</button>
                    </form>
                </td>
                @endcan
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Nothing is waiting to arrive.</td></tr>
        @endforelse
        </tbody>
    </table></div></section>

    <section class="panel"><div class="panel-head"><h2>Receiving history</h2></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Date</th><th>Reference</th><th>Lot / product</th><th class="num">Received</th><th class="num">Accepted</th><th class="num">Rejected</th><th class="num">Damaged</th><th>Location</th><th>Evidence</th><th>By</th></tr></thead>
        <tbody>
        @forelse ($history as $e)
            <tr><td>{{ $e->arrived_on->format('j M Y') }}</td><td>{{ $e->reference }}</td><td class="primary-cell">{{ $e->line->lot->number }} {{ $e->line->description }}</td>
                <td class="num">{{ $e->received_quantity }}</td><td class="num">{{ $e->accepted_quantity }}</td><td class="num">{{ $e->rejected_quantity }}</td><td class="num">{{ $e->damaged_quantity }}</td>
                <td>{{ $e->location?->name }}</td>
                <td>{{ $e->evidence_reference }}
                    @foreach ($evidence->get($e->id, collect()) as $doc)<a href="{{ route('stock.evidence.download', $doc) }}" title="{{ $doc->sha256 }}">{{ $doc->name }}</a><br>@endforeach
                    @can('sourcing.manage')
                    <form method="POST" action="{{ route('stock.evidence.store', $e) }}" enctype="multipart/form-data" class="inline-form">@csrf
                        <select name="kind" aria-label="Evidence type">@foreach ($kinds as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required><button class="btn btn-sm">Attach</button></form>
                    @endcan</td><td>{{ $e->creator?->name }}</td></tr>
        @empty
            <tr><td colspan="10" class="muted">No arrivals recorded through this screen yet.</td></tr>
        @endforelse
        </tbody>
    </table></div></section>
</x-layout>
