@use('App\Support\Money')
@php($open = in_array($lot->status, ['approved', 'in_progress', 'completed']))
<section class="panel">
    <div class="panel-head"><h2>Client allocations &amp; billing</h2>
        <span class="muted">Only the product, quantity and agreed unit price go onto the client's invoice. Supplier prices, costs and margins stay internal.</span></div>
    <div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Client / project</th><th>Item</th><th class="num">Qty</th><th class="num">Agreed price</th><th class="num">Amount</th><th>Billing</th><th></th></tr></thead>
        <tbody>
        @forelse ($lot->allocations as $a)
            <tr><td>{{ $a->project->client->name }}<span class="secondary">{{ $a->project->name }}</span></td>
                <td>{{ $a->description }} @if ($a->kind === 'recovery')<span class="badge badge-partial">Cost recovery</span>@endif</td>
                <td class="num">{{ $a->quantity }}</td><td class="num">{{ Money::format($a->unit_price) }}</td><td class="num">{{ Money::format($a->amount()) }}</td>
                <td>@if ($a->invoice)<a href="{{ route('invoices.show', $a->invoice) }}">{{ $a->invoice->number }}</a> <x-status :value="$a->invoice->status->value" />@else <span class="muted">Not billed</span>@endif</td>
                <td class="num">
                    @unless ($a->billed_invoice_id) @can('sourcing.manage') @unless ($lot->isClosed())
                        <form method="POST" action="{{ route('sourcing.allocations.bill', $a) }}" class="inline-form">@csrf
                            <select name="segment_id" title="Segment"><option value="">New sourcing segment</option>@foreach ($a->project->segments as $seg)<option value="{{ $seg->id }}">Add to: {{ $seg->name }}</option>@endforeach</select>
                            <select name="invoice_id" title="Invoice"><option value="">New invoice</option>@foreach ($a->project->invoices->where('status', '!=', \App\Enums\InvoiceStatus::Paid) as $inv)<option value="{{ $inv->id }}">Add to {{ $inv->number }}</option>@endforeach</select>
                            <button class="btn btn-sm btn-primary">Bill</button></form>
                        <form method="POST" action="{{ route('sourcing.allocations.destroy', $a) }}" style="display:inline">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Remove</button></form>
                    @endunless @endcan @endunless
                </td></tr>
        @empty
            <tr><td colspan="7" class="muted">Not allocated to any client yet. Unallocated usable units are valued at the lot price in the lot totals.</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr><td colspan="4">Billed on invoices / received by ClientHub</td><td class="num">{{ Money::format($f['billed']) }}</td><td colspan="2" class="num">{{ Money::format($f['received']) }} received · {{ Money::format($f['receivable']) }} receivable</td></tr></tfoot>
    </table></div>
</section>

@can('sourcing.manage') @if ($open && ! $lot->isClosed())
<section class="panel">
    <div class="panel-head"><h2>Allocate to a client</h2><span class="muted">Remaining usable units per line are shown; you cannot allocate more than that.</span></div>
    <form method="POST" action="{{ route('sourcing.lots.allocations.store', $lot) }}" class="panel-body">@csrf
        <input type="hidden" name="kind" value="sale">
        <div class="form-grid">
            <div class="field"><label>Client project</label><select name="project_id" required>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->client->name }} — {{ $p->name }}</option>@endforeach</select></div>
            <div class="field"><label>Product line</label><select name="line_id" required>@foreach ($f['lines'] as $id => $row)<option value="{{ $id }}">{{ $row['line']->description }} ({{ $row['unallocated_qty'] }} left @ {{ Money::format($row['line']->approved_price ?? 0) }})</option>@endforeach</select></div>
            <div class="field"><label>Quantity</label><input name="quantity" inputmode="decimal" required></div>
            @can('sourcing.price.approve')<div class="field"><label>Agreed unit price (blank = approved price)</label><input name="unit_price" inputmode="decimal"></div>@endcan
        </div>
        <button class="btn btn-primary" style="margin-top:.9rem">Allocate</button>
    </form>
    <form method="POST" action="{{ route('sourcing.lots.allocations.store', $lot) }}" class="panel-body" style="border-top:1px solid var(--border)">@csrf
        <input type="hidden" name="kind" value="recovery">
        <p class="muted">Agreed client reimbursement of an unexpected cost. It is billed through the normal invoice flow and is not income until ClientHub receives the money.</p>
        <div class="form-grid">
            <div class="field"><label>Client project</label><select name="project_id" required>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->client->name }} — {{ $p->name }}</option>@endforeach</select></div>
            <div class="field"><label>Recovery amount</label><input name="unit_price" inputmode="decimal" required></div>
            <div class="field"><label>Description on invoice</label><input name="description" placeholder="Additional sourcing cost"></div>
        </div>
        <button class="btn" style="margin-top:.9rem">Add cost recovery</button>
    </form>
</section>
@endif @endcan
