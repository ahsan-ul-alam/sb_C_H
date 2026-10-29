@use('App\Support\Money')
@use('App\Support\ListFilters')
@php($cur = $project->currency)
<x-layout :title="$project->name">
    <x-page-header :title="$project->name"
        :crumbs="[['Projects', ListFilters::url('projects')], [$project->client->name, route('clients.show', $project->client)], [$project->name, null]]">
        <x-slot:badges>
            <span class="badge badge-{{ $project->status->value }}">{{ $project->status->label() }}</span>
            <span class="badge badge-plain">{{ $cur }}</span>
            @if ($project->isArchived()) <span class="badge badge-archived">Archived</span> @endif
        </x-slot:badges>
        <x-slot:meta>
            <span>Client: <a href="{{ route('clients.show', $project->client) }}">{{ $project->client->name }}</a></span>
            <span>Created {{ $project->started_on->format('j M Y') }}</span>
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('projects.edit', $project) }}" class="btn"><x-icon name="edit" class="icon-sm" /> Edit</a>
            <a href="{{ route('projects.payments.choose', $project) }}" class="btn"><x-icon name="cash" class="icon-sm" /> Record payment</a>
            <a href="{{ route('projects.invoices.create', $project) }}" class="btn btn-primary"><x-icon name="plus" /> New invoice</a>
        </x-slot:actions>
    </x-page-header>

    <div class="summary-strip">
        <div><div class="group">Project balance</div><div class="label">Agreed charges</div><div class="value">{{ Money::format($totals['charges']) }}</div></div>
        <div><div class="group">&nbsp;</div><div class="label">Payments received</div><div class="value" style="color:var(--success)">{{ Money::format($totals['received']) }}</div></div>
        <div><div class="group">&nbsp;</div><div class="label">Remaining project balance</div><div class="value">{{ Money::format($totals['outstanding']) }}</div></div>
        <div><div class="group">Invoices</div><div class="label">Invoiced so far</div><div class="value">{{ Money::format($totals['invoiced']) }}</div></div>
        <div><div class="group">&nbsp;</div><div class="label">Invoice balance due</div><div class="value" @if ($totals['invoice_balance'] > 0) style="color:var(--danger)" @endif>{{ Money::format($totals['invoice_balance']) }}</div></div>
    </div>
    <p class="hint" style="margin:-.75rem 0 1.25rem">Amounts in {{ $cur }}. The project balance covers every agreed charge; the invoice balance only covers what has been invoiced.</p>

    <div class="grid grid-main-side">
        <div class="stack">
            <section class="panel" id="segments">
                <div class="panel-head">
                    <div>
                        <h2>Segments</h2>
                        <div class="sub">Agreed charges and the payments received against each</div>
                    </div>
                    <a href="#add-segment" class="btn btn-sm" onclick="setTimeout(() => document.getElementById('seg_name').focus(), 0)"><x-icon name="plus" class="icon-sm" /> Add segment</a>
                </div>
                @if ($segments->isEmpty())
                    <x-empty-state icon="layers" message="No segments yet. Add each agreed charge below, e.g. “Onboarding charge – 10,000”." />
                @else
                    <div class="table-wrap">
                        <table class="table table-min-760">
                            <thead>
                                <tr><th>Segment</th><th class="num">Agreed</th><th class="num">Received</th><th class="num">Remaining</th><th style="width:130px">Progress</th><th><span class="sr-only">Actions</span></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($segments as $segment)
                                    @php($received = (int) $segment->allocations_sum_amount)
                                    @php($remaining = $segment->amount - $received)
                                    @php($pct = $segment->amount > 0 ? min(100, (int) floor($received * 100 / $segment->amount)) : 0)
                                    <tr>
                                        <td class="primary-cell">
                                            <span class="strong">{{ $segment->name }}</span>
                                            @if ($segment->description)<span class="secondary">{{ $segment->description }}</span>@endif
                                        </td>
                                        <td class="num">{{ Money::format($segment->amount) }}</td>
                                        <td class="num">{{ Money::format($received) }}</td>
                                        <td class="num strong">{{ Money::format($remaining) }}</td>
                                        <td>
                                            <div class="progress-cell" title="{{ $pct }}% received">
                                                <div class="progress {{ $remaining <= 0 ? 'is-complete' : '' }}" style="flex:1"><span style="width:{{ $pct }}%"></span></div>
                                                <span class="pct">{{ $pct }}%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                @if ($remaining > 0)
                                                    <a href="{{ route('segments.payments.create', $segment) }}" class="btn btn-sm"><x-icon name="cash" class="icon-sm" /> Payment</a>
                                                @endif
                                                <a href="{{ route('projects.invoices.create', [$project, 'segment' => $segment->id]) }}" class="btn btn-sm" title="Create an invoice for this segment"><x-icon name="invoice" class="icon-sm" /> Invoice</a>
                                                <a href="{{ route('segments.edit', $segment) }}" class="btn btn-sm btn-ghost" title="Edit segment"><x-icon name="edit" class="icon-sm" /><span class="sr-only">Edit {{ $segment->name }}</span></a>
                                            </div>
                                        </td>
                                    </tr>
                                    @foreach ($segment->allocations->sortBy(fn ($a) => $a->payment->paid_on) as $allocation)
                                        <tr class="subrow">
                                            <td colspan="2" style="padding-left:1.8rem">
                                                ↳ {{ $allocation->payment->paid_on->format('j M Y') }} · {{ $allocation->payment->method->label() }}
                                                @if ($allocation->payment->reference) · {{ $allocation->payment->reference }} @endif
                                                @if ($allocation->payment->invoice)
                                                    · <a href="{{ route('invoices.show', $allocation->payment->invoice) }}">{{ $allocation->payment->invoice->number }}</a>
                                                @endif
                                            </td>
                                            <td class="num">{{ Money::format($allocation->amount) }}</td>
                                            <td colspan="3" class="num"><a href="{{ route('payments.edit', $allocation->payment_id) }}">Edit payment</a></td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>Total</td>
                                    <td class="num">{{ Money::format($totals['charges']) }}</td>
                                    <td class="num">{{ Money::format((int) $segments->sum('allocations_sum_amount')) }}</td>
                                    <td class="num">{{ Money::format($totals['charges'] - (int) $segments->sum('allocations_sum_amount')) }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif

                <form method="POST" action="{{ route('projects.segments.store', $project) }}" id="add-segment" class="form-section" style="border-top:1px solid var(--border); background:var(--surface-2); border-radius:0 0 var(--radius) var(--radius)">
                    @csrf
                    <h3 style="margin-bottom:.6rem">Add segment</h3>
                    <div class="form-grid">
                        <div class="field">
                            <label for="seg_name">Name<span class="req" aria-hidden="true">*</span></label>
                            <input type="text" id="seg_name" name="name" value="{{ old('name') }}" required maxlength="255" placeholder="e.g. Onboarding charge" @class(['is-invalid' => $errors->has('name')])>
                            @error('name') <span class="error">{{ $message }}</span> @enderror
                        </div>
                        <div class="field">
                            <label for="seg_amount">Agreed amount ({{ $cur }})<span class="req" aria-hidden="true">*</span></label>
                            <input type="text" id="seg_amount" name="amount" value="{{ old('amount') }}" required inputmode="decimal" placeholder="10000.00" class="amount-input @error('amount') is-invalid @enderror">
                            @error('amount') <span class="error">{{ $message }}</span> @enderror
                        </div>
                        <div class="field full">
                            <label for="seg_description">Description <span class="opt">(optional)</span></label>
                            <input type="text" id="seg_description" name="description" value="{{ old('description') }}" placeholder="What this charge covers">
                        </div>
                    </div>
                    <div style="margin-top:.8rem"><button type="submit" class="btn btn-primary btn-sm"><x-icon name="plus" class="icon-sm" /> Add segment</button></div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div><h2>Invoices</h2><div class="sub">Invoice totals are separate from agreed project charges</div></div>
                    <a href="{{ route('projects.invoices.create', $project) }}" class="btn btn-sm"><x-icon name="plus" class="icon-sm" /> New invoice</a>
                </div>
                @if ($invoices->isEmpty())
                    <x-empty-state icon="invoice" message="No invoices for this project yet." />
                @else
                    <div class="table-wrap">
                        <table class="table table-min-640">
                            <thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Remaining</th><th>Status</th></tr></thead>
                            <tbody>
                                @foreach ($invoices as $invoice)
                                    <tr @class(['is-overdue' => $invoice->isOverdue()])>
                                        <td class="primary-cell"><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a>@if ($invoice->purpose)<span class="secondary">{{ $invoice->purpose }}</span>@endif</td>
                                        <td class="nowrap">{{ $invoice->issued_on->format('j M Y') }}</td>
                                        <td class="nowrap">{{ $invoice->due_on?->format('j M Y') ?? '—' }}</td>
                                        <td class="num">{{ Money::format($invoice->total) }}</td>
                                        <td class="num strong">{{ Money::format($invoice->balance()) }}</td>
                                        <td><x-invoice-status :invoice="$invoice" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div><h2>Payments</h2><div class="sub">Each transaction is listed once, with the segments it covers</div></div>
                    <a href="{{ route('projects.payments.choose', $project) }}" class="btn btn-sm"><x-icon name="cash" class="icon-sm" /> Record payment</a>
                </div>
                @if ($payments->isEmpty())
                    <x-empty-state icon="wallet" message="No payments recorded for this project yet." />
                @else
                    <div class="table-wrap">
                        <table class="table table-min-640">
                            <thead><tr><th>Date</th><th>Method</th><th>Invoice</th><th>Segments</th><th class="num">Amount</th><th><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody>
                                @foreach ($payments as $payment)
                                    <tr>
                                        <td class="nowrap">{{ $payment->paid_on->format('j M Y') }}</td>
                                        <td>{{ $payment->method->label() }}@if ($payment->reference)<span class="secondary">{{ $payment->reference }}</span>@endif</td>
                                        <td>@if ($payment->invoice)<a href="{{ route('invoices.show', $payment->invoice) }}">{{ $payment->invoice->number }}</a>@else<span class="muted">—</span>@endif</td>
                                        <td>
                                            @forelse ($payment->allocations as $allocation)
                                                <div>{{ $allocation->segment->name }} <span class="muted">{{ Money::format($allocation->amount) }}</span></div>
                                            @empty
                                                <span class="muted">Not allocated</span>
                                            @endforelse
                                        </td>
                                        <td class="num strong">{{ Money::format($payment->amount) }}</td>
                                        <td class="num"><a href="{{ route('payments.edit', $payment) }}" class="btn btn-sm btn-ghost"><x-icon name="edit" class="icon-sm" /> Edit</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot><tr><td colspan="4">Total received</td><td class="num">{{ Money::format($totals['received'], $cur) }}</td><td></td></tr></tfoot>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <aside class="stack">
            @if ($project->description)
                <section class="panel">
                    <div class="panel-head"><h2>Notes</h2></div>
                    <div class="panel-body" style="white-space:pre-line; font-size:.9rem">{{ $project->description }}</div>
                </section>
            @endif
            <section class="panel">
                <div class="panel-head"><h2>Client</h2></div>
                <div class="panel-body">
                    <p class="strong" style="margin-bottom:.5rem"><a href="{{ route('clients.show', $project->client) }}">{{ $project->client->name }}</a></p>
                    <ul class="contact-list">
                        <li><x-icon name="phone" class="icon-sm" /> {{ $project->client->mobile }}</li>
                        @if ($project->client->email)<li><x-icon name="mail" class="icon-sm" /> {{ $project->client->email }}</li>@endif
                    </ul>
                </div>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Record keeping</h2></div>
                <div class="panel-body">
                    @if ($payments->isEmpty() && $invoices->isEmpty())
                        <p class="hint">No invoices or payments yet, so this project can be deleted.</p>
                        <form method="POST" action="{{ route('projects.destroy', $project) }}" data-confirm="Permanently delete this project and its segments? This cannot be undone.">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm"><x-icon name="trash" class="icon-sm" /> Delete project</button>
                        </form>
                    @else
                        <p class="hint">Projects with invoices or payments are kept for history. Archiving hides the project from lists; records and totals stay intact.</p>
                        <form method="POST" action="{{ route('projects.archive', $project) }}" data-confirm="{{ $project->isArchived() ? 'Restore this project?' : 'Archive this project? It will be hidden from project lists but nothing is deleted.' }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn btn-sm"><x-icon :name="$project->isArchived() ? 'restore' : 'archive'" class="icon-sm" /> {{ $project->isArchived() ? 'Restore project' : 'Archive project' }}</button>
                        </form>
                    @endif
                </div>
            </section>
        </aside>
    </div>
</x-layout>
