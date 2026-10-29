@use('App\Support\Money')
@use('App\Support\ListFilters')
@use('App\Enums\PaymentMethod')
@php($cur = $invoice->currency)
@php($balance = $invoice->balance())
<x-layout :title="'Invoice '.$invoice->number">
    <x-page-header :title="'Invoice '.$invoice->number"
        :crumbs="[['Invoices', ListFilters::url('invoices')], [$invoice->number, null]]">
        <x-slot:badges><x-invoice-status :invoice="$invoice" /></x-slot:badges>
        <x-slot:meta>
            <span>Client: <a href="{{ route('clients.show', $invoice->project->client) }}">{{ $invoice->project->client->name }}</a></span>
            <span>Project: <a href="{{ route('projects.show', $invoice->project) }}">{{ $invoice->project->name }}</a></span>
            <span>Issued {{ $invoice->issued_on->format('j M Y') }}</span>
            @if ($invoice->due_on)<span>Due {{ $invoice->due_on->format('j M Y') }}</span>@endif
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('invoices.edit', $invoice) }}" class="btn"><x-icon name="edit" class="icon-sm" /> Edit</a>
            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener" class="btn" title="Opens the PDF in a new tab — use your browser's print button"><x-icon name="printer" class="icon-sm" /> Print</a>
            <a href="{{ route('invoices.pdf', [$invoice, 'download' => 1]) }}" @class(['btn', 'btn-primary' => $balance === 0])><x-icon name="download" class="icon-sm" /> Download PDF</a>
            @if ($balance > 0)
                <a href="{{ route('invoices.payments.create', $invoice) }}" class="btn btn-primary"><x-icon name="cash" /> Record payment</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-main-side">
        <section class="panel">
            <div class="panel-head">
                <div><h2>Preview</h2><div class="sub">Generated from the saved invoice and current payments</div></div>
            </div>
            <iframe src="{{ route('invoices.pdf', $invoice) }}#view=FitH" class="pdf-frame" title="Invoice {{ $invoice->number }} PDF preview"></iframe>
        </section>

        <aside class="stack">
            <section class="panel">
                <div class="panel-head">
                    <h2>Payment summary</h2>
                    <span class="stamp {{ $invoice->status->stampClass() }}">{{ $invoice->status->stamp() }}</span>
                </div>
                <div class="panel-body balance-box">
                    <div class="row"><span class="muted">Invoice total</span><span class="num strong">{{ Money::format($invoice->total, $cur) }}</span></div>
                    <div class="row"><span class="muted">Amount paid</span><span class="num" style="color:var(--success)">{{ Money::format($invoice->amount_paid, $cur) }}</span></div>
                    <div class="row total"><span>Remaining</span><span class="num" @if ($balance > 0) style="color:var(--danger)" @endif>{{ Money::format($balance, $cur) }}</span></div>
                    @if ($invoice->isOverdue())
                        <div class="callout" style="background:var(--danger-bg); border-color:#f0c2bd; color:#8f1f18">Overdue since {{ $invoice->due_on->format('j M Y') }}.</div>
                    @endif
                </div>
            </section>

            @if ($balance > 0)
                <section class="panel">
                    <div class="panel-head"><div><h2>Mark as paid</h2><div class="sub">Records one payment for the full remaining amount</div></div></div>
                    <form method="POST" action="{{ route('invoices.mark-paid', $invoice) }}"
                          data-confirm="Record a payment of {{ Money::format($balance, $cur) }} and mark invoice {{ $invoice->number }} as paid?">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <div class="panel-body" style="display:grid; gap:.8rem">
                            <div class="callout">Remaining to record: <strong>{{ Money::format($balance, $cur) }}</strong></div>
                            <div class="field">
                                <label for="mp_paid_on">Payment date<span class="req" aria-hidden="true">*</span></label>
                                <input type="date" id="mp_paid_on" name="paid_on" value="{{ old('paid_on', today()->format('Y-m-d')) }}" required>
                            </div>
                            <div class="field">
                                <label for="mp_method">Method<span class="req" aria-hidden="true">*</span></label>
                                <select id="mp_method" name="method" required>
                                    @foreach (PaymentMethod::cases() as $method)
                                        <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="mp_reference">Reference <span class="opt">(optional)</span></label>
                                <input type="text" id="mp_reference" name="reference" placeholder="Transaction ID, cheque no.">
                            </div>
                            <button type="submit" class="btn btn-success btn-block"><x-icon name="check" /> Mark as paid · {{ Money::format($balance, $cur) }}</button>
                        </div>
                    </form>
                </section>
            @endif

            <section class="panel">
                <div class="panel-head">
                    <h2>Payment history</h2>
                    @if ($balance > 0)
                        <a href="{{ route('invoices.payments.create', $invoice) }}" class="btn btn-sm"><x-icon name="plus" class="icon-sm" /> Payment</a>
                    @endif
                </div>
                @if ($invoice->payments->isEmpty())
                    <x-empty-state icon="wallet" message="No payments recorded yet." />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <tbody>
                                @foreach ($invoice->payments as $payment)
                                    <tr>
                                        <td>
                                            <span class="strong">{{ Money::format($payment->amount, $cur) }}</span>
                                            <span class="secondary">{{ $payment->paid_on->format('j M Y') }} · {{ $payment->method->label() }}@if ($payment->reference) · {{ $payment->reference }}@endif</span>
                                            @if ($payment->allocations->isNotEmpty())
                                                <span class="secondary">→ {{ $payment->allocations->map(fn ($a) => $a->segment->name)->implode(', ') }}</span>
                                            @endif
                                        </td>
                                        <td class="num">
                                            <div class="row-actions">
                                                <a href="{{ route('payments.edit', $payment) }}" class="btn btn-sm btn-ghost" title="Edit payment"><x-icon name="edit" class="icon-sm" /><span class="sr-only">Edit</span></a>
                                                <form method="POST" action="{{ route('payments.destroy', $payment) }}" data-confirm="Remove this payment of {{ Money::format($payment->amount, $cur) }}? The invoice balance and stamp will be recalculated.">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-ghost" title="Remove payment" style="color:var(--danger)"><x-icon name="trash" class="icon-sm" /><span class="sr-only">Remove</span></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($invoice->payments->isEmpty())
                <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" data-confirm="Delete invoice {{ $invoice->number }}? This cannot be undone.">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger btn-sm"><x-icon name="trash" class="icon-sm" /> Delete invoice</button>
                </form>
            @else
                <p class="hint">Invoices with payments can't be deleted. Remove the payments first if this invoice was created by mistake.</p>
            @endif
        </aside>
    </div>
</x-layout>
