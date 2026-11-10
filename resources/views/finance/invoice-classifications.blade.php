@use('App\Support\Money')
<x-layout title="Invoice Classification">
    <x-page-header title="Invoice Classification">
        <x-slot:meta>Client reports group invoice lines by category. Finance proposes a category for a line that has none; a different authorised user verifies it. This changes how a line is reported to the client only: invoices, payments and journals are never touched, and every step is kept.</x-slot:meta>
    </x-page-header>

    @can('invoice.classify.approve')
    <section class="panel">
        <div class="panel-head"><h2>Waiting for verification</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Client</th><th>Invoice line</th><th class="num">Amount</th><th>Proposed category</th><th>Reason</th><th>Proposed by</th><th></th></tr></thead>
            <tbody>@forelse ($pending as $p)
                <tr><td>{{ $p->item->invoice->project->client->name }}</td><td>{{ $p->item->invoice->number }} · {{ $p->item->description }}</td><td class="num">{{ Money::format($p->item->amount, config('finance.currency')) }}</td>
                    <td>{{ \App\Support\InvoiceCategories::label($p->category) }}</td><td>{{ $p->reason }}</td><td>{{ $p->proposer->name }}</td>
                    <td class="num">@if ($p->proposed_by !== auth()->id())
                        @foreach (['approve' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)<form method="POST" action="{{ route('finance.classifications.decide', $p) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>@endforeach
                        @else<span class="muted">Your proposal</span>@endif</td></tr>
            @empty<tr><td colspan="7" class="muted">Nothing is waiting.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
    @endcan

    @can('invoice.classify')
    <section class="panel">
        <div class="panel-head"><h2>Lines that need a category</h2><span class="sub">No approved classification, no matching segment name or invoice purpose, and not backed by sourcing billing.</span></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Client</th><th>Invoice line</th><th class="num">Amount</th><th>Propose</th></tr></thead>
            <tbody>@forelse ($needs as $n)
                <tr><td>{{ $n['invoice']->project->client->name }}</td><td>{{ $n['invoice']->number }} · {{ $n['item']->description }}@if ($n['invoice']->purpose)<br><span class="muted">Invoice purpose: {{ $n['invoice']->purpose }}</span>@endif</td><td class="num">{{ Money::format($n['item']->amount, config('finance.currency')) }}</td>
                    <td><form method="POST" action="{{ route('finance.classifications.propose') }}" class="inline-form">@csrf<input type="hidden" name="invoice_item_id" value="{{ $n['item']->id }}">
                        <select name="category" required aria-label="Category"><option value="">Category…</option>@foreach ($categories as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                        <input name="reason" placeholder="Reason" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-primary">Propose</button></form></td></tr>
            @empty<tr><td colspan="4" class="muted">Every invoice line has a category.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Recent decisions</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Line</th><th>Category</th><th>Result</th><th>Reason</th><th>Decided</th></tr></thead>
            <tbody>@forelse ($recent as $r)<tr><td>{{ $r->item->invoice->number }} · {{ $r->item->description }}</td><td>{{ \App\Support\InvoiceCategories::label($r->category) }}</td><td><x-status :value="$r->status" /></td><td>{{ $r->reason }}</td><td>{{ $r->decided_at?->format('j M Y') }}</td></tr>@empty<tr><td colspan="5" class="muted">No decisions yet.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
</x-layout>