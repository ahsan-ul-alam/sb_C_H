@use('App\Support\Money')
<x-layout title="Client Settlements">
    <x-page-header title="Client Settlements">
        <x-slot:meta>What each client is owed after their products sold: collected money, less the commission (and storage fees, where allowed) they owe. Their sourcing cost is never deducted again.</x-slot:meta>
    </x-page-header>
    <section class="panel">
        <div class="table-wrap"><table class="table table-min-760">
            <thead><tr><th>Client</th><th class="num">Delivered sales</th><th class="num">Collected</th><th class="num">Commission due</th><th class="num">Storage due</th><th class="num">Settled so far</th><th class="num">Net payable now</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @php($s = $r['s'])
                <tr><td class="primary-cell"><a href="{{ route('product.settlements.show', $r['client']) }}">{{ $r['client']->name }}</a></td>
                    <td class="num">{{ Money::format($s['delivered_sales']) }}</td><td class="num">{{ Money::format($s['collected']) }}</td><td class="num">{{ Money::format($s['commission_due']) }}</td>
                    <td class="num">{{ Money::format($s['storage_due']) }}</td><td class="num">{{ Money::format($s['settled']) }}</td><td class="num strong">{{ Money::format($s['payable']) }}</td></tr>
            @empty
                <tr><td colspan="7" class="muted">Nothing to settle yet. Collections appear here once customer money is recorded against delivered orders.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</x-layout>
