@use('App\Services\Ledger')
@use('App\Support\Money')
<x-layout title="Partners">
    <x-page-header title="Partners">
        <x-slot:meta>Investment, ownership, loss and profit ratios are independent. Latest policy: {{ $policy?->name }}.</x-slot:meta>
    </x-page-header>

    @php($own = $policy?->ownership_shares ?? [])
    @php($loss = $policy?->loss_shares ?? [])
    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr>
                <th scope="col">Partner</th><th scope="col">Status</th><th scope="col">User account</th>
                <th scope="col" class="num">Investment target</th><th scope="col" class="num">Ownership</th><th scope="col" class="num">Loss share</th>
                <th scope="col" class="num">Profit-fund ratio</th><th scope="col" class="num">Paid capital</th><th scope="col"><span class="sr-only">Actions</span></th>
            </tr></thead>
            <tbody>
            @foreach ($partners as $p)
                <tr>
                    <td class="primary-cell"><a href="{{ route('finance.partners.show', $p) }}">{{ $p->code }} · {{ $p->name }}</a></td>
                    <td><x-status :value="$p->status" /></td>
                    <td>{{ $linkedUsers[$p->id]->email ?? '—' }}</td>
                    <td class="num">{{ isset($policy->investment_bps[$p->code]) ? number_format($policy->investment_bps[$p->code] / 100, 2).'%' : '—' }}</td>
                    <td class="num">{{ isset($own[$p->code]) ? number_format(100 * $own[$p->code] / array_sum($own), 2).'%' : '—' }}</td>
                    <td class="num">{{ isset($loss[$p->code]) ? number_format(100 * $loss[$p->code] / array_sum($loss), 2).'%' : '—' }}</td>
                    <td class="num">{{ isset($policy->profit_bps[$p->code]) ? number_format($policy->profit_bps[$p->code] / 100, 2).'%' : '—' }}</td>
                    <td class="num">{{ Money::format(Ledger::creditBalance('partner.capital', $p->id)) }}</td>
                    <td><div class="list-actions">
                        <a href="{{ route('finance.partners.show', $p) }}" class="btn btn-sm btn-ghost">View<span class="sr-only"> {{ $p->code }}</span></a>
                        @can('partner.manage')
                            <a href="{{ route('finance.partners.edit', $p) }}" class="btn btn-sm"><x-icon name="edit" class="icon-sm" /> Edit<span class="sr-only"> {{ $p->code }}</span></a>
                        @endcan
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="panel-foot muted">Ownership is exactly one third each (shown rounded). Investment is a funding target, not proof of payment.</div>
    </section>

    @can('partner.manage')
    <section class="panel form-card">
        <div class="panel-head"><h2>Add partner</h2><span class="sub">The current structure has exactly three active founders; ratios are set only in signed policy versions.</span></div>
        <form method="POST" action="{{ route('finance.partners.store') }}" class="panel-body">@csrf
            <div class="form-grid">
                <div class="field"><label for="code">Code</label><input id="code" name="code" maxlength="4" value="{{ old('code') }}" required></div>
                <div class="field"><label for="name">Full name</label><input id="name" name="name" value="{{ old('name') }}" required></div>
                <div class="field"><label for="joined_on">Joined on <span class="opt">(optional)</span></label><input id="joined_on" type="date" name="joined_on" value="{{ old('joined_on') }}"></div>
            </div>
            <button class="btn btn-primary" style="margin-top:.9rem">Create Partner</button>
        </form>
    </section>
    @endcan
</x-layout>
