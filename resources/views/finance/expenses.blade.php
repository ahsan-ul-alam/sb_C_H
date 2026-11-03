@use('App\Support\Money')
@use('App\Services\ExpenseService')
<x-layout title="Expenses">
    <x-page-header title="Expenses">
        <x-slot:meta>Income comes from ClientHub payments ({{ config('finance.currency') }} projects). Verified expenses are posted to the journal: paid ones reduce the chosen account once; unpaid ones become accounts payable until paid.</x-slot:meta>
    </x-page-header>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Accounts payable (unpaid, verified)</div><div class="value">{{ Money::format($payable, config('finance.currency')) }}</div></div>
    </div>

    @can('expense.record')
    <section class="panel">
        <div class="panel-head"><h2>Record expense</h2></div>
        <form method="POST" action="{{ route('finance.expenses.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Category</label><input name="category" value="{{ old('category') }}" required></div>
                <div class="field"><label>Description</label><input name="description" value="{{ old('description') }}" required></div>
                <div class="field"><label>Amount</label><input name="amount" inputmode="decimal" value="{{ old('amount') }}" required></div>
                <div class="field"><label>Date of expense</label><input type="date" name="spent_on" value="{{ old('spent_on', today()->toDateString()) }}" required></div>
                <div class="field"><label>Type</label>
                    <select name="classification">@foreach (ExpenseService::CLASSIFICATIONS as $k => $label)<option value="{{ $k }}" @selected(old('classification', 'operating') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="field"><label>Project <span class="opt">(required for a direct project cost)</span></label>
                    <select name="project_id"><option value="">—</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id') === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
                <div class="field"><label>Prepaid: spread over how many months?</label><input name="prepaid_months" type="number" min="1" max="60" value="{{ old('prepaid_months') }}" placeholder="only for prepaid expenses"></div>
                <div class="field"><label>Vendor / recipient</label><input name="vendor" value="{{ old('vendor') }}"></div>
                <div class="field"><label>Payment status</label>
                    <select name="payment_status"><option value="paid" @selected(old('payment_status', 'paid') === 'paid')>Paid now</option><option value="unpaid" @selected(old('payment_status') === 'unpaid')>Not yet paid (record a payable)</option></select></div>
                <div class="field"><label>Paid from</label>
                    <select name="financial_account_id"><option value="">Default account</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('financial_account_id') === $a->id)>{{ $a->name }}</option>@endforeach</select></div>
                <div class="field"><label>Payment date</label><input type="date" name="paid_on" value="{{ old('paid_on') }}"></div>
                <div class="field"><label>Supporting document <span class="opt">(upload under Documents first)</span></label>
                    <select name="document_id"><option value="">—</option>@foreach ($documents as $d)<option value="{{ $d->id }}" @selected((int) old('document_id') === $d->id)>{{ $d->name }}</option>@endforeach</select></div>
                <label class="check"><input type="checkbox" name="is_asset" value="1" @checked(old('is_asset'))> Capital purchase (office equipment, other lasting assets) — recorded as an asset, not an operating expense</label>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Record</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="num">Amount</th><th>Status</th><th>Payment</th><th></th></tr></thead>
            <tbody>
            @forelse ($expenses as $e)
                <tr><td>{{ $e->spent_on->format('j M Y') }}</td><td>{{ $e->category }}</td>
                    <td>{{ $e->description }}
                        @if ($e->is_asset) <span class="badge badge-partial">Capital asset</span>@endif
                        @if ($e->classification === 'direct') <span class="badge badge-partial">Direct cost</span>@endif
                        @if ($e->classification === 'prepaid') <span class="badge badge-partial">Prepaid · {{ $e->prepaid_months }} mo</span>@endif
                        @if ($e->reverses_id) <span class="badge badge-partial">Correction ({{ $e->correction_type }})</span>@endif
                        @if ($e->vendor || $e->project)<span class="secondary">{{ collect([$e->vendor, $e->project?->name])->filter()->implode(' · ') }}</span>@endif</td>
                    <td class="num">{{ Money::format($e->amount) }}</td>
                    <td><x-status :value="$e->status" />@if ($e->auto_policy_id)<span class="secondary">Automatic — standing policy #{{ $e->auto_policy_id }}</span>@endif</td>
                    <td>@if ($e->status === 'verified' && ! $e->reverses_id)<x-status :value="$e->payment_status" />@if ($e->outstanding() > 0)<span class="secondary">Owed {{ Money::format($e->outstanding()) }}</span>@endif @if ($e->account)<span class="secondary">{{ $e->account->name }}</span>@endif @endif</td>
                    <td class="num">
                        @if ($e->status === 'pending')
                            @can('expense.verify')
                                @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)
                                    <form method="POST" action="{{ route('finance.expenses.decide', $e) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>
                                @endforeach
                            @endcan
                        @elseif ($e->status === 'verified' && ! $e->reverses_id)
                            @if ($e->outstanding() > 0)
                                @can('expense.pay')
                                <details><summary class="btn btn-sm">Pay</summary>
                                    <form method="POST" action="{{ route('finance.expenses.pay', $e) }}" style="display:grid; gap:.4rem; margin-top:.4rem; min-width:14rem">@csrf
                                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <input name="amount" inputmode="decimal" value="{{ Money::toDecimal($e->outstanding()) }}" aria-label="Amount to pay">
                                        <input type="date" name="paid_on" value="{{ today()->toDateString() }}" aria-label="Payment date">
                                        <select name="financial_account_id" aria-label="Pay from"><option value="">Default account</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                                        <input name="reference" placeholder="Reference" aria-label="Reference">
                                        <button class="btn btn-sm btn-primary">Record payment</button>
                                    </form></details>
                                @endcan
                            @endif
                            @can('expense.record')
                                @if ($e->payment_status !== 'reversed' && $e->payment_status !== 'partial')
                                <details><summary class="btn btn-sm">Reverse</summary>
                                    <form method="POST" action="{{ route('finance.expenses.reverse', $e) }}" style="display:grid; gap:.4rem; margin-top:.4rem; min-width:14rem">@csrf
                                        @if ($e->payment_status === 'paid')
                                            <select name="type" aria-label="What happened"><option value="recording_error">Recording error — money never left</option><option value="refund_received">Refund received</option><option value="credit_note">Credit note (no cash)</option></select>
                                        @endif
                                        <input name="reason" placeholder="Reason" required aria-label="Reason">
                                        <button class="btn btn-sm btn-danger">Request reversal</button>
                                    </form></details>
                                @endif
                            @endcan
                        @endif
                    </td></tr>
                @foreach ($e->payments->where('voided_at', null) as $pay)
                    <tr class="muted"><td colspan="3">↳ Payment {{ $pay->paid_on->format('j M Y') }} {{ $pay->reference }}</td><td class="num">{{ Money::format($pay->amount) }}</td><td colspan="2">{{ $pay->account?->name }}</td>
                        <td class="num">@can('expense.pay')<form method="POST" action="{{ route('finance.expenses.payments.void', $pay) }}" style="display:inline" data-confirm="Void this payment? The payable is restored.">@csrf<button class="btn btn-sm btn-danger">Void</button></form>@endcan</td></tr>
                @endforeach
            @empty
                <tr><td colspan="7" class="muted">No expenses yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $expenses])
    </section>
</x-layout>
