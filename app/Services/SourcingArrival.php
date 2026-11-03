<?php

namespace App\Services;

use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What physically reached Sellbees's room, per product line. Ordered quantity is never treated as received: until an arrival is
 * recorded the line shows "not recorded", and accepted (usable) quantity can never exceed what was received. The cost boundary of
 * the business is this arrival, so the date is recorded with it.
 */
class SourcingArrival
{
    /**
     * @param  string|null  $accepted  quantity accepted after inspection; omitted keeps the line's current usable quantity
     */
    public static function record(SourcingLotLine $line, string $received, ?string $accepted, string $arrivedOn, User $user, ?string $reference = null): void
    {
        DB::transaction(function () use ($line, $received, $accepted, $arrivedOn, $reference) {
            $lot = SourcingLot::whereKey($line->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            if ($lot->isClosed()) {
                throw new RuntimeException('This lot is '.strtolower($lot->statusLabel()).' and can no longer be changed.');
            }
            $line = $lot->lines()->findOrFail($line->id);
            if (bccomp($received, '0', 2) < 0 || bccomp($received, (string) $line->quantity, 2) > 0) {
                throw new RuntimeException('Received quantity must be between 0 and the purchased quantity of '.$line->quantity.'.');
            }
            if ($arrivedOn > today()->toDateString()) {
                throw new RuntimeException('The arrival date cannot be in the future.');
            }
            $acceptedGiven = $accepted !== null;
            $accepted ??= (string) $line->usable_quantity;
            if (bccomp($accepted, $received, 2) > 0) {
                throw new RuntimeException($acceptedGiven
                    ? 'Accepted quantity cannot exceed the quantity received.'
                    : 'Only '.$received.' arrived, which is below the accepted quantity of '.$line->usable_quantity.'; state the accepted quantity too.');
            }

            $usableChanged = bccomp($accepted, (string) $line->usable_quantity, 2) !== 0;
            if ($usableChanged) {
                // A shortage or damage found at arrival is a fact, not an edit, so it is allowed after pricing approval too (the approved
                // price and cost snapshot stay frozen; the variance against them is shown). It can never drop below what clients were promised.
                if (bccomp((string) $lot->allocations()->where('kind', 'sale')->where('sourcing_lot_line_id', $line->id)->sum('quantity'), $accepted, 2) > 0) {
                    throw new RuntimeException('Accepted quantity cannot drop below the quantity already allocated to clients.');
                }
            }
            $line->update(['received_quantity' => $received, 'arrived_on' => $arrivedOn, 'usable_quantity' => $accepted]
                + (trim((string) $reference) !== '' ? ['acceptance_reference' => trim((string) $reference)] : []));
            if ($usableChanged && $lot->status === 'priced') {
                $lot->update(['status' => 'costing', 'priced_by' => null]);
            }
            Audit::log('sourcing.arrival', 'lot '.$lot->number, $line->description.' received '.$received.' accepted '.$accepted.' on '.$arrivedOn);
        });
    }
}
