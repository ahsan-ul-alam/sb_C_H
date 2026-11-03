@use('App\Support\Money')
<x-layout title="Other Income">
    <x-page-header title="Other Income">
        <x-slot:meta>Non-trading income such as bank interest or a grant. It adds cash and is reported on its own line; it is not customer revenue, and it is not distributed to partners unless the Board adopts the accounting profit basis.</x-slot:meta>
    </x-page-header>

    @can('income.record')
    <section class="panel">
        <div class="panel-head"><h2>Record other income</h2></div>
        <form method="POST" action="{{ route('finance.other-income.store') }}" class="panel-body">@csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="form-grid">
                <div class="field"><label>Description</label><input name="description" value="{{ old('description') }}" required></div>
                <div class="field"><label>Amount</label><input name="amount" inputmode="decimal" value="{{ old('amount') }}" required></div>
                <div class="field"><label>Received on</label><input type="date" name="received_on" value="{{ old('received_on', today()->toDateString()) }}" required></div>
                <div class="field"><label>Received into</label><select name="financial_account_id"><option value="">Default account</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Record</button>
        </form>
    </section>
    @endcan

    <section class="panel">
        <div class="table-wrap"><table class="table table-min-640">
            <thead><tr><th>Date</th><th>Description</th><th>Account</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($items as $i)
                <tr><td>{{ $i->received_on->format('j M Y') }}</td><td>{{ $i->description }}</td><td class="muted">{{ $i->account?->name }}</td><td class="num">{{ Money::format($i->amount) }}</td><td><x-status :value="$i->status" /></td>
                    <td class="num">
                        @can('income.verify')
                            @if ($i->status === 'pending')
                                @foreach (['verify' => 'btn-success', 'reject' => 'btn-danger'] as $d => $cls)<form method="POST" action="{{ route('finance.other-income.decide', $i) }}" style="display:inline">@csrf<input type="hidden" name="decision" value="{{ $d }}"><button class="btn btn-sm {{ $cls }}">{{ ucfirst($d) }}</button></form>@endforeach
                            @elseif ($i->status === 'verified' && $i->created_by !== auth()->id())
                                <details><summary class="btn btn-sm">Reverse</summary><form method="POST" action="{{ route('finance.other-income.reverse', $i) }}" style="display:grid; gap:.4rem; margin-top:.4rem">@csrf<input name="reason" placeholder="Reason" required><button class="btn btn-sm btn-danger">Reverse</button></form></details>
                            @endif
                        @endcan
                    </td></tr>
            @empty
                <tr><td colspan="6" class="muted">Nothing recorded.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('partials.pagination', ['paginator' => $items])
    </section>
</x-layout>