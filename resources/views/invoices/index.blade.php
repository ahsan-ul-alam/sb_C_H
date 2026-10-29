@use('App\Support\Money')
@use('App\Support\DateRange')
@php($filtered = request()->hasAny(['q', 'client', 'project', 'status', 'overdue', 'currency', 'range']))
<x-layout title="Invoices">
    <x-page-header title="Invoices">
        <x-slot:meta>
            {{ $invoices->total() }} {{ Str::plural('invoice', $invoices->total()) }}
            @if ($range) · Issued {{ $range->label() }} @endif
            @if ($filterProject) · Project: <a href="{{ route('projects.show', $filterProject) }}">{{ $filterProject->name }}</a> @endif
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ $filterProject ? route('projects.invoices.create', $filterProject) : route('invoices.start', request()->only('client')) }}" class="btn btn-primary"><x-icon name="plus" /> New invoice</a>
        </x-slot:actions>
    </x-page-header>

    <section class="panel">
        <form method="GET" action="{{ route('invoices.index') }}" class="filters" role="search">
            @if ($filterProject) <input type="hidden" name="project" value="{{ $filterProject->id }}"> @endif
            <div class="field grow">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Invoice number, client or project">
            </div>
            <div class="field">
                <label for="client">Client</label>
                <select id="client" name="client">
                    <option value="">All clients</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((int) request('client') === $client->id)>{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="status">Payment status</label>
                <select id="status" name="status">
                    <option value="">Any status</option>
                    <option value="open" @selected(request('status') === 'open')>Not fully paid</option>
                    <option value="unpaid" @selected(request('status') === 'unpaid')>Due (nothing paid)</option>
                    <option value="partial" @selected(request('status') === 'partial')>Partially paid</option>
                    <option value="paid" @selected(request('status') === 'paid')>Paid</option>
                </select>
            </div>
            <div class="field">
                <label for="currency">Currency</label>
                <select id="currency" name="currency">
                    <option value="">All</option>
                    @foreach ($currencies as $code)
                        <option value="{{ $code }}" @selected(request('currency') === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="range">Issued</label>
                <select id="range" name="range">
                    <option value="">Any date</option>
                    @foreach (DateRange::PRESETS as $key => $label)
                        <option value="{{ $key }}" @selected($range?->preset === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($range?->preset === 'custom')
                <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="{{ $range->from->toDateString() }}"></div>
                <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="{{ $range->to->toDateString() }}"></div>
            @endif
            <label class="check"><input type="checkbox" name="overdue" value="1" @checked(request()->boolean('overdue'))> Overdue only</label>
            <button type="submit" class="btn"><x-icon name="search" class="icon-sm" /> Filter</button>
            @if ($filtered)
                <a href="{{ route('invoices.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </form>

        @if ($invoices->isEmpty())
            <x-empty-state icon="invoice" message="{{ $filtered ? 'No invoices match these filters.' : 'No invoices yet. Create one from a project.' }}">
                @unless ($filtered)
                    <a href="{{ route('invoices.start') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="icon-sm" /> New invoice</a>
                @endunless
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table table-min-760">
                    <thead>
                        <tr><th>Invoice / Client</th><th>Project</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Remaining</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr @class(['is-overdue' => $invoice->isOverdue()])>
                                <td class="primary-cell">
                                    <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a>
                                    <span class="secondary">{{ $invoice->project->client->name }}</span>
                                </td>
                                <td><a href="{{ route('projects.show', $invoice->project) }}">{{ $invoice->project->name }}</a></td>
                                <td class="nowrap">{{ $invoice->issued_on->format('j M Y') }}</td>
                                <td class="nowrap">{{ $invoice->due_on?->format('j M Y') ?? '—' }}</td>
                                <td class="num">{{ Money::format($invoice->total, $invoice->currency) }}</td>
                                <td class="num strong">{{ Money::format($invoice->balance(), $invoice->currency) }}</td>
                                <td class="nowrap"><x-invoice-status :invoice="$invoice" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $invoices])
        @endif
    </section>
</x-layout>
