<x-layout title="Inventory exceptions">
    <x-page-header title="Inventory exceptions">
        <x-slot:meta>Things that need a person to look. This list only points at records; it never orders stock or posts a transaction.</x-slot:meta>
    </x-page-header>
    @forelse ($exceptions as $type => $items)
        <section class="panel"><div class="panel-head"><h2>{{ $type }} <span class="sub">{{ $items->count() }}</span></h2></div>
            <div class="panel-body"><ul>
                @foreach ($items as $e)
                    <li><a href="{{ $e['param'] ? route($e['route'], $e['param']) : route($e['route']) }}">{{ $e['message'] }}</a></li>
                @endforeach
            </ul></div></section>
    @empty
        <div class="flash flash-success" role="status"><x-icon name="check-circle" /> <div>No exceptions: every accepted unit has a project, a place, an owner and balances that add up.</div></div>
    @endforelse
    @php($warn = $lowStock->where('state', '!=', 'ok'))
    @if ($warn->isNotEmpty())
        <section class="panel"><div class="panel-head"><h2>Low or out of stock <span class="sub">{{ $warn->count() }}</span></h2></div><div class="panel-body"><ul>
            @foreach ($warn as $r)<li>{{ $r['threshold']->product->name }} ({{ $r['threshold']->project?->name ?? 'all projects' }}, {{ $r['threshold']->location?->name ?? 'all locations' }}): {{ $r['quantity'] }} against a minimum of {{ $r['threshold']->minimum_quantity }}</li>@endforeach
        </ul></div></section>
    @endif
</x-layout>
