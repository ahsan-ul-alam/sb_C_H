<?php

namespace App\Services;

use App\Models\ClientOrder;

/**
 * The four status families of a customer order, derived from the order's delivery state, its lines and its collections, so they stay separate:
 * confirming an order is not delivering it, delivering it is not collecting the money, and collecting money is not Sellbees revenue.
 * A refund to the customer is a Finance matter that the order module does not record, so "Refunded" is never shown here.
 */
class SalesStatus
{
    /** @return array{order: string, fulfilment: string, payment: string} */
    public static function of(ClientOrder $o): array
    {
        $o->loadMissing('items');
        $delivery = $o->delivery_status;
        $allDelivered = $o->items->isNotEmpty() && $o->items->every(fn ($i) => bccomp((string) $i->delivered_quantity, (string) $i->quantity, 2) >= 0);
        $order = match ($delivery) {
            'pending' => 'Draft', 'confirmed', 'dispatched', 'failed' => 'Confirmed', 'cancelled' => 'Cancelled',
            'delivered' => $allDelivered ? 'Fulfilled' : 'Partially Fulfilled', default => 'Draft',
        };
        $returned = $o->items->contains(fn ($i) => bccomp((string) $i->returned_quantity, '0', 2) > 0);
        $fulfilment = match ($delivery) {
            'pending', 'cancelled' => 'Unreserved', 'confirmed' => 'Reserved', 'dispatched' => 'In Transit', 'failed' => 'Failed',
            'delivered' => $returned ? 'Returned (part)' : 'Delivered', default => 'Unreserved',
        };
        $collected = (int) $o->collections->sum('amount');
        $due = $delivery === 'delivered' ? $o->deliveredValue() : 0;
        $payment = $collected <= 0 ? 'Unpaid' : ($collected >= $due ? 'Paid' : 'Partially Paid');

        return ['order' => $order, 'fulfilment' => $fulfilment, 'payment' => $payment];
    }
}
