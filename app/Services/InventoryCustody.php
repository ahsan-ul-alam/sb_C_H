<?php

namespace App\Services;

use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\SourcingLotLine;
use App\Models\User;
use RuntimeException;

/**
 * Who OWNS goods and where they PHYSICALLY are are two different facts. A handover moves ownership to the client; it does not move the goods.
 * The units of an approved handover are recorded as that handover's own slice of the lot line's stock movements:
 *
 *   approve (warehouse)   own slice available -q, the handover's slice available +q: the same physical units, no change to the line's physical total
 *   approve (released)    own slice available -q, the handover's slice delivered +q: the goods left with the client, so the physical count falls
 *   return, resalable     the handover's slice -q, own available +q (and the cost restored by the return journal)
 *   return, damaged       the handover's slice -q, own damaged +q (held apart from sellable stock until a loss case classifies it)
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
        Inventory::move($line, 'custody_out', ['available' => bcmul($qty, '-1', 2)], 'ho-own:'.$handover->id, null, $user, $reason.': ownership passed', null);
        if ($handover->custody_mode === 'released') {
            // The goods left with the client: the physical count falls, and the units stay traceable to THIS handover in its own slice (the delivered bucket).
            Inventory::move($line, 'handover_out', ['delivered' => $qty], 'ho-out:'.$handover->id, null, $user, $reason.': goods left with the client', $handover->id);

            return;
        }
        Inventory::move($line, 'custody_in', ['available' => $qty], 'ho-cust:'.$handover->id, null, $user, $reason.': held in our warehouse for the client', $handover->id);
    }

    /** Units of this handover that can go back to Sellbees now: standing in ITS slice (never another handover's, never the line's aggregate) and not reserved for an order. */
    public static function returnable(InventoryHandover $handover): string
    {
        $slice = Inventory::sliceBalances($handover->line, $handover->id);

        return $handover->custody_mode === 'released' ? $slice['delivered'] : $slice['available'];
    }

    public static function giveBack(InventoryHandover $handover, InventoryHandoverReturn $return, User $user): void
    {
        $qty = (string) $return->quantity;
        if ((int) $return->inventory_handover_id !== (int) $handover->id) {
            throw new RuntimeException('That return belongs to another handover.');
        }
        if (bccomp($qty, self::returnable($handover), 2) > 0) {
            throw new RuntimeException('Only '.self::returnable($handover).' of this handover can be taken back now; the rest is reserved for an order, already with the client\'s customers or already returned.');
        }
        $line = $handover->line;
        $into = $return->condition === 'damaged' ? 'damaged' : 'available';
        $reason = 'Return #'.$return->id.' of handover #'.$handover->id.' ('.$return->condition.')';
        $key = 'ho-ret:'.$return->id;
        $from = $handover->custody_mode === 'released' ? 'delivered' : 'available';
        Inventory::move($line, 'custody_return', [$from => bcmul($qty, '-1', 2)], $key.':cust', null, $user, $reason, $handover->id);
        Inventory::move($line, 'custody_return', [$into => $qty], $key.':own', null, $user, $reason, null);
    }

    /**
     * The slice of one handover against what the handover says is still the client's: every unit is in exactly one place.
     *
     * @return array{handover: int, expected: string, held: array<string, string>, differences: list<string>, ok: bool}
     */
    public static function check(InventoryHandover $handover): array
    {
        $expected = $handover->netQuantity();
        $differences = [];
        $held = array_intersect_key(Inventory::sliceBalances($handover->line, $handover->id), array_flip(Inventory::BUCKETS));
        $slice = '0';
        foreach ($held as $bucket => $v) {
            $slice = bcadd($slice, $v, 2);
            if (bccomp($v, '0', 2) < 0) {
                $differences[] = 'The '.$bucket.' bucket of this slice is negative ('.$v.').';
            }
        }
        if (bccomp($slice, $expected, 2) !== 0) {
            $differences[] = 'Units recorded for this handover ('.$slice.') differ from what the handover says is still with the client ('.$expected.').';
        }

        return ['handover' => $handover->id, 'expected' => $expected, 'held' => $held, 'differences' => $differences, 'ok' => $differences === []];
    }
}
