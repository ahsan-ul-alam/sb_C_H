@use('App\Support\Money')
@use('App\Models\FinancialAccount')
@php($cur = config('finance.currency'))
<x-layout title="Cash & Bank Accounts">
    <x-page-header title="Cash &amp; Bank Accounts">
        <x-slot:meta>A balance is the sum of the journal's cash lines for that account, so it always agrees with the ledger. Transfers move money between accounts and never change total cash.</x-slot:meta>
    </x-page-header>

    <div class="metrics-auto">
        <div class="metric"><div class="label">Total available cash</div><div class="value">{{ Money::format($total, $cur) }}</div></div>
    </div>

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Account</th><th>Type</th><th class="num">Inflows</th><th class="num">Outflows</th><th class="num">Balance</th></tr></thead>
            <tbody>
            @forelse ($balances as $b)
                <tr><td>{{ $b['name'] }}@if ($b['account']?->is_default) <span class="badge badge-paid">Default</span>@endif @if ($b['account'] && ! $b['account']->is_active) <span class="badge badge-unpaid">Inactive</span>@endif</td>
                    <td class="muted">{{ $b['type'] }}</td><td class="num">{{ Money::format($b['inflow']) }}</td><td class="num">{{ Money::format($b['outflow']) }}</td>
                    <td class="num strong {{ $b['balance'] < 0 ? 'text-danger' : '' }}">{{ Money::format($b['balance'], $cur) }}</td></tr>
            @empty
                <tr><td colspan="5" class="muted">No accounts yet. Create the first one below; it becomes the default for new cash movements.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @can('account.manage')
    <div class="grid grid-2">
        <section class="panel">
            <div class="panel-head"><h2>New account</h2></div>
            <form method="POST" action="{{ route('finance.accounts.store') }}" class="panel-body">@csrf
                <div class="form-grid">
                    <div class="field"><label>Name</label><input name="name" value="{{ old('name') }}" placeholder="Office cash, City Bank, bKash…" required></div>
                    <div class="field"><label>Type</label><select name="type">@foreach (FinancialAccount::TYPES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
                    <div class="field full"><label>Notes</label><input name="notes" value="{{ old('notes') }}"></div>
                </div>
                <button class="btn btn-primary" style="margin-top:.9rem">Create</button>
            </form>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Manage accounts</h2></div>
            <div class="table-wrap"><table class="table">
                <tbody>
                @foreach ($accounts as $a)
                    <tr><td colspan="2">
                        <form method="POST" action="{{ route('finance.accounts.update', $a) }}" style="display:flex; gap:.6rem; align-items:center; flex-wrap:wrap">@csrf @method('PUT')
                            <input name="name" value="{{ $a->name }}" aria-label="Account name" style="max-width:12rem">
                            <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($a->is_active)> Active</label>
                            <label class="check"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked($a->is_default)> Default</label>
                            <label class="check"><input type="hidden" name="allow_negative" value="0"><input type="checkbox" name="allow_negative" value="1" @checked($a->allow_negative)> Allow overdraft</label>
                            <button class="btn btn-sm">Save</button>
                        </form>
                    </td></tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
    </div>
    @endcan

    @can('transfer.record')
    <section class="panel">
        <div class="panel-head"><h2>Transfer between accounts</h2><span class="sub">"Unassigned" is cash posted before accounts existed; move it into the right account here.</span></div>
        <form method="POST" action="{{ route('finance.transfers.store') }}" class="panel-body">@csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="form-grid">
                <div class="field"><label>From</label><select name="from"><option value="">Unassigned (legacy cash)</option>@foreach ($accounts->where('is_active', true) as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                <div class="field"><label>To</label><select name="to"><option value="">Unassigned (legacy cash)</option>@foreach ($accounts->where('is_active', true) as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
                <div class="field"><label>Amount ({{ $cur }})</label><input name="amount" inputmode="decimal" required></div>
                <div class="field"><label>Date</label><input type="date" name="date" value="{{ today()->toDateString() }}" required></div>
                <div class="field"><label>Reference</label><input name="reference"></div>
                <div class="field"><label>Notes</label><input name="notes"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Transfer</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Recent transfers</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>From</th><th>To</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            @forelse ($transfers as $t)
                <tr><td>{{ $t->transferred_on->format('j M Y') }}</td><td>{{ $t->from?->name ?? 'Unassigned' }}</td><td>{{ $t->to?->name ?? 'Unassigned' }}</td><td>{{ $t->reference }}</td>
                    <td class="num">{{ Money::format($t->amount) }}</td>
                    <td class="num">
                        @if ($t->isVoided())
                            <x-status value="voided" />
                        @else
                            @can('transfer.void')
                                <form method="POST" action="{{ route('finance.transfers.void', $t) }}" style="display:inline" data-confirm="Void this transfer? A reversing entry is posted.">@csrf<button class="btn btn-sm btn-danger">Void</button></form>
                            @endcan
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="6" class="muted">No transfers yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
