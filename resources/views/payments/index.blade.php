@use('App\Support\Money')
@use('App\Support\DateRange')
@php($filtered = request()->hasAny(['q', 'client', 'project', 'method', 'currency', 'range']))
<x-layout title="Payments">
    <x-page-header title="Payments">
        <x-slot:meta>
            {{ $payments->total() }} {{ Str::plural('payment', $payments->total()) }}
            @if ($range) · Paid {{ $range->label() }} @endif
        </x-slot:meta>
    </x-page-header>

    @if ($totalsByCurrency->isNotEmpty())
        <div class="summary-strip">
            @foreach ($totalsByCurrency as $row)
                <div>
                    <div class="label">Total received{{ $filtered ? ' (filtered)' : '' }}</div>
                    <div class="value">{{ Money::format((int) $row->total, $row->currency) }}</div>
                    <div class="hint">{{ $row->count }} {{ Str::plural('payment', $row->count) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <section class="panel">
        <form method="GET" action="{{ route('payments.index') }}" class="filters" role="search">
            @if (request('project')) <input type="hidden" name="project" value="{{ (int) request('project') }}"> @endif
            <div class="field grow">
                <label for="q">Search</label>
                <input type="search" id="q" name="q" value="{{ request('q') }}" placeholder="Client, project, invoice no. or reference">
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
                <label for="method">Method</label>
                <select id="method" name="method">
                    <option value="">Any method</option>
                    @foreach (\App\Enums\PaymentMethod::cases() as $method)
                        <option value="{{ $method->value }}" @selected(request('method') === $method->value)>{{ $method->label() }}</option>
                    @endforeach
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
                <label for="range">Paid</label>
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
            <button type="submit" class="btn"><x-icon name="search" class="icon-sm" /> Filter</button>
            @if ($filtered)
                <a href="{{ route('payments.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </form>

        @if ($payments->isEmpty())
            <x-empty-state icon="wallet" message="{{ $filtered ? 'No payments match these filters.' : 'No payments yet. Record them from an invoice or a project segment.' }}">
                @unless ($filtered)
                    <a href="{{ route('invoices.index', ['status' => 'open']) }}" class="btn btn-sm">Open invoices</a>
                @endunless
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table table-min-760">
                    <thead>
                        <tr><th>Date</th><th>Client / Project</th><th>Invoice</th><th>Method</th><th>Segments</th><th class="num">Amount</th><th><span class="sr-only">Actions</span></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td class="nowrap">{{ $payment->paid_on->format('j M Y') }}</td>
                                <td class="primary-cell">
                                    <a href="{{ route('projects.show', $payment->project) }}">{{ $payment->project->client->name }}</a>
                                    <span class="secondary">{{ $payment->project->name }}</span>
                                </td>
                                <td>@if ($payment->invoice)<a href="{{ route('invoices.show', $payment->invoice) }}">{{ $payment->invoice->number }}</a>@else<span class="muted">—</span>@endif</td>
                                <td>{{ $payment->method->label() }}@if ($payment->reference)<span class="secondary">{{ $payment->reference }}</span>@endif</td>
                                <td class="muted">{{ $payment->allocations->map(fn ($a) => $a->segment->name)->implode(', ') ?: '—' }}</td>
                                <td class="num strong">{{ Money::format($payment->amount, $payment->project->currency) }}</td>
                                <td class="num"><a href="{{ route('payments.edit', $payment) }}" class="btn btn-sm btn-ghost"><x-icon name="edit" class="icon-sm" /> Edit</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('partials.pagination', ['paginator' => $payments])
        @endif
    </section>
</x-layout>
