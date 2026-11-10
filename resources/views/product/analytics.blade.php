@use('App\Support\Money')
<x-layout title="Sales Analytics">
    <x-page-header title="Sales Analytics">
        <x-slot:meta>Real figures from the order records, limited to the projects you may see. Nothing here posts or recognises anything; commission and client entitlement are the amounts already worked out on each order.</x-slot:meta>
    </x-page-header>

    <section class="panel">
        <form method="GET" class="filters" role="search">
            <div class="field"><label>Client</label><select name="client"><option value="">All</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(request('client') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="field"><label>Project</label><select name="fproject"><option value="">All</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected(request('fproject') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <div class="field"><label>Ordered from</label><input type="date" name="from" value="{{ request('from') }}"></div>
            <div class="field"><label>to</label><input type="date" name="to" value="{{ request('to') }}"></div>
            <button class="btn">Apply</button> <a class="btn" href="{{ route('product.analytics') }}">Clear</a>
        </form>
    </section>

    @include('product._sales-figures', ['a' => $a])

    <section class="panel">
        <div class="panel-head"><h2>Project-wise performance</h2></div>
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Project</th><th class="num">Orders</th><th class="num">Delivered</th><th class="num">Failed</th><th class="num">Cancelled</th><th class="num">Success rate</th><th class="num">Successful sales</th><th class="num">Delivery charges</th><th class="num">Commission</th><th class="num">Client entitlement</th></tr></thead>
            <tbody>
            @forelse ($a['projects'] as $r)
                <tr><td class="primary-cell"><a href="{{ route('product.projects.show', $r['project']) }}">{{ $r['project']->name }}</a></td><td class="num">{{ $r['orders'] }}</td><td class="num">{{ $r['delivered'] }}</td><td class="num">{{ $r['failed'] }}</td><td class="num">{{ $r['cancelled'] }}</td>
                    <td class="num">{{ $r['success_rate_bps'] === null ? '—' : number_format($r['success_rate_bps'] / 100, 1).'%' }}</td><td class="num">{{ Money::format($r['sales']) }}</td><td class="num">{{ Money::format($r['delivery']) }}</td><td class="num">{{ Money::format($r['commission']) }}</td><td class="num">{{ Money::format($r['entitlement']) }}</td></tr>
            @empty
                <tr><td colspan="10" class="muted">No orders.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    @include('product._sales-exceptions', ['exceptions' => $exceptions])
</x-layout>