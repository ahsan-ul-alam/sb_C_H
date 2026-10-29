@use('App\Support\Money')
@use('App\Http\Controllers\OpeningController')
<x-layout title="Opening Balances">
    <x-page-header title="Opening Balances">
        <x-slot:meta>Enter only verified figures from real business records. Finance prepares, a different Board member approves, then Finance posts once. The balancing amount goes to opening equity.</x-slot:meta>
    </x-page-header>

    @can('opening.prepare')
    <section class="panel">
        <div class="panel-head"><h2>New batch</h2></div>
        <form method="POST" action="{{ route('finance.opening.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label>Type</label><select name="type"><option value="opening" @disabled($openingPosted)>Opening balance</option><option value="adjustment">Correction (adjustment)</option></select></div>
                <div class="field"><label>As of</label><input type="date" name="as_of" value="{{ old('as_of') }}" required></div>
                <div class="field"><label>Source / note</label><input name="note" value="{{ old('note') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Create draft</button>
        </form>
    </section>
    @endcan

    @forelse ($batches as $b)
        <section class="panel">
            <div class="panel-head"><div><h2>{{ ucfirst($b->type) }} as of {{ $b->as_of->format('j M Y') }}</h2><div class="sub">{{ $b->note }}</div></div>
                <span class="badge badge-{{ $b->status === 'posted' ? 'paid' : 'partial' }}"><x-status :value="$b->status" /></span></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Item</th><th>Partner</th><th class="num">Amount</th><th>Note</th></tr></thead>
                <tbody>
                @foreach ($b->lines as $l)
                    <tr><td>{{ OpeningController::KINDS[$l->kind][0] }}</td><td>{{ $partners->firstWhere('id', $l->partner_id)?->code ?? '—' }}</td><td class="num">{{ Money::format($l->amount) }}</td><td>{{ $l->note }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="panel-foot">
                @if ($b->status === 'draft')
                    @can('opening.prepare')
                    <form method="POST" action="{{ route('finance.opening.lines', $b) }}" style="display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.5rem">@csrf
                        <select name="kind">@foreach (OpeningController::KINDS as $k => $def)<option value="{{ $k }}">{{ $def[0] }}</option>@endforeach</select>
                        <select name="partner_id"><option value="">partner (if needed)</option>@foreach ($partners as $p)<option value="{{ $p->id }}">{{ $p->code }}</option>@endforeach</select>
                        <input name="amount" placeholder="amount" inputmode="decimal" required><input name="note" placeholder="source document">
                        <button class="btn btn-sm">Add line</button>
                    </form>
                    @endcan
                    @can('opening.approve')
                        <form method="POST" action="{{ route('finance.opening.approve', $b) }}" style="display:inline">@csrf<button class="btn btn-sm btn-success">Approve batch</button></form>
                    @endcan
                @elseif ($b->status === 'approved')
                    @can('opening.prepare')
                        <form method="POST" action="{{ route('finance.opening.post', $b) }}" data-confirm="Post once to the ledger?" style="display:inline">@csrf<button class="btn btn-sm btn-primary">Post to ledger</button></form>
                    @endcan
                @endif
                @if (in_array($b->status, ['draft', 'approved']))
                    @can('opening.prepare')
                        <form method="POST" action="{{ route('finance.opening.cancel', $b) }}" style="display:inline">@csrf<button class="btn btn-sm btn-danger">Cancel</button></form>
                    @endcan
                @endif
            </div>
        </section>
    @empty
        <section class="panel"><div class="panel-body muted">No opening balances recorded yet.</div></section>
    @endforelse
</x-layout>
