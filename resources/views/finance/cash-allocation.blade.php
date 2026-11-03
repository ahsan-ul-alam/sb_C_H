@use('App\Support\Money')
@php($cur = config('finance.currency'))
<x-layout title="Opening Cash Allocation">
    <x-page-header title="Opening Cash Allocation">
        <x-slot:meta>Assigns cash the ledger already holds (posted before financial accounts existed) to Office Cash, Bank, Mobile Banking and so on. It only moves money between accounts: it creates no revenue, profit or capital, and total cash never changes.</x-slot:meta>
    </x-page-header>

    <div class="metrics-auto"><div class="metric"><div class="label">Verified unassigned cash</div><div class="value">{{ Money::format($unassigned, $cur) }}</div><div class="hint">Must be allocated in full, to the cent</div></div>
        @if ($openingCash + $openingBank > 0)<div class="metric"><div class="label">Opening batches recorded</div><div class="value">{{ Money::format($openingCash, $cur) }} cash · {{ Money::format($openingBank, $cur) }} bank</div><div class="hint">Reference only; the total above also includes everything posted since</div></div>@endif
    </div>

    @can('account.manage')
        @if ($unassigned > 0)
        <section class="panel">
            <div class="panel-head"><h2>Prepare an allocation</h2><span class="sub">Enter the real balance of each account from its statement. Do not adjust figures to make them fit: a difference is an error to investigate.</span></div>
            <form method="POST" action="{{ route('finance.cash-allocation.prepare') }}" class="panel-body">@csrf
                <div class="form-grid">
                    @foreach ($accounts as $a)
                        <div class="field"><label for="amt{{ $a->id }}">{{ $a->name }} ({{ $cur }})</label><input id="amt{{ $a->id }}" name="amounts[{{ $a->id }}]" inputmode="decimal" value="{{ old('amounts.'.$a->id) }}" placeholder="0.00"></div>
                    @endforeach
                    <div class="field full"><label>Note / evidence reference</label><input name="note" value="{{ old('note') }}" placeholder="Bank statement dates, cash count sheet…"></div>
                </div>
                <button class="btn btn-primary" style="margin-top:.9rem">Prepare for approval</button>
            </form>
        </section>
        @endif
    @endcan

    <section class="panel">
        <div class="panel-head"><h2>Allocations</h2></div>
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>#</th><th>Prepared</th><th>Plan</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($batches as $b)
                <tr><td>{{ $b->id }}</td><td>{{ $b->created_at->format('j M Y') }}<span class="secondary">{{ $b->preparer?->email }}</span></td>
                    <td>@foreach ($b->lines as $l){{ $l->account->name }}: {{ Money::format($l->amount) }}<br>@endforeach<span class="secondary">{{ $b->note }}</span></td>
                    <td class="num">{{ Money::format($b->unassigned_at_prepare, $cur) }}</td><td><x-status :value="$b->status" /></td>
                    <td class="num">
                        @if ($b->status === 'pending')
                            @can('cashalloc.approve')<form method="POST" action="{{ route('finance.cash-allocation.approve', $b) }}" style="display:inline" data-confirm="Post this allocation as transfers?">@csrf<button class="btn btn-sm btn-success">Approve &amp; post</button></form>@endcan
                            @can('account.manage')<form method="POST" action="{{ route('finance.cash-allocation.cancel', $b) }}" style="display:inline">@csrf<button class="btn btn-sm btn-danger">Cancel</button></form>@endcan
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="6" class="muted">No allocations yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>