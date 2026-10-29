@use('App\Support\Money')
@use('App\Support\ListFilters')
@php($cur = $project->currency)
@php($openSegments = $segments->filter(fn ($s) => $s->amount - (int) $s->allocations_sum_amount > 0))
<x-layout title="Record payment">
    <x-page-header title="Record payment"
        :crumbs="[['Projects', ListFilters::url('projects')], [$project->name, route('projects.show', $project)], ['Record payment', null]]">
        <x-slot:meta>Client: {{ $project->client->name }} · {{ $cur }}</x-slot:meta>
    </x-page-header>

    <p class="muted" style="margin-top:-.5rem">What is this payment for? Paying an invoice also credits the segments it bills, so the money is never counted twice.</p>

    <div class="grid grid-2">
        <section class="panel">
            <div class="panel-head"><div><h2>Against an invoice</h2><div class="sub">Unpaid and partially paid invoices</div></div></div>
            @if ($invoices->isEmpty())
                <x-empty-state icon="invoice" message="No open invoices for this project.">
                    <a href="{{ route('projects.invoices.create', $project) }}" class="btn btn-sm"><x-icon name="plus" class="icon-sm" /> New invoice</a>
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            @foreach ($invoices as $invoice)
                                <tr @class(['is-overdue' => $invoice->isOverdue()])>
                                    <td class="primary-cell">
                                        <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a>
                                        <span class="secondary">Remaining {{ Money::format($invoice->balance(), $cur) }}</span>
                                    </td>
                                    <td><x-invoice-status :invoice="$invoice" /></td>
                                    <td class="num"><a href="{{ route('invoices.payments.create', $invoice) }}" class="btn btn-sm btn-primary"><x-icon name="cash" class="icon-sm" /> Record</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="panel-head"><div><h2>Against a segment</h2><div class="sub">Direct payment toward an agreed charge, without an invoice</div></div></div>
            @if ($openSegments->isEmpty())
                <x-empty-state icon="layers" message="{{ $segments->isEmpty() ? 'This project has no segments yet.' : 'All segments are fully paid.' }}">
                    <a href="{{ route('projects.show', $project) }}#add-segment" class="btn btn-sm">Go to segments</a>
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            @foreach ($openSegments as $segment)
                                <tr>
                                    <td class="primary-cell">
                                        <span class="strong">{{ $segment->name }}</span>
                                        <span class="secondary">Remaining {{ Money::format($segment->amount - (int) $segment->allocations_sum_amount, $cur) }} of {{ Money::format($segment->amount) }}</span>
                                    </td>
                                    <td class="num"><a href="{{ route('segments.payments.create', $segment) }}" class="btn btn-sm btn-primary"><x-icon name="cash" class="icon-sm" /> Record</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layout>
