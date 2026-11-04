<?php

namespace App\Services;

use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\JournalEntry;
use App\Models\SourcingAllocation;
use App\Models\SourcingLot;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transfer of control of Sellbees-owned goods to a client project: the event that turns inventory into cost of goods sold.
 *
 *   propose   a Project Operations / Inventory user records that control passed, with the evidence (signed receiving document or contractual
 *             handover record), the date control actually passed and the quantity. Nothing is posted: not an invoice, a payment or an allocation, and not this.
 *   approve   a DIFFERENT authorised user decides. Only now, and only if the goods are Sellbees-owned and fully valued, the allocation is valid, enough
 *             untransferred quantity exists, nothing is reserved, evidence is on record and the month is open, is the cost released:
 *                 Dr cogs / Cr assets.inventory        for the exact integer value removed from the lot line's purchase and landed layers
 *             and the quantity, value and journal reference are written in one transaction as immutable value movements.
 *   return    goods coming back, under evidence and a second approver. Resalable goods restore the ORIGINAL cost (Dr inventory / Cr cogs, dated in an
 *             open period); damaged goods restore nothing and post nothing, and wait for the loss classification of a later phase.
 *
 * Client-owned custody stock never comes through here, and no revenue or credit note is touched (Phase 3).
 */
class InventoryHandoverService
{
    /** The allocated quantity that can still be handed over: allocated, less what is pending or still with the client. */
    public static function eligibleQuantity(SourcingAllocation $allocation, ?int $exceptHandoverId = null): string
    {
        $committed = '0';
        foreach (InventoryHandover::where('sourcing_allocation_id', $allocation->id)->whereIn('status', ['pending', 'approved'])->when($exceptHandoverId, fn ($q) => $q->whereKeyNot($exceptHandoverId))->get() as $h) {
            $committed = bcadd($committed, $h->status === 'pending' ? (string) $h->quantity : $h->netQuantity(), 2);
        }
        $left = bcsub((string) $allocation->quantity, $committed, 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    public static function propose(SourcingAllocation $allocation, string $quantity, string $proposedOn, string $transferredOn, string $evidenceReference, ?string $evidenceNote, string $key, User $user): InventoryHandover
    {
        if ($existing = InventoryHandover::where('idempotency_key', $key)->first()) {
            return $existing; // the same submission twice records one request
        }
        try {
            return DB::transaction(function () use ($allocation, $quantity, $proposedOn, $transferredOn, $evidenceReference, $evidenceNote, $key, $user) {
                $lot = SourcingLot::whereKey($allocation->sourcing_lot_id)->lockForUpdate()->firstOrFail();
                $allocation = SourcingAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
                if ($allocation->kind !== 'sale' || $allocation->sourcing_lot_line_id === null) {
                    throw new RuntimeException('Only a product allocation to a client project can be handed over.');
                }
                self::assertOwnership($lot);
                if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $quantity) || bccomp($quantity, '0', 2) <= 0) {
                    throw new RuntimeException('Enter a quantity greater than zero.');
                }
                self::assertEvidence($evidenceReference);
                if ($transferredOn > today()->toDateString()) {
                    throw new RuntimeException('Control cannot have passed on a future date.');
                }
                if (bccomp($quantity, self::eligibleQuantity($allocation), 2) > 0) {
                    throw new RuntimeException('Only '.self::eligibleQuantity($allocation).' of this allocation can still be handed over (the rest is already handed over or awaiting approval).');
                }
                $line = $lot->lines()->findOrFail($allocation->sourcing_lot_line_id);
                $handover = InventoryHandover::create(['project_id' => $allocation->project_id, 'client_id' => $allocation->project->client_id, 'sourcing_allocation_id' => $allocation->id,
                    'sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $line->id, 'supplier_product_id' => $line->supplier_product_id, 'quantity' => $quantity, 'proposed_on' => $proposedOn,
                    'transferred_on' => $transferredOn, 'evidence_reference' => trim($evidenceReference), 'evidence_note' => $evidenceNote, 'prepared_by' => $user->id, 'idempotency_key' => $key]);
                Audit::log('inventory.handover_proposed', 'lot '.$lot->number, '#'.$handover->id.' '.$quantity.' of '.$line->description.' ('.trim($evidenceReference).')');

                return $handover->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return InventoryHandover::where('idempotency_key', $key)->firstOrFail();
        }
    }

    public static function cancel(InventoryHandover $handover, User $user, string $reason): void
    {
        DB::transaction(function () use ($handover, $user, $reason) {
            $handover = InventoryHandover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            if ($handover->status !== 'pending') {
                throw new RuntimeException('Only a request that has not been decided can be cancelled; an approved handover is undone by a return.');
            }
            if ($handover->prepared_by !== $user->id && ! $user->can('inventory.handover.approve')) {
                throw new RuntimeException('Only the person who proposed it, or an approver, can cancel this request.');
            }
            if (trim($reason) === '') {
                throw new RuntimeException('Give a reason for cancelling.');
            }
            $handover->update(['status' => 'cancelled', 'decision_note' => trim($reason)]);
            Audit::log('inventory.handover_cancelled', 'handover '.$handover->id, trim($reason));
        });
    }

    public static function decide(InventoryHandover $handover, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($handover, $approve, $user, $note) {
            $lot = SourcingLot::whereKey($handover->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $allocation = SourcingAllocation::whereKey($handover->sourcing_allocation_id)->lockForUpdate()->firstOrFail();
            $handover = InventoryHandover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
            if ($handover->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($handover->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve a handover you proposed.');
            }
            if (! $user->can('inventory.handover.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide a handover.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $handover->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim($note)]);
                Audit::log('inventory.handover_rejected', 'handover '.$handover->id, trim($note));

                return;
            }

            // Everything below is re-checked on the facts as they are now, with the lot and allocation locked.
            if (JournalEntry::where('source_type', 'inventory_handover')->where('source_id', $handover->id)->exists()) {
                throw new RuntimeException('This handover has already been posted.');
            }
            self::assertOwnership($lot);
            self::assertEvidence($handover->evidence_reference);
            if ($allocation->kind !== 'sale' || (int) $allocation->project_id !== (int) $handover->project_id || (int) $allocation->sourcing_lot_line_id !== (int) $handover->sourcing_lot_line_id) {
                throw new RuntimeException('The allocation no longer matches this handover.');
            }
            if (bccomp((string) $handover->quantity, self::eligibleQuantity($allocation, $handover->id), 2) > 0) {
                throw new RuntimeException('Only '.self::eligibleQuantity($allocation, $handover->id).' of the allocation can still be handed over.');
            }
            $line = $lot->lines()->findOrFail($handover->sourcing_lot_line_id);
            InventoryValuation::assertIssuable($line);
            if (bccomp((string) $handover->quantity, InventoryValuation::lineFigures($line)['quantity'], 2) > 0) {
                throw new RuntimeException('Only '.InventoryValuation::lineFigures($line)['quantity'].' of this line is valued and available to hand over.');
            }
            if (bccomp(Inventory::balances($line)['reserved'], '0', 2) > 0) {
                throw new RuntimeException('Stock of this line is reserved for an order; release the reservation before handing it over.');
            }
            $date = $handover->transferred_on->toDateString();
            if ($date > today()->toDateString()) {
                throw new RuntimeException('Control cannot have passed on a future date.');
            }
            AccountingService::assertOpen($date, 'this handover');

            // The exact integer value taken out of the line's layers: the journal and the movements use the same figures, never a recomputed unit cost.
            $cost = InventoryValuation::issueCost($line, (string) $handover->quantity);
            $total = $cost['purchase'] + $cost['landed'];
            if ($total <= 0) {
                throw new RuntimeException('This line holds no value to release; value it before handing it over.');
            }
            $entry = Ledger::post($date, 'Cost of goods sold: handover #'.$handover->id.' of '.$handover->quantity.' '.$line->description.' ('.$lot->number.')', 'inventory_handover', $handover->id, [
                ['cogs', null, $total, 0], ['assets.inventory', null, 0, $total],
            ], $user->id);
            InventoryValuation::recordIssue($handover, $line, $cost['purchase'], $cost['landed'], $entry->id, $user);
            $handover->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null,
                'purchase_value' => $cost['purchase'], 'landed_value' => $cost['landed'], 'cogs_value' => $total, 'journal_entry_id' => $entry->id]);
            Audit::log('inventory.handover_approved', 'handover '.$handover->id, $handover->quantity.' of '.$line->description.', cost '.$total);
        });
    }

    public static function proposeReturn(InventoryHandover $handover, string $quantity, string $condition, string $returnedOn, string $evidenceReference, string $reason, string $key, User $user): InventoryHandoverReturn
    {
        if ($existing = InventoryHandoverReturn::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($handover, $quantity, $condition, $returnedOn, $evidenceReference, $reason, $key, $user) {
                $handover = InventoryHandover::whereKey($handover->id)->lockForUpdate()->firstOrFail();
                if ($handover->status !== 'approved') {
                    throw new RuntimeException('Only an approved handover can have goods returned.');
                }
                if (! in_array($condition, ['resalable', 'damaged'], true)) {
                    throw new RuntimeException('State whether the returned goods are resalable or damaged.');
                }
                if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $quantity) || bccomp($quantity, '0', 2) <= 0) {
                    throw new RuntimeException('Enter a quantity greater than zero.');
                }
                self::assertEvidence($evidenceReference);
                if (trim($reason) === '') {
                    throw new RuntimeException('Give the reason for the return.');
                }
                if ($returnedOn > today()->toDateString()) {
                    throw new RuntimeException('The return cannot be dated in the future.');
                }
                if ($returnedOn < $handover->transferred_on->toDateString()) {
                    throw new RuntimeException('The goods cannot come back before control passed to the client ('.$handover->transferred_on->format('j M Y').').');
                }
                $pending = (string) $handover->returns()->where('status', 'pending')->sum('quantity');
                $room = bcsub($handover->netQuantity(), bcadd($pending, '0', 2), 2);
                if (bccomp($quantity, $room, 2) > 0) {
                    throw new RuntimeException('Only '.(bccomp($room, '0', 2) > 0 ? $room : '0.00').' of this handover is still with the client and not already in a return.');
                }
                $return = InventoryHandoverReturn::create(['inventory_handover_id' => $handover->id, 'quantity' => $quantity, 'condition' => $condition, 'returned_on' => $returnedOn,
                    'evidence_reference' => trim($evidenceReference), 'reason' => trim($reason), 'prepared_by' => $user->id, 'idempotency_key' => $key]);
                Audit::log('inventory.return_proposed', 'handover '.$handover->id, $quantity.' '.$condition.': '.trim($reason));

                return $return->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return InventoryHandoverReturn::where('idempotency_key', $key)->firstOrFail();
        }
    }

    public static function cancelReturn(InventoryHandoverReturn $return, User $user, string $reason): void
    {
        DB::transaction(function () use ($return, $user, $reason) {
            $return = InventoryHandoverReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'pending') {
                throw new RuntimeException('Only a return that has not been decided can be cancelled.');
            }
            if ($return->prepared_by !== $user->id && ! $user->can('inventory.handover.approve')) {
                throw new RuntimeException('Only the person who proposed it, or an approver, can cancel this return.');
            }
            if (trim($reason) === '') {
                throw new RuntimeException('Give a reason for cancelling.');
            }
            $return->update(['status' => 'cancelled', 'decision_note' => trim($reason)]);
        });
    }

    public static function decideReturn(InventoryHandoverReturn $return, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($return, $approve, $user, $note) {
            $handover = InventoryHandover::whereKey(InventoryHandoverReturn::findOrFail($return->id)->inventory_handover_id)->lockForUpdate()->firstOrFail();
            SourcingLot::whereKey($handover->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $return = InventoryHandoverReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($return->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve a return you proposed.');
            }
            if (! $user->can('inventory.handover.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide a return.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $return->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim($note)]);

                return;
            }
            self::assertEvidence($return->evidence_reference);
            if ($handover->status !== 'approved' || bccomp((string) $return->quantity, $handover->netQuantity(), 2) > 0) {
                throw new RuntimeException('That quantity is no longer with the client under this handover.');
            }

            if ($return->condition === 'damaged') {
                // Control comes back but the goods are damaged: they are NOT resalable stock and no cost is restored. The original cost of goods sold stays
                // until the loss or supplier claim is classified (a later phase); the quantity is recorded so nothing is hidden.
                $handover->update(['damaged_returned_quantity' => bcadd((string) $handover->damaged_returned_quantity, (string) $return->quantity, 2)]);
                $return->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null, 'purchase_value' => 0, 'landed_value' => 0, 'restored_value' => 0]);
                Audit::log('inventory.return_damaged', 'handover '.$handover->id, $return->quantity.' damaged, no cost restored');

                return;
            }

            // Resalable goods come back at the ORIGINAL cost. The restored value is the difference of two cumulative shares of the handover's recorded value, so
            // partial returns add up to exactly the value released and a full return restores all of it.
            $before = (string) $handover->returned_quantity;
            $after = bcadd($before, (string) $return->quantity, 2);
            $share = fn (int $value, string $units): int => bccomp($units, (string) $handover->quantity, 2) === 0 ? $value : (int) bcadd(bcdiv(bcmul((string) $value, $units, 2), (string) $handover->quantity, 6), '0.5', 0);
            $purchase = $share((int) $handover->purchase_value, $after) - $share((int) $handover->purchase_value, $before);
            $landed = $share((int) $handover->landed_value, $after) - $share((int) $handover->landed_value, $before);
            $restored = $purchase + $landed;
            if ($restored <= 0) {
                throw new RuntimeException('There is no cost to restore for this quantity.');
            }
            // The correction goes into an open period: the original handover's journal is never touched, even when its month is closed.
            $closed = AccountingService::isClosed($return->returned_on->toDateString());
            $date = $closed ? today()->toDateString() : $return->returned_on->toDateString();
            AccountingService::assertOpen($date, 'this return');
            $line = SourcingLot::findOrFail($handover->sourcing_lot_id)->lines()->findOrFail($handover->sourcing_lot_line_id);
            $entry = Ledger::post($date, 'Goods returned (resalable): handover #'.$handover->id.' cost restored'.($closed ? ' (original period closed; corrected in the open period)' : ''), 'inventory_handover_return', $return->id, [
                ['assets.inventory', null, $restored, 0], ['cogs', null, 0, $restored],
            ], $user->id);
            InventoryValuation::recordReturn($return, $handover, $line, $purchase, $landed, $entry->id, $date, $user);
            $handover->update(['returned_quantity' => $after]);
            $return->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null,
                'purchase_value' => $purchase, 'landed_value' => $landed, 'restored_value' => $restored, 'journal_entry_id' => $entry->id]);
            Audit::log('inventory.return_approved', 'handover '.$handover->id, $return->quantity.' resalable, cost restored '.$restored);
        });
    }

    private static function assertOwnership(SourcingLot $lot): void
    {
        if ($lot->ownership !== 'company' || ! InventoryEngine::isValued($lot)) {
            throw new RuntimeException('Only Sellbees-owned stock that the inventory engine values can be handed over here. Client-owned stock is custody only, and a lot whose ownership is not classified through an approved enrolment is outside the engine.');
        }
    }

    private static function assertEvidence(string $reference): void
    {
        if (mb_strlen(trim($reference)) < 5) {
            throw new RuntimeException('Give the evidence that control passed (a signed receiving document or contractual handover record). An invoice or a payment is not evidence of handover.');
        }
    }
}
