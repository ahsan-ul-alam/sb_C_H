@use('App\Support\Money')
@php($quantities = \App\Services\ProjectSourcingReport::quantities($f))
<p class="muted">Operational status (where the goods are) is separate from the financial position: moving a lot to "Arrived" records nothing in the books. Money moves only when payments, settlements and costs are recorded and verified.</p>

@can('sourcing.manage') @unless ($lot->isClosed())
<section class="panel">
    <div class="panel-head"><h2>Purchase order and client project</h2></div>
    <form method="POST" action="{{ route('sourcing.lots.details', $lot) }}" class="panel-body inline-form">@csrf
        <input name="purchase_order" value="{{ old('purchase_order', $lot->purchase_order) }}" maxlength="60" placeholder="Purchase order number">
        <select name="project_id"><option value="">No client project</option>@foreach (\App\Models\Project::whereNull('archived_at')->orderBy('name')->get(['id', 'name']) as $p)<option value="{{ $p->id }}" @selected($lot->project_id === $p->id)>{{ $p->name }}</option>@endforeach</select>
        <button class="btn btn-primary">Save</button>
    </form>
</section>
@endunless @endcan

<section class="panel">
    <div class="panel-head"><h2>Procurement status</h2><span class="strong">{{ \App\Models\SourcingLot::PROCUREMENT[$lot->procurement_status] ?? $lot->procurement_status }}</span></div>
    @can('sourcing.manage') @unless ($lot->isClosed())
    <form method="POST" action="{{ route('sourcing.lots.procurement', $lot) }}" class="panel-body inline-form">@csrf
        <select name="procurement_status">@foreach (\App\Models\SourcingLot::PROCUREMENT as $k => $label)<option value="{{ $k }}" @selected($lot->procurement_status === $k)>{{ $label }}</option>@endforeach</select>
        <button class="btn btn-primary">Update status</button>
    </form>
    @endunless @endcan
</section>

<section class="panel">
    <div class="panel-head"><h2>Receive and inspect goods</h2><span class="muted">Record what actually reached the room: received, accepted, and the delivery or inspection reference.</span></div>
    <div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Product</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Accepted</th><th class="num">Damaged / rejected</th><th class="num">Missing</th><th class="num">Still to arrive</th><th>Reference</th><th></th></tr></thead>
        <tbody>
        @foreach ($quantities as $q)
            <tr>
                <td>{{ $q['line']->description }} <span class="secondary">{{ $q['line']->supplier->name }}</span></td>
                <td class="num">{{ $q['purchased'] }}</td>
                <td class="num">{{ $q['received'] ?? 'not recorded' }}</td><td class="num">{{ $q['accepted'] }}</td>
                <td class="num">{{ $q['rejected'] ?? '—' }}</td><td class="num">{{ $q['missing'] ?? '—' }}</td><td class="num">{{ $q['pending'] ?? '—' }}</td>
                <td>{{ $q['acceptance_reference'] ?? '—' }}<span class="secondary">{{ $q['arrived_on']?->format('j M Y') }}</span></td>
                <td>@can('sourcing.manage') @unless ($lot->isClosed())
                    <form method="POST" action="{{ route('sourcing.lines.arrival', $q['line']) }}" class="inline-form">@csrf
                        <input name="received_quantity" placeholder="Received" inputmode="decimal" required size="7"><input name="accepted_quantity" placeholder="Accepted" inputmode="decimal" size="7">
                        <input name="acceptance_reference" placeholder="Delivery note / inspection ref" size="14"><input type="date" name="arrived_on" max="{{ today()->toDateString() }}" required><button class="btn btn-sm">Record</button>
                    </form>
                @endunless @endcan</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>

<section class="panel">
    <div class="panel-head"><h2>Supporting documents</h2><span class="muted">Supplier invoice or proforma, payment slip, delivery note, inspection record (PDF, JPG or PNG, up to 8 MB).</span></div>
    @can('sourcing.manage')
    <form method="POST" action="{{ route('sourcing.lots.documents.store', $lot) }}" enctype="multipart/form-data" class="panel-body inline-form">@csrf
        <input name="name" placeholder="What is this document?" required><input type="file" name="file" required><button class="btn btn-primary">Attach</button>
    </form>
    @endcan
    <div class="table-wrap"><table class="table table-min-640">
        <thead><tr><th>Document</th><th>Uploaded</th><th></th></tr></thead>
        <tbody>
        @forelse ($documents as $d)
            <tr><td>{{ $d->name }}</td><td>{{ $d->created_at->format('j M Y H:i') }}</td><td class="num"><a class="btn btn-sm" href="{{ route('sourcing.documents.download', $d) }}">Download</a></td></tr>
        @empty
            <tr><td colspan="3" class="muted">No documents attached yet. Amounts above reference these by their invoice, slip or delivery-note numbers.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
