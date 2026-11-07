<x-layout title="Client expenses">
    <x-page-header title="Client expenses">
        <x-slot:meta>{{ $client->name }} · {{ $client->sb_number }}</x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('clients.profitability', $client) }}" class="btn">Client profitability</a>
            <a href="{{ route('clients.show', $client) }}" class="btn btn-ghost">Back to client</a>
        </x-slot:actions>
    </x-page-header>

    <div class="callout">These are costs the <strong>client</strong> bears, recorded only to report the client's profit. They are never posted to the Sellbees ledger and are not a Sellbees expense or liability. Do not enter the lot's product cost or inbound freight: it is already inside the client's acquisition cost.</div>

    @can('client.expense.record')
    <section class="panel">
        <div class="panel-head"><h2>Enter a client expense</h2></div>
        <div class="panel-body">
            <form method="POST" action="{{ route('clients.expenses.store', $client) }}" class="stack">
                @csrf
                <div class="field-row">
                    <div class="field"><label for="category">Category</label>
                        <select id="category" name="category" required>@foreach (\App\Models\ClientExpense::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>@endforeach</select></div>
                    <div class="field"><label for="amount">Amount</label><input id="amount" name="amount" value="{{ old('amount') }}" inputmode="decimal" required></div>
                    <div class="field"><label for="spent_on">Expense date</label><input type="date" id="spent_on" name="spent_on" value="{{ old('spent_on') }}" required></div>
                </div>
                <div class="field-row">
                    <div class="field"><label for="responsible_party">Borne by</label>
                        <select id="responsible_party" name="responsible_party"><option value="client">Client (counts in client profit)</option><option value="sellbees">Sellbees (recorded, excluded)</option></select></div>
                    <div class="field"><label for="paid_status">Paid?</label>
                        <select id="paid_status" name="paid_status"><option value="paid">Paid</option><option value="unpaid">Unpaid</option></select></div>
                    <div class="field"><label for="reference">Supporting reference</label><input id="reference" name="reference" value="{{ old('reference') }}" required></div>
                </div>
                <div class="field-row">
                    <div class="field"><label for="charge_treatment">Charge classification</label>
                        <select id="charge_treatment" name="charge_treatment"><option value="additional">Additional charge: deducted from client profit</option><option value="included_in_acquisition">Already included in the acquisition price: information only, not deducted</option></select></div>
                    <div class="field"><label for="source_invoice_id">Source invoice id <span class="opt">(required when included in the acquisition price)</span></label><input id="source_invoice_id" name="source_invoice_id" inputmode="numeric"></div>
                </div>
                <div class="field"><label for="description">Description <span class="opt">(optional)</span></label><input id="description" name="description" value="{{ old('description') }}"></div>
                <fieldset class="field"><legend>Allocation (must add up to the amount)</legend>
                    <div class="field-row">
                        <div class="field"><label>Project</label>
                            <select name="allocations[0][project_id]" required>@foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                        <div class="field"><label>Order <span class="opt">(optional)</span></label>
                            <select name="allocations[0][client_order_id]"><option value="">Whole project</option>@foreach ($orders as $o)<option value="{{ $o->id }}">{{ $o->reference }}</option>@endforeach</select></div>
                        <div class="field"><label>Amount</label><input name="allocations[0][amount]" inputmode="decimal" required></div>
                    </div>
                    <p class="muted">To share a cost across projects, enter it once per project through the same form after agreeing the split basis; or record separate lines in the note. A multi-line split needs its basis in the note.</p>
                </fieldset>
                <div class="field-row">
                    <div class="field"><label for="allocation_basis">Split basis</label>
                        <select id="allocation_basis" name="allocation_basis"><option value="direct">Direct</option><option value="sales_value">Sales value</option><option value="units">Units</option><option value="manual">Manual</option></select></div>
                    <div class="field"><label for="allocation_note">Basis note</label><input id="allocation_note" name="allocation_note"></div>
                    <div class="field"><label for="expense_id">Linked ERP expense id <span class="opt">(if the same cost already exists)</span></label><input id="expense_id" name="expense_id" inputmode="numeric"></div>
                </div>
                <div><button type="submit" class="btn btn-primary">Enter expense</button></div>
            </form>
        </div>
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Expenses</h2></div>
        @if ($expenses->isEmpty())
            <x-empty-state icon="wallet" message="No client expenses recorded." />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Date</th><th>Category</th><th>Reference</th><th>Allocation</th><th class="num">Amount</th><th>Borne by</th><th>Status</th><th>History</th><th></th></tr></thead>
                <tbody>
                @foreach ($expenses as $e)
                    <tr>
                        <td class="nowrap">{{ $e->spent_on->format('j M Y') }}</td>
                        <td>{{ \App\Models\ClientExpense::CATEGORIES[$e->category] ?? $e->category }}<span class="secondary">{{ $e->paid_status }}</span></td>
                        <td>{{ $e->reference }}</td>
                        <td>@foreach ($e->allocations as $a){{ $a->project->name }}: {{ \App\Support\Money::format($a->amount) }}<br>@endforeach</td>
                        <td class="num strong">{{ \App\Support\Money::format($e->amount) }}</td>
                        <td>{{ $e->responsible_party }}</td>
                        <td><strong>{{ ucfirst($e->status) }}</strong>@if ($e->reversal_reason)<span class="secondary">{{ $e->reversal_reason }}</span>@endif</td>
                        <td>@foreach ($e->events as $ev)<span class="secondary">{{ $ev->created_at->format('j M H:i') }} {{ $ev->event }} {{ $ev->user?->name }}</span>@endforeach</td>
                        <td class="nowrap">
                            @if ($e->status === 'entered' && auth()->user()->can('client.expense.review'))
                                <form method="POST" action="{{ route('clients.expenses.review', $e) }}">@csrf<button class="btn btn-sm">Review</button></form>
                            @endif
                            @if ($e->status === 'reviewed' && auth()->user()->can('client.expense.approve'))
                                <form method="POST" action="{{ route('clients.expenses.decide', $e) }}">@csrf<input name="note" placeholder="Reason if rejecting">
                                    <button name="decision" value="approve" class="btn btn-sm btn-primary">Approve</button><button name="decision" value="reject" class="btn btn-sm">Reject</button></form>
                            @endif
                            @if (! in_array($e->status, ['reversed', 'rejected'], true) && auth()->user()->can('client.expense.record'))
                                <form method="POST" action="{{ route('clients.expenses.reverse', $e) }}">@csrf<input name="reason" placeholder="Reason" required><button class="btn btn-sm">Reverse</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody></table></div>
        @endif
    </section>
</x-layout>
