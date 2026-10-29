@props(['invoice'])
@php($labels = ['unpaid' => 'Due', 'partial' => 'Partially Paid', 'paid' => 'Paid'])
<span class="badge badge-{{ $invoice->status->value }}">{{ $labels[$invoice->status->value] }}</span>
@if ($invoice->isOverdue())
    <span class="badge badge-overdue badge-plain" title="Due {{ $invoice->due_on->format('j M Y') }}">Overdue</span>
@endif
