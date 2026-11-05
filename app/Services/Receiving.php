<?php

namespace App\Services;

use App\Models\SourcingArrivalEvent;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Goods receiving as a history of arrivals. Each arrival is a row (what came, what was accepted, what was rejected or damaged, where it was put,
 * which delivery reference and evidence). The line's received and accepted totals are the sum of its arrivals and are written through
 * SourcingArrival::record, so the existing receipt movement, valuation sync and lot status all stay the single path: this class never writes
 * stock itself. A repeated delivery reference on the same line is refused, which is what stops one arrival adding stock twice.
 */
class Receiving
{
    public static function receive(SourcingLotLine $line, string $received, string $accepted, string $damaged, string $arrivedOn, ?int $locationId, string $reference, ?string $evidence, ?string $notes, User $user): SourcingArrivalEvent
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new RuntimeException('Enter the delivery reference (delivery note or GRN number) so this arrival cannot be recorded twice.');
        }

        return DB::transaction(function () use ($line, $received, $accepted, $damaged, $arrivedOn, $locationId, $reference, $evidence, $notes, $user) {
            SourcingLot::whereKey($line->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $line = SourcingLotLine::findOrFail($line->id);
            if (SourcingArrivalEvent::where('sourcing_lot_line_id', $line->id)->where('reference', $reference)->exists()) {
                throw new RuntimeException('Delivery reference '.$reference.' is already recorded for this product; the stock was not added again.');
            }
            if (bccomp($received, '0', 2) <= 0 || bccomp($accepted, '0', 2) < 0 || bccomp($damaged, '0', 2) < 0) {
                throw new RuntimeException('Enter the quantity received (above zero) and the accepted and damaged quantities.');
            }
            $rejected = bcsub($received, $accepted, 2);
            if (bccomp($rejected, '0', 2) < 0) {
                throw new RuntimeException('Accepted quantity cannot exceed the quantity received.');
            }
            if (bccomp($damaged, $rejected, 2) > 0) {
                throw new RuntimeException('Damaged units are part of the rejected units; damaged cannot exceed '.$rejected.'.');
            }
            $already = $line->received_quantity === null ? '0' : (string) $line->received_quantity;
            $cumulative = bcadd($already, $received, 2);
            if (bccomp($cumulative, (string) $line->quantity, 2) > 0) {
                throw new RuntimeException('That would make '.$cumulative.' received against '.$line->quantity.' ordered; only '.bcsub((string) $line->quantity, $already, 2).' are still expected.');
            }
            $acceptedBefore = $line->received_quantity === null ? '0' : (string) $line->usable_quantity;
            $location = $locationId ? StockLocation::where('is_active', true)->findOrFail($locationId) : StockLocations::default();

            $event = SourcingArrivalEvent::create(['sourcing_lot_line_id' => $line->id, 'reference' => $reference, 'received_quantity' => $received, 'accepted_quantity' => $accepted,
                'rejected_quantity' => $rejected, 'damaged_quantity' => $damaged, 'arrived_on' => $arrivedOn, 'stock_location_id' => $location->id,
                'evidence_reference' => $evidence ? trim($evidence) : null, 'notes' => $notes ? trim($notes) : null, 'created_by' => $user->id]);
            SourcingArrival::record($line, $cumulative, bcadd($acceptedBefore, $accepted, 2), $arrivedOn, $user, $reference, $event->id, $location->id);
            Audit::log('inventory.received', 'line '.$line->id, $reference.': received '.$received.' accepted '.$accepted.' rejected '.$rejected);

            return $event;
        });
    }

    /** What is still expected on a line (never negative; zero once everything ordered has arrived). */
    public static function expected(SourcingLotLine $line): string
    {
        $left = bcsub((string) $line->quantity, (string) ($line->received_quantity ?? 0), 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }
}
