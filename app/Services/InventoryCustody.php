<?php

namespace App\Services;

use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\SourcingLotLine;
use App\Models\User;
use RuntimeException;

/**
 * Who OWNS goods and where they PHYSICALLY are are two different facts. A handover moves ownership to the client; it does not move the goods.
 * The units of an approved handover are recorded as that handover's custody slice of the lot line's stock movements:
 *
 *   approve (warehouse)   own slice available −q, custody slice available +q    — same physical units, no change to the line's physical total
 *   approve (released)    own slice available −q, own slice delivered +q       — the goods left with the client, the physical count falls
 *   return, resalable     custody −q, own available +q                        — and the cost restored by the return journal
 *   return, damaged       custody −q, own damaged +q                          — held apart from sellable stock until it is classified (Phase 2D-1)
 *
 * Nothing here posts a journal: the cost of goods sold was released once, by the handover; custody movements never create Sellbees COGS or revenue.
 * A whole lot's ownership is never changed by a partial handover. Callers hold the lot's lock (Inventory::move takes it again, which is harmless).
 */
class InventoryCustody
{
    public static function receive(InventoryHandover $handover, SourcingLotLine $line, User $user): void
    {
        $qty = (string) $handover->quantity;
        $reason = 'Handover #'.$handover->id.' to client '.$handover->client_id.' / project '.$handover->project_id;
        if ($handover->custody_mode === 'released') {
            Inventory::move($line, 'handover_out', ['available' => bcmul($qty, '-1', 2), 'delivered' => $qty], 'ho-out:'.$handover->id, null, $user, $reason.': goods left with the client');

            return;
        }
        Inventory::move($line, 'custody_out', ['available' => bcmul($qty, '-1', 2)], 'ho-own:'.$handover->id, null, $user, $reason.': ownership passed', null);
        Inventory::move($line, 'custody_in', ['available' => $qty], 'ho-cust:'.$handover->id, null, $user, $reason.': held in our warehouse for the client', $handover->id);
    }

    /** Units of a handover that can go back to Sellbees now: standing in custody (or at the client, for a released handover), not reserved for an order. */
    public static function returnable(InventoryHandover $handover): string
    {
        $line = $handover->line;

        return $handover->custody_mode === 'released'
            ? Inventory::sliceBalances($line, null)['delivered']
            : Inventory::sliceBalances($line, $handover->id)['available'];
    }

    public static function giveBack(InventoryHandover $handover, InventoryHandoverReturn $return, User $user): void
    {
        $qty = (string) $return->quantity;
        if (bccomp($qty, self::returnable($handover), 2) > 0) {
            throw new RuntimeException('Only '.self::returnable($handover).' of this handover can be taken back now; the rest is reserved for an order or already with the client\'s customers.');
        }
        $line = $handover->line;
        $into = $return->condition === 'damaged' ? 'damaged' : 'available';
        $reason = 'Return #'.$return->id.' of handover #'.$handover->id.' ('.$return->condition.')';
        $key = 'ho-ret:'.$return->id;
        if ($handover->custody_mode === 'released') {
            Inventory::move($line, 'custody_return', ['delivered' => bcmul($qty, '-1', 2), $into => $qty], $key, null, $user, $reason);

            return;
        }
        Inventory::move($line, 'custody_return', ['available' => bcmul($qty, '-1', 2)], $key.':cust', null, $user, $reason, $handover->id);
        Inventory::move($line, 'custody_return', [$into => $qty], $key.':own', null, $user, $reason, null);
    }

    /**
     * The custody slice of one handover against what the handover says is still the client's: every unit is in exactly one place.
     *
     * @return array{handover: int, expected: string, held: array<string, string>, differences: list<string>, ok: bool}
     */
    public static function check(InventoryHandover $handover): array
    {
        $line = $handover->line;
        $expected = $handover->netQuantity();
        $differences = [];
        if ($handover->custody_mode === 'released') {
            $held = ['available' => '0.00', 'reserved' => '0.00', 'dispatched' => '0.00', 'delivered' => '0.00', 'returned' => '0.00', 'damaged' => '0.00'];
            $released = self::releasedAtClient($line);
        } else {
            $b = Inventory::sliceBalances($line, $handover->id);
            $held = array_intersect_key($b, array_flip(Inventory::BUCKETS));
            $slice = '0';
            foreach ($held as $v) {
                $slice = bcadd($slice, $v, 2);
            }
            $released = null;
            if (bccomp($slice, $expected, 2) !== 0) {
                $differences[] = 'Custody units recorded ('.$slice.') differ from what the handover says is still with the client ('.$expected.').';
            }
            foreach ($held as $bucket => $v) {
                if (bccomp($v, '0', 2) < 0) {
                    $differences[] = 'The '.$bucket.' bucket of this custody slice is negative ('.$v.').';
                }
            }
        }

        return ['handover' => $handover->id, 'expected' => $expected, 'held' => $held, 'released_at_client' => $released, 'differences' => $differences, 'ok' => $differences === []];
    }

    /** Units of a line that left with a client at handover and have not come back (the line's own "delivered" bucket). */
    public static function releasedAtClient(SourcingLotLine $line): string
    {
        return Inventory::sliceBalances($line, null)['delivered'];
    }
}
