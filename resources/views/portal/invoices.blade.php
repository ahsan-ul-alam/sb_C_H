@use('App\Support\Money')
@php
    $m = fn (int $v) => Money::format($v, config('finance.currency'));
@endphp
<x-portal-layout title="Invoices & Payments" subtitle="Only verified payments are counted. A cancelled payment is not shown.">
    <div class="pt-grid pt-g3">
        <x-portal.kpi tone="primary" icon="invoice" label="Total invoiced" :value="$m($summary['total'])" />
        <x-portal.kpi tone="primary" icon="check-circle" label="Paid" :value="$m($summary['paid'])" hint="Verified payments" />
        <x-portal.kpi tone="primary" icon="alert" label="Outstanding" :value="$m($summary['outstanding'])" :hint="$summary['open'].' open '.\Illuminate\Support\Str::plural('invoice', $summary['open'])" />
    </div>
    <section class="pt-card">
        @if ($invoices->count())
        <div class="pt-table-wrap"><table class="pt-table stack">
            <thead><tr><th>Invoice</th><th>Project</th><th class="r">Amount</th><th class="r">Paid</th><th class="r">Outstanding</th><th>Status</th><th>Payments</th><th class="r">Document</th></tr></thead>
            <tbody>@foreach ($invoices as $i)
                <tr><td class="lead"><strong>{{ $i->number }}</strong><small>Issued {{ $i->issued_on->format('j M Y') }}@if ($i->due_on) · due {{ $i->due_on->format('j M Y') }}@endif</small></td>
                    <td data-label="Project">{{ $i->project->name }}</td>
                    <td class="r num" data-label="Amount">{{ $m($i->total) }}</td><td class="r num" data-label="Paid">{{ $m($i->amount_paid) }}</td><td class="r num" data-label="Outstanding"><strong>{{ $m($i->balance()) }}</strong></td>
                    <td data-label="Status"><x-portal.status kind="invoice" :value="$i->isOverdue() ? 'Overdue' : $i->status->label()" /></td>
                    <td data-label="Payments">@forelse ($i->payments as $p)<span class="num" style="display:block">{{ $p->paid_on->format('j M Y') }} · {{ $m($p->amount) }}</span>@empty<span class="muted">None yet</span>@endforelse</td>
                    <td class="r" data-label="Document"><a class="pt-btn pt-btn-sm" href="{{ route('portal.invoices.pdf', $i->id) }}" target="_blank" rel="noopener"><x-icon name="eye" /> View</a> <a class="pt-btn pt-btn-sm" href="{{ route('portal.invoices.pdf', [$i->id, 'download' => 1]) }}"><x-icon name="download" /> PDF</a></td></tr>
            @endforeach</tbody>
        </table></div>
        @include('portal._pager', ['paginator' => $invoices])
        @else
            <x-portal.empty icon="invoice" title="No invoices yet">Invoices from Sellbees will appear here, together with the payments recorded against them.</x-portal.empty>
        @endif
    </section>
</x-portal-layout>
