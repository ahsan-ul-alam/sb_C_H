<x-layout title="Reservations">
    <x-page-header title="Reservations">
        <x-slot:meta>Stock held for confirmed and dispatched orders. A reservation lowers availability, not physical stock. Cancel the order (Orders &amp; Delivery) to release it; reservations older than {{ \App\Services\InventoryExceptions::STALE_RESERVATION_DAYS }} days are flagged.</x-slot:meta>
    </x-page-header>
    <section class="panel"><div class="table-wrap"><table class="table table-min-760">
        <thead><tr><th>Order</th><th>Project / client</th><th>Product / lot</th><th class="num">Quantity</th><th>Held since</th><th>State</th></tr></thead>
        <tbody>
        @forelse ($orders as $o)
            @foreach ($o->items as $i)
                <tr>
                    <td><a href="{{ route('product.orders', ['project' => $o->project_id]) }}">{{ $o->reference }}</a></td>
                    <td>{{ $o->project?->name }} <span class="muted">{{ $o->project?->client?->name }}</span></td>
                    <td class="primary-cell">{{ $i->description }}<span class="secondary">{{ $i->line?->lot?->number }}</span></td>
                    <td class="num">{{ $i->quantity }}</td>
                    <td>{{ $o->updated_at->format('j M Y') }}</td>
                    <td>@if ($o->delivery_status === 'dispatched') <span class="badge badge-archived">Dispatched</span>
                        @elseif ($o->updated_at < $stale) <span class="badge badge-unpaid">Stale</span>
                        @else <span class="badge badge-partial">Reserved</span> @endif</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="6" class="muted">No stock is reserved.</td></tr>
        @endforelse
        </tbody>
    </table></div></section>
</x-layout>
