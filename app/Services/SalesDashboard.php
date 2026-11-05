<?php

namespace App\Services;

use App\Models\ClientOrder;
use App\Models\InventoryHandover;
use Illuminate\Support\Collection;

/**
 * Sales figures for stock orders. Gross order value is the client's merchandise value and is never presented as Sellbees revenue: Sellbees earns
 * the commission on what was delivered and not returned. Cash collected is the client's money held, and COGS appears only where Sellbees sold its
 * own goods to a client (the handover), never on an end-customer delivery.
 */
class SalesDashboard
{
    /**
     * @return array{counts: array<string, int>, gross: int, delivered: int, collected: int, receivable: int, commission: int, cogs: int, by_project: Collection<int, array<string, mixed>>, trend: Collection<string, int>}
     */
    public static function figures(?string $from = null, ?string $to = null): array
    {
        $orders = ClientOrder::query()->where('fulfilment', 'stock')->with(['items', 'collections', 'project'])
            ->when($from, fn ($q, $v) => $q->whereDate('ordered_on', '>=', $v))->when($to, fn ($q, $v) => $q->whereDate('ordered_on', '<=', $v))->get();
        $delivered = $orders->where('delivery_status', 'delivered');
        $collected = (int) $orders->sum(fn ($o) => $o->collections->sum('amount'));
        $deliveredValue = (int) $delivered->sum(fn ($o) => $o->deliveredValue());

        return [
            'counts' => [
                'received' => $orders->count(), 'confirmed' => $orders->where('delivery_status', 'confirmed')->count(),
                'awaiting_dispatch' => $orders->where('delivery_status', 'confirmed')->count(), 'dispatched' => $orders->where('delivery_status', 'dispatched')->count(),
                'delivered' => $delivered->count(), 'returned' => $delivered->filter(fn ($o) => $o->items->contains(fn ($i) => bccomp((string) $i->returned_quantity, '0', 2) > 0))->count(),
                'cancelled' => $orders->where('delivery_status', 'cancelled')->count(), 'failed' => $orders->where('delivery_status', 'failed')->count(),
            ],
            'gross' => (int) $orders->whereNotIn('delivery_status', ['cancelled'])->sum(fn ($o) => $o->items->sum(fn ($i) => $i->amount())),
            'delivered' => $deliveredValue,
            'collected' => $collected,
            'receivable' => max(0, $deliveredValue - (int) $delivered->sum(fn ($o) => $o->collections->sum('amount'))),
            'commission' => (int) $delivered->sum('commission'),
            'cogs' => (int) InventoryHandover::where('status', 'approved')->sum('cogs_value'),
            'by_project' => $orders->groupBy('project_id')->map(fn ($g) => [
                'project' => $g->first()->project, 'orders' => $g->count(), 'delivered_orders' => $g->where('delivery_status', 'delivered')->count(),
                'gross' => (int) $g->whereNotIn('delivery_status', ['cancelled'])->sum(fn ($o) => $o->items->sum(fn ($i) => $i->amount())),
                'delivered' => (int) $g->where('delivery_status', 'delivered')->sum(fn ($o) => $o->deliveredValue()), 'commission' => (int) $g->where('delivery_status', 'delivered')->sum('commission'),
            ])->values(),
            'trend' => $delivered->groupBy(fn ($o) => $o->ordered_on->format('Y-m'))->map(fn ($g) => (int) $g->sum(fn ($o) => $o->deliveredValue()))->sortKeys(),
        ];
    }
}
