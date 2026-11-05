@use('App\Services\StockLocations')
<x-layout title="Transfers & locations">
    <x-page-header title="Transfers &amp; locations">
        <x-slot:meta>A transfer moves units between two places in our warehouse. The line total, ownership and value never change, and nothing moves until the transfer is completed.</x-slot:meta>
    </x-page-header>

    @can('inventory.adjust')
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:1rem">
        <section class="panel"><div class="panel-head"><h2>Request a transfer</h2></div><div class="panel-body">
            <form method="POST" action="{{ route('stock.transfers.store') }}" class="form-grid">@csrf
                <div class="field"><label for="line">Product / lot</label><select id="line" name="sourcing_lot_line_id" required>@foreach ($lines as $l)<option value="{{ $l->id }}" @selected(old('sourcing_lot_line_id') == $l->id)>{{ $l->lot->number }} {{ $l->description }}</option>@endforeach</select>@error('sourcing_lot_line_id') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="from">From</label><select id="from" name="from_location_id"><option value="">Not placed</option>@foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected(old('from_location_id') == $loc->id)>{{ $loc->name }}</option>@endforeach</select>@error('from_location_id') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="to">To</label><select id="to" name="to_location_id" required>@foreach ($locations as $loc)<option value="{{ $loc->id }}" @selected(old('to_location_id') == $loc->id)>{{ $loc->name }}</option>@endforeach</select>@error('to_location_id') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="quantity">Quantity</label><input id="quantity" name="quantity" inputmode="decimal" value="{{ old('quantity') }}" required>@error('quantity') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="reason">Reason</label><input id="reason" name="reason" value="{{ old('reason') }}" required>@error('reason') <span class="error">{{ $message }}</span> @enderror</div>
                <button class="btn btn-primary">Request transfer</button>
            </form>
        </div></section>
        <section class="panel"><div class="panel-head"><h2>Add a location</h2></div><div class="panel-body">
            <form method="POST" action="{{ route('stock.locations.store') }}" class="form-grid">@csrf
                <div class="field"><label for="code">Code</label><input id="code" name="code" maxlength="20" required>@error('code') <span class="error">{{ $message }}</span> @enderror</div>
                <div class="field"><label for="name">Name</label><input id="name" name="name" required>@error('name') <span class="error">{{ $message }}</span> @enderror</div>
                <button class="btn">Add location</button>
            </form>
        </div></section>
        <section class="panel"><div class="panel-head"><h2>Low-stock level</h2></div><div class="panel-body">
            <form method="POST" action="{{ route('stock.thresholds.store') }}" class="form-grid">@csrf
                <div class="field"><label for="p">Product</label><select id="p" name="supplier_product_id" required>@foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                <div class="field"><label for="pr">Project (optional)</label><select id="pr" name="project_id"><option value="">All projects</option>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                <div class="field"><label for="lc">Location (optional)</label><select id="lc" name="stock_location_id"><option value="">All locations</option>@foreach ($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach</select></div>
                <div class="field"><label for="min">Warn below</label><input id="min" name="minimum_quantity" inputmode="decimal" required>@error('minimum_quantity') <span class="error">{{ $message }}</span> @enderror</div>
                <button class="btn">Save level</button>
            </form>
        </div></section>
    </div>
    @endcan

    <section class="panel"><div class="panel-head"><h2>Transfers</h2></div><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Requested</th><th>Product / lot</th><th>From</th><th>To</th><th class="num">Quantity</th><th>Reason</th><th>Status</th>@can('inventory.adjust')<th></th>@endcan</tr></thead>
        <tbody>
        @forelse ($transfers as $t)
            <tr><td>{{ $t->created_at->format('j M Y') }}</td><td class="primary-cell">{{ $t->line->description }}<span class="secondary">{{ $t->line->lot->number }}</span></td>
                <td>{{ $t->from?->name ?? 'Not placed' }}</td><td>{{ $t->to->name }}</td><td class="num">{{ $t->quantity }}</td><td>{{ $t->reason }}</td>
                <td><span class="badge {{ $t->status === 'completed' ? 'badge-paid' : ($t->status === 'cancelled' ? 'badge-archived' : 'badge-partial') }}">{{ ucfirst($t->status) }}</span></td>
                @can('inventory.adjust')
                <td>@if ($t->status === 'requested')
                    <form method="POST" action="{{ route('stock.transfers.complete', $t) }}" class="inline-form">@csrf<button class="btn btn-primary">Complete</button></form>
                    <form method="POST" action="{{ route('stock.transfers.cancel', $t) }}" class="inline-form">@csrf<button class="btn">Cancel</button></form>
                @endif</td>
                @endcan
            </tr>
        @empty
            <tr><td colspan="8" class="muted">No transfers yet.</td></tr>
        @endforelse
        </tbody>
    </table></div></section>
</x-layout>
