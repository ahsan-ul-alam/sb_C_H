@use('App\Support\Money')
@use('App\Support\ListFilters')
@use('App\Enums\PaymentMethod')
@php
    $cur = $project->currency;
    $editing = $payment->exists;
    $action = $editing ? route('payments.update', $payment) : ($invoice ? route('invoices.payments.store', $invoice) : route('segments.payments.store', $segment));
    $existingAllocations = $editing ? $payment->allocations->pluck('amount', 'segment_id') : collect();
    $remaining = $invoice
        ? $invoice->balance() + ($editing ? $payment->amount : 0)
        : ($segment ? $segment->balance($payment->id) : 0);
    $back = $invoice ? route('invoices.show', $invoice) : route('projects.show', $project);
@endphp
<x-layout :title="$editing ? 'Edit payment' : 'Record payment'">
    <x-page-header :title="$editing ? 'Edit payment' : 'Record payment'"
        :crumbs="$invoice
            ? [['Invoices', ListFilters::url('invoices')], [$invoice->number, route('invoices.show', $invoice)], [$editing ? 'Edit payment' : 'Record payment', null]]
            : [['Projects', ListFilters::url('projects')], [$project->name, route('projects.show', $project)], [$editing ? 'Edit payment' : 'Record payment', null]]">
        <x-slot:meta>
            <span>Client: {{ $project->client->name }}</span>
            <span>Project: <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></span>
        </x-slot:meta>
    </x-page-header>

    <div class="grid grid-main-side">
        <form method="POST" action="{{ $action }}" class="panel">
            @csrf
            @if ($editing)
                @method('PUT')
            @else
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            @endif

            <div class="form-section">
                <h2>Payment</h2>
                <div class="form-grid">
                    <div class="field">
                        <label for="amount">Amount ({{ $cur }})<span class="req" aria-hidden="true">*</span></label>
                        <input type="text" id="amount" name="amount" value="{{ old('amount', $payment->amount ? Money::toDecimal($payment->amount) : '') }}" required inputmode="decimal" autofocus placeholder="0.00" class="amount-input @error('amount') is-invalid @enderror">
                        <span class="hint">Up to {{ Money::format($remaining, $cur) }} can be recorded here.</span>
                        @error('amount') <span class="error">{{ $message }}</span> @enderror
                    </div>
                    <div class="field">
                        <label for="paid_on">Payment date<span class="req" aria-hidden="true">*</span></label>
                        <input type="date" id="paid_on" name="paid_on" value="{{ old('paid_on', $payment->paid_on?->format('Y-m-d')) }}" required>
                    </div>
                    <div class="field">
                        <label for="method">Method<span class="req" aria-hidden="true">*</span></label>
                        <select id="method" name="method" required>
                            @foreach (PaymentMethod::cases() as $method)
                                <option value="{{ $method->value }}" @selected(old('method', $payment->method?->value) === $method->value)>{{ $method->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="reference">Reference <span class="opt">(optional)</span></label>
                        <input type="text" id="reference" name="reference" value="{{ old('reference', $payment->reference) }}" placeholder="Transaction ID, cheque no.">
                    </div>
                    <div class="field full">
                        <label for="notes">Notes <span class="opt">(optional)</span></label>
                        <textarea id="notes" name="notes" rows="2">{{ old('notes', $payment->notes) }}</textarea>
                    </div>
                </div>
            </div>

            @if ($invoice && $segments->isNotEmpty())
                <div class="form-section">
                    <h2>Allocate to segments <span class="opt" style="font-weight:400; color:var(--muted)">(optional)</span></h2>
                    <p class="section-hint">
                        Leave every box empty to credit the segments billed on this invoice automatically.
                        Enter amounts only to split the payment yourself. The payment is stored once either way.
                    </p>
                    @error('allocations') <p class="error">{{ $message }}</p> @enderror
                    <div class="table-wrap">
                        <table class="table" style="border:1px solid var(--border); border-radius:var(--radius-sm)">
                            <thead><tr><th>Segment</th><th class="num">Remaining</th><th style="width:170px">Allocate ({{ $cur }})</th></tr></thead>
                            <tbody>
                                @foreach ($segments as $seg)
                                    @php($existing = $existingAllocations->get($seg->id))
                                    <tr>
                                        <td>{{ $seg->name }}</td>
                                        <td class="num">{{ Money::format($seg->balance($payment->id)) }}</td>
                                        <td><input type="text" name="allocations[{{ $seg->id }}]" value="{{ old('allocations.'.$seg->id, $existing ? Money::toDecimal($existing) : '') }}" inputmode="decimal" placeholder="auto" aria-label="Allocate to {{ $seg->name }}"></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><x-icon name="check" /> {{ $editing ? 'Save payment' : 'Record payment' }}</button>
                <a href="{{ $back }}" class="btn btn-ghost">Cancel</a>
            </div>
        </form>

        <aside class="stack">
            <section class="panel">
                <div class="panel-head"><h2>{{ $invoice ? 'Invoice '.$invoice->number : 'Segment' }}</h2></div>
                <div class="panel-body balance-box">
                    @if ($invoice)
                        <div class="row"><span class="muted">Invoice total</span><span class="num">{{ Money::format($invoice->total, $cur) }}</span></div>
                        <div class="row"><span class="muted">Paid {{ $editing ? '(other payments)' : 'so far' }}</span><span class="num">{{ Money::format($invoice->total - $remaining, $cur) }}</span></div>
                        <div class="row total"><span>Remaining</span><span class="num">{{ Money::format($remaining, $cur) }}</span></div>
                    @elseif ($segment)
                        <p class="strong" style="margin:0">{{ $segment->name }}</p>
                        <div class="row"><span class="muted">Agreed charge</span><span class="num">{{ Money::format($segment->amount, $cur) }}</span></div>
                        <div class="row"><span class="muted">Received {{ $editing ? '(other payments)' : 'so far' }}</span><span class="num">{{ Money::format($segment->amount - $remaining, $cur) }}</span></div>
                        <div class="row total"><span>Remaining</span><span class="num">{{ Money::format($remaining, $cur) }}</span></div>
                    @endif
                </div>
            </section>
            @if ($editing)
                <form method="POST" action="{{ route('payments.destroy', $payment) }}" data-confirm="Remove this payment of {{ Money::format($payment->amount, $cur) }}? Balances and the invoice stamp will be recalculated.">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger btn-sm"><x-icon name="trash" class="icon-sm" /> Remove payment</button>
                </form>
            @endif
        </aside>
    </div>
</x-layout>
