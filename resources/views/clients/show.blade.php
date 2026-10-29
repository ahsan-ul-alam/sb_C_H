@use('App\Support\Money')
@use('App\Support\ListFilters')
<x-layout :title="$client->name">
    <x-page-header :title="$client->name" :crumbs="[['Clients', ListFilters::url('clients')], [$client->name, null]]">
        <x-slot:badges>
            @if ($client->isArchived()) <span class="badge badge-archived">Archived</span> @endif
        </x-slot:badges>
        <x-slot:meta>
            Client since {{ $client->created_at->format('M Y') }} · {{ $projects->count() }} {{ Str::plural('project', $projects->count()) }}
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('clients.edit', $client) }}" class="btn"><x-icon name="edit" class="icon-sm" /> Edit</a>
            @if ($projects->isNotEmpty())
                <a href="{{ route('invoices.start', ['client' => $client->id]) }}" class="btn"><x-icon name="invoice" class="icon-sm" /> New invoice</a>
            @endif
            <a href="{{ route('clients.projects.create', $client) }}" class="btn btn-primary"><x-icon name="plus" /> New project</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-main-side">
        <div class="stack">
            @if ($summaries->isNotEmpty())
                @foreach ($summaries as $summary)
                    <div class="summary-strip">
                        <div><div class="group">{{ $summary['currency'] }} · Projects</div><div class="label">Agreed charges</div><div class="value">{{ Money::format($summary['charges']) }}</div></div>
                        <div><div class="group">&nbsp;</div><div class="label">Received</div><div class="value">{{ Money::format($summary['received']) }}</div></div>
                        <div><div class="group">&nbsp;</div><div class="label">Project balance</div><div class="value">{{ Money::format($summary['charges'] - $summary['received']) }}</div></div>
                        <div><div class="group">{{ $summary['currency'] }} · Invoices</div><div class="label">Invoiced</div><div class="value">{{ Money::format($summary['invoiced']) }}</div></div>
                        <div><div class="group">&nbsp;</div><div class="label">Invoice balance due</div><div class="value">{{ Money::format($summary['invoice_balance']) }}</div></div>
                    </div>
                @endforeach
            @endif

            <section class="panel">
                <nav class="tabs" aria-label="Client records">
                    <a href="{{ route('clients.show', [$client, 'tab' => 'projects']) }}" @class(['active' => $tab === 'projects'])>Projects<span class="count">{{ $projects->count() }}</span></a>
                    <a href="{{ route('clients.show', [$client, 'tab' => 'invoices']) }}" @class(['active' => $tab === 'invoices'])>Invoices<span class="count">{{ $invoices->count() }}</span></a>
                    <a href="{{ route('clients.show', [$client, 'tab' => 'payments']) }}" @class(['active' => $tab === 'payments'])>Payments<span class="count">{{ $payments->count() }}</span></a>
                </nav>

                @if ($tab === 'projects')
                    @if ($projects->isEmpty())
                        <x-empty-state icon="folder" message="No projects for this client yet.">
                            <a href="{{ route('clients.projects.create', $client) }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="icon-sm" /> New project</a>
                        </x-empty-state>
                    @else
                        <div class="table-wrap">
                            <table class="table table-min-640">
                                <thead><tr><th>Project</th><th>Status</th><th class="num">Charges</th><th class="num">Received</th><th class="num">Project balance</th></tr></thead>
                                <tbody>
                                    @foreach ($projects as $project)
                                        @php($charges = (int) $project->segments_sum_amount)
                                        @php($received = (int) $project->payments_sum_amount)
                                        <tr>
                                            <td class="primary-cell">
                                                <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>
                                                <span class="secondary">{{ $project->currency }} · started {{ $project->started_on->format('j M Y') }} · {{ $project->invoices_count }} {{ Str::plural('invoice', $project->invoices_count) }}</span>
                                            </td>
                                            <td>
                                                <span class="badge badge-{{ $project->status->value }}">{{ $project->status->label() }}</span>
                                                @if ($project->isArchived()) <span class="badge badge-archived">Archived</span> @endif
                                            </td>
                                            <td class="num">{{ Money::format($charges) }}</td>
                                            <td class="num">{{ Money::format($received) }}</td>
                                            <td class="num strong">{{ Money::format($charges - $received) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @elseif ($tab === 'invoices')
                    @if ($invoices->isEmpty())
                        <x-empty-state icon="invoice" message="No invoices for this client yet.">
                            @if ($projects->isNotEmpty())
                                <a href="{{ route('invoices.start', ['client' => $client->id]) }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="icon-sm" /> New invoice</a>
                            @endif
                        </x-empty-state>
                    @else
                        <div class="table-wrap">
                            <table class="table table-min-640">
                                <thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Remaining</th><th>Status</th></tr></thead>
                                <tbody>
                                    @foreach ($invoices as $invoice)
                                        <tr @class(['is-overdue' => $invoice->isOverdue()])>
                                            <td class="primary-cell"><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a><span class="secondary">{{ $invoice->project->name }}</span></td>
                                            <td class="nowrap">{{ $invoice->issued_on->format('j M Y') }}</td>
                                            <td class="nowrap">{{ $invoice->due_on?->format('j M Y') ?? '—' }}</td>
                                            <td class="num">{{ Money::format($invoice->total, $invoice->currency) }}</td>
                                            <td class="num strong">{{ Money::format($invoice->balance(), $invoice->currency) }}</td>
                                            <td><x-invoice-status :invoice="$invoice" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @else
                    @if ($payments->isEmpty())
                        <x-empty-state icon="wallet" message="No payments recorded for this client yet." />
                    @else
                        <div class="table-wrap">
                            <table class="table table-min-640">
                                <thead><tr><th>Date</th><th>Project / Invoice</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead>
                                <tbody>
                                    @foreach ($payments as $payment)
                                        <tr>
                                            <td class="nowrap">{{ $payment->paid_on->format('j M Y') }}</td>
                                            <td>
                                                <a href="{{ route('projects.show', $payment->project) }}">{{ $payment->project->name }}</a>
                                                @if ($payment->invoice)<span class="secondary"><a href="{{ route('invoices.show', $payment->invoice) }}">{{ $payment->invoice->number }}</a></span>@endif
                                            </td>
                                            <td>{{ $payment->method->label() }}</td>
                                            <td>{{ $payment->reference ?? '—' }}</td>
                                            <td class="num strong">{{ Money::format($payment->amount, $payment->project->currency) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            </section>
        </div>

        <aside class="stack">
            <section class="panel">
                <div class="panel-head"><h2>Contact</h2></div>
                <div class="panel-body">
                    <ul class="contact-list">
                        <li><x-icon name="phone" class="icon-sm" /> <span>{{ $client->mobile }}@if ($client->mobile_alt)<br>{{ $client->mobile_alt }}@endif</span></li>
                        @if ($client->email)<li><x-icon name="mail" class="icon-sm" /> <a href="mailto:{{ $client->email }}">{{ $client->email }}</a></li>@endif
                        @if ($client->address || $client->country)
                            <li><x-icon name="map-pin" class="icon-sm" /> <span style="white-space:pre-line">{{ trim($client->address."\n".$client->country) }}</span></li>
                        @endif
                        @if ($client->id_type || $client->id_number)
                            <li><x-icon name="id-card" class="icon-sm" /> <span>{{ $client->id_type?->label() ?? 'ID' }}{{ $client->id_number ? ': '.$client->id_number : '' }}</span></li>
                        @endif
                    </ul>
                    @if ($client->notes)
                        <div class="callout" style="margin-top:1rem; white-space:pre-line">{{ $client->notes }}</div>
                    @endif
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h2>Record keeping</h2></div>
                <div class="panel-body">
                    @if ($client->canBeDeleted())
                        <p class="hint">This client has no projects and can be deleted permanently.</p>
                        <form method="POST" action="{{ route('clients.destroy', $client) }}" data-confirm="Permanently delete {{ $client->name }}? This cannot be undone.">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm"><x-icon name="trash" class="icon-sm" /> Delete client</button>
                        </form>
                    @else
                        <p class="hint">Clients with projects are kept for financial history. Archiving hides the client from the main list; nothing is deleted.</p>
                        <form method="POST" action="{{ route('clients.archive', $client) }}" data-confirm="{{ $client->isArchived() ? 'Restore this client to the main list?' : 'Archive this client? It will be hidden from the main client list but all records stay.' }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn btn-sm"><x-icon :name="$client->isArchived() ? 'restore' : 'archive'" class="icon-sm" /> {{ $client->isArchived() ? 'Restore client' : 'Archive client' }}</button>
                        </form>
                    @endif
                </div>
            </section>
        </aside>
    </div>
</x-layout>
