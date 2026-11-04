<?php

namespace App\Services;

use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\InventoryLossCase;
use App\Models\JournalEntry;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Damage, impairment and write-off of inventory, as one case taken through three separate people and one exact accounting treatment.
 *
 *   propose   Inventory/Operations records the physical finding: which units, where they stand, why, the evidence, the condition and a proposed recoverable value.
 *             No recoverable value means "valuation pending": the units stay quarantined, nothing is recognised and nothing is released to sale. A value is never assumed.
 *   review    Finance (not the proposer) checks the evidence and the treatment, and supplies or confirms the recoverable value, a verified zero included, with its basis.
 *   decide    a Board member (neither of the two) approves or rejects. Only now is anything posted, on the facts as they are with the lot locked.
 *
 * What an approval does, by where the units come from (C = carrying value, R = verified recoverable value, L = C - R):
 *   owned     Sellbees stock still in the cost layers. The units and exactly C leave the layers (append-only value movements).
 *               write-off   Dr loss / Cr inventory C          (abnormal: inventory_loss; normal: cogs, as part of the cost of goods)
 *               impaired    Dr inventory_impaired R, Dr loss L / Cr inventory C   (the units are held apart at R, outside the lot's cost layers, never at full cost)
 *   return    A damaged return whose control came back. The sale reversal is valid, so the original cost of goods sold is reversed for these units, the returned units are
 *             recognised at R and the loss is recognised separately: Dr inventory_impaired R, Dr inventory_loss L / Cr cogs C. A NORMAL loss stays in cost of goods sold,
 *             so only R is reversed. No revenue, invoice or credit note is touched (Phase 3).
 *   custody   Client-owned stock damaged while in our warehouse. An incident only: the units move to the custody slice's damaged bucket and NOTHING is posted, because
 *             Sellbees has no inventory, cost or loss for goods it does not own. A liability would need an enforceable obligation and an authorised treatment.
 *
 * An event in a closed month is never backdated: it is posted in the open month and flagged as a prior-period event for Finance and Board attention.
 */
class InventoryLossService
{
    public const OUTCOMES = ['repairable', 'impaired', 'write_off'];

    private const EVIDENCE_MIN = 5;

    /** Units of a slice that are already in another open case. */
    private static function committed(array $where, ?int $exceptId = null): string
    {
        $q = InventoryLossCase::whereIn('status', InventoryLossCase::ACTIVE);
        foreach ($where as $column => $value) {
            is_array($value) ? $q->whereIn($column, $value) : $q->where($column, $value);
        }

        return bcadd((string) $q->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('quantity'), '0', 2);
    }

    /**
     * @param  array<string, mixed>  $data  scope (owned|return|custody), quantity, outcome, classification, cause, evidence_reference, assessment_note, recoverable_value, valuation_basis, event_on,
     *                                      and per scope: sourcing_lot_line_id + from_bucket | inventory_handover_return_id | inventory_handover_id
     */
    public static function propose(User $user, array $data, string $key): InventoryLossCase
    {
        if ($existing = InventoryLossCase::where('idempotency_key', $key)->first()) {
            return $existing; // the same submission twice records one case
        }
        try {
            return DB::transaction(function () use ($user, $data, $key) {
                $scope = (string) ($data['scope'] ?? '');
                $quantity = (string) ($data['quantity'] ?? '');
                if (! in_array($scope, ['owned', 'return', 'custody'], true)) {
                    throw new RuntimeException('State whether this is Sellbees stock, a damaged return or client-owned stock in custody.');
                }
                if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $quantity) || bccomp($quantity, '0', 2) <= 0) {
                    throw new RuntimeException('Enter a quantity greater than zero.');
                }
                if (mb_strlen(trim((string) ($data['evidence_reference'] ?? ''))) < self::EVIDENCE_MIN) {
                    throw new RuntimeException('Give the supporting evidence (an inspection report, police report, photographs or a signed return note).');
                }
                if (trim((string) ($data['cause'] ?? '')) === '') {
                    throw new RuntimeException('Give the cause of the damage or loss.');
                }

                $handoverId = null;
                $returnId = null;
                $fromBucket = 'damaged';
                if ($scope === 'owned') {
                    $line = SourcingLotLine::findOrFail($data['sourcing_lot_line_id'] ?? 0);
                    $lot = SourcingLot::whereKey($line->sourcing_lot_id)->lockForUpdate()->firstOrFail();
                    self::assertSellbeesStock($lot);
                    $fromBucket = (string) ($data['from_bucket'] ?? '');
                    if (! in_array($fromBucket, ['available', 'damaged'], true)) {
                        throw new RuntimeException('State whether the units are in sellable stock or already set apart as damaged.');
                    }
                    $event = (string) ($data['event_on'] ?? '');
                    $room = bcsub(Inventory::sliceBalances($line, null)[$fromBucket], self::committed(['sourcing_lot_line_id' => $line->id, 'scope' => ['owned', 'return'], 'from_bucket' => $fromBucket]), 2);
                } elseif ($scope === 'return') {
                    $return = InventoryHandoverReturn::whereKey($data['inventory_handover_return_id'] ?? 0)->lockForUpdate()->firstOrFail();
                    $handover = InventoryHandover::whereKey($return->inventory_handover_id)->lockForUpdate()->firstOrFail();
                    $lot = SourcingLot::whereKey($handover->sourcing_lot_id)->lockForUpdate()->firstOrFail();
                    if ($return->status !== 'approved' || $return->condition !== 'damaged') {
                        throw new RuntimeException('Only an approved return of damaged goods needs a loss case; resalable returns restore their cost under the return itself.');
                    }
                    self::assertSellbeesStock($lot);
                    $line = SourcingLotLine::findOrFail($handover->sourcing_lot_line_id);
                    $handoverId = $handover->id;
                    $returnId = $return->id;
                    $event = $return->returned_on->toDateString();
                    $left = bcsub((string) $return->quantity, self::committed(['inventory_handover_return_id' => $return->id]), 2);
                    $approved = (string) InventoryLossCase::where('inventory_handover_return_id', $return->id)->where('status', 'approved')->sum('quantity');
                    $room = bcsub($left, bcadd($approved, '0', 2), 2);
                    $owned = bcsub(Inventory::sliceBalances($line, null)['damaged'], self::committed(['sourcing_lot_line_id' => $line->id, 'scope' => ['owned', 'return'], 'from_bucket' => 'damaged']), 2);
                    $room = bccomp($room, $owned, 2) < 0 ? $room : $owned;
                } else {
                    $handover = InventoryHandover::whereKey($data['inventory_handover_id'] ?? 0)->lockForUpdate()->firstOrFail();
                    $lot = SourcingLot::whereKey($handover->sourcing_lot_id)->lockForUpdate()->firstOrFail();
                    if ($handover->status !== 'approved' || $handover->custody_mode !== 'warehouse') {
                        throw new RuntimeException('Only goods that an approved handover left in our warehouse are in the client\'s custody here.');
                    }
                    $line = SourcingLotLine::findOrFail($handover->sourcing_lot_line_id);
                    $handoverId = $handover->id;
                    $fromBucket = 'available';
                    $event = (string) ($data['event_on'] ?? '');
                    $room = bcsub(Inventory::sliceBalances($line, $handover->id)['available'], self::committed(['inventory_handover_id' => $handover->id, 'scope' => 'custody']), 2);
                }
                if (bccomp($quantity, $room, 2) > 0) {
                    throw new RuntimeException('Only '.(bccomp($room, '0', 2) > 0 ? $room : '0.00').' of these units can go into a new case; the rest is not there, is reserved, or is already in another open case.');
                }
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $event) || $event > today()->toDateString()) {
                    throw new RuntimeException('Give the date the damage or return happened; it cannot be in the future.');
                }

                $outcome = $scope === 'custody' ? 'custody_damage' : (string) ($data['outcome'] ?? '');
                $classification = $scope === 'custody' ? null : (string) ($data['classification'] ?? '');
                $recoverable = null;
                $basis = null;
                if ($scope !== 'custody') {
                    if (! in_array($outcome, self::OUTCOMES, true)) {
                        throw new RuntimeException('State the outcome: repairable, impaired (recoverable at a lower value) or write-off.');
                    }
                    if (! in_array($classification, ['normal', 'abnormal'], true)) {
                        throw new RuntimeException('Classify the loss by its cause as normal or abnormal.');
                    }
                    if (($data['recoverable_value'] ?? null) !== null && ($data['recoverable_value'] ?? '') !== '') {
                        $recoverable = self::validRecoverable($outcome, (int) $data['recoverable_value'], (string) ($data['valuation_basis'] ?? ''));
                        $basis = trim((string) $data['valuation_basis']);
                    }
                }
                $case = InventoryLossCase::create(['scope' => $scope, 'sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $line->id, 'inventory_handover_id' => $handoverId, 'inventory_handover_return_id' => $returnId,
                    'from_bucket' => $fromBucket, 'quantity' => $quantity, 'outcome' => $outcome, 'classification' => $classification, 'cause' => trim((string) $data['cause']), 'evidence_reference' => trim((string) $data['evidence_reference']),
                    'assessment_note' => $data['assessment_note'] ?? null, 'recoverable_value' => $recoverable, 'valuation_basis' => $basis, 'event_on' => $event, 'discovered_on' => today()->toDateString(),
                    'status' => $scope !== 'custody' && $recoverable === null ? 'pending_valuation' : 'pending_review', 'prepared_by' => $user->id, 'idempotency_key' => $key]);
                Audit::log('inventory.loss_proposed', 'line '.$line->id, '#'.$case->id.' '.$scope.' '.$quantity.' '.$outcome.' '.($classification ?? '').': '.$case->cause);

                return $case->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return InventoryLossCase::where('idempotency_key', $key)->firstOrFail();
        }
    }

    /** A recoverable value is a supported amount: a verified zero belongs to a write-off, any positive value to goods that are kept. */
    private static function validRecoverable(string $outcome, int $value, string $basis): int
    {
        if ($value < 0) {
            throw new RuntimeException('A recoverable value cannot be negative.');
        }
        if (mb_strlen(trim($basis)) < self::EVIDENCE_MIN) {
            throw new RuntimeException('State the basis of the recoverable value (a quote, a repair estimate, a salvage offer, or "no buyer, scrapped").');
        }
        if ($outcome === 'write_off' && $value !== 0) {
            throw new RuntimeException('A write-off recovers nothing; goods that keep a recoverable value are impaired or repairable, not written off.');
        }
        if ($outcome !== 'write_off' && $value <= 0) {
            throw new RuntimeException('Impaired or repairable goods need a recoverable value above zero; with none, the goods are written off.');
        }

        return $value;
    }

    private static function assertSellbeesStock(SourcingLot $lot): void
    {
        if ($lot->ownership !== 'company' || ! InventoryEngine::isValued($lot)) {
            throw new RuntimeException('Only Sellbees-owned stock that the inventory engine values can be written down here; client-owned stock is a custody incident.');
        }
    }

    public static function cancel(InventoryLossCase $case, User $user, string $reason): void
    {
        DB::transaction(function () use ($case, $user, $reason) {
            $case = InventoryLossCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($case->status, InventoryLossCase::ACTIVE, true)) {
                throw new RuntimeException('Only a case that has not been decided can be cancelled.');
            }
            if ($case->prepared_by !== $user->id && ! $user->can('inventory.loss.approve')) {
                throw new RuntimeException('Only the person who proposed it, or an approver, can cancel this case.');
            }
            if (trim($reason) === '') {
                throw new RuntimeException('Give a reason for cancelling.');
            }
            $case->update(['status' => 'cancelled', 'decision_note' => trim($reason)]);
            Audit::log('inventory.loss_cancelled', 'case '.$case->id, trim($reason));
        });
    }

    /** Finance: check the evidence and the treatment, and supply or confirm the recoverable value (a verified zero included) with its basis. */
    public static function review(InventoryLossCase $case, User $user, string $note, ?int $recoverableValue = null, ?string $basis = null): void
    {
        DB::transaction(function () use ($case, $user, $note, $recoverableValue, $basis) {
            $case = InventoryLossCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($case->status, ['pending_valuation', 'pending_review'], true)) {
                throw new RuntimeException('This case is not waiting for a Finance review.');
            }
            if (! $user->can('inventory.loss.review')) {
                throw new RuntimeException('Only Finance can review a loss case.');
            }
            if ($case->prepared_by === $user->id) {
                throw new RuntimeException('You cannot review a case you proposed.');
            }
            if (trim($note) === '') {
                throw new RuntimeException('Record what Finance checked: the evidence, the valuation and the treatment.');
            }
            $update = ['status' => 'reviewed', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => trim($note)];
            if ($case->scope !== 'custody') {
                $value = $recoverableValue ?? $case->recoverable_value;
                if ($value === null) {
                    throw new RuntimeException('There is no supported recoverable value yet. The units stay quarantined and nothing is recognised until one is supplied with its basis.');
                }
                $update['recoverable_value'] = self::validRecoverable($case->outcome, (int) $value, (string) ($basis ?? $case->valuation_basis));
                $update['valuation_basis'] = trim((string) ($basis ?? $case->valuation_basis));
            }
            $case->update($update);
            Audit::log('inventory.loss_reviewed', 'case '.$case->id, trim($note));
        });
    }

    public static function decide(InventoryLossCase $case, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($case, $approve, $user, $note) {
            $case = InventoryLossCase::findOrFail($case->id);
            SourcingLot::whereKey($case->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $handover = $case->inventory_handover_id ? InventoryHandover::whereKey($case->inventory_handover_id)->lockForUpdate()->firstOrFail() : null;
            $case = InventoryLossCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->status !== 'reviewed') {
                throw new RuntimeException($case->status === 'approved' || $case->status === 'rejected' ? 'Already decided.' : 'Finance must review this case before the Board can decide it.');
            }
            if ($case->prepared_by === $user->id || $case->reviewed_by === $user->id) {
                throw new RuntimeException('You cannot approve a case you proposed or reviewed.');
            }
            if (! $user->can('inventory.loss.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide a loss case.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $case->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim($note)]);
                Audit::log('inventory.loss_rejected', 'case '.$case->id, trim($note));

                return;
            }
            if (JournalEntry::where('source_type', 'inventory_loss_case')->where('source_id', $case->id)->exists()) {
                throw new RuntimeException('This case has already been posted.');
            }

            $result = match ($case->scope) {
                'custody' => self::applyCustody($case, $handover, $user),
                'return' => self::applyReturn($case, $handover, $user),
                default => self::applyOwned($case, $user),
            };
            $case->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null] + $result);
            Audit::log('inventory.loss_approved', 'case '.$case->id, $case->scope.' '.$case->quantity.' '.$case->outcome.' '.($result['treatment'] ?? '').' carrying '.($result['carrying_value'] ?? 0).' loss '.($result['loss_value'] ?? 0));
        });
    }

    /**
     * Where the journal is dated: the event's own date while that month is open, otherwise today (the discovery date). A closed month is never posted into.
     *
     * @return array{0: string, 1: bool}
     */
    private static function postingDate(InventoryLossCase $case): array
    {
        $closed = AccountingService::isClosed($case->event_on->toDateString());
        $date = $closed ? today()->toDateString() : $case->event_on->toDateString();
        AccountingService::assertOpen($date, 'this loss case');

        return [$date, $closed];
    }

    private static function memo(InventoryLossCase $case, bool $closed): string
    {
        return 'Inventory '.str_replace('_', ' ', $case->outcome).' (case #'.$case->id.', '.$case->classification.' loss): '.$case->quantity.' units, '.$case->cause
            .($closed ? ' - event dated '.$case->event_on->format('j M Y').' in a closed period; recorded in the open period, flagged for review' : '');
    }

    /**
     * @param  list<array{0: string, 1: null, 2: int, 3: int}>  $lines
     */
    private static function post(InventoryLossCase $case, string $date, bool $closed, array $lines, User $user): ?int
    {
        return $lines === [] ? null : Ledger::post($date, self::memo($case, $closed), 'inventory_loss_case', $case->id, $lines, $user->id)->id;
    }

    /** @return array<string, mixed> */
    private static function applyOwned(InventoryLossCase $case, User $user): array
    {
        $lot = SourcingLot::findOrFail($case->sourcing_lot_id);
        self::assertSellbeesStock($lot);
        $line = $lot->lines()->findOrFail($case->sourcing_lot_line_id);
        InventoryValuation::assertIssuable($line);
        $quantity = (string) $case->quantity;
        if (bccomp($quantity, Inventory::sliceBalances($line, null)[$case->from_bucket], 2) > 0) {
            throw new RuntimeException('Those units are no longer in Sellbees\' '.$case->from_bucket.' stock.');
        }
        $cost = InventoryValuation::issueCost($line, $quantity); // exact integer value leaving the layers; fails when the quantity is not valued
        $carrying = $cost['purchase'] + $cost['landed'];
        $recoverable = self::recoverableFor($case, $carrying);
        $loss = $carrying - $recoverable;
        [$date, $closed] = self::postingDate($case);
        $case->update(['posted_on' => $date]);

        $lossAccount = $case->classification === 'abnormal' ? 'inventory_loss' : 'cogs';
        $lines = [];
        if ($recoverable > 0) {
            $lines[] = ['assets.inventory_impaired', null, $recoverable, 0];
        }
        if ($loss > 0) {
            $lines[] = [$lossAccount, null, $loss, 0];
        }
        $lines[] = ['assets.inventory', null, 0, $carrying];
        $entryId = self::post($case, $date, $closed, $lines, $user);
        InventoryValuation::recordLoss($case, $line, $cost['purchase'], $cost['landed'], $entryId, $user);
        self::moveUnits($case, $line, $user);

        return ['posted_on' => $date, 'prior_period' => $closed, 'journal_entry_id' => $entryId, 'carrying_value' => $carrying, 'loss_value' => $loss, 'cogs_reversed' => 0,
            'treatment' => $case->outcome === 'write_off' ? ($case->classification === 'abnormal' ? 'inventory_loss_expense' : 'retained_in_cogs') : 'impaired_inventory'];
    }

    /** @return array<string, mixed> */
    private static function applyReturn(InventoryLossCase $case, InventoryHandover $handover, User $user): array
    {
        $lot = SourcingLot::findOrFail($case->sourcing_lot_id);
        self::assertSellbeesStock($lot);
        $return = InventoryHandoverReturn::findOrFail($case->inventory_handover_return_id);
        if ($handover->status !== 'approved' || $return->status !== 'approved' || (int) $return->inventory_handover_id !== (int) $handover->id) {
            throw new RuntimeException('The return this case belongs to no longer matches its handover.');
        }
        $line = $lot->lines()->findOrFail($case->sourcing_lot_line_id);
        $quantity = (string) $case->quantity;
        if (bccomp($quantity, Inventory::sliceBalances($line, null)['damaged'], 2) > 0) {
            throw new RuntimeException('Those units are no longer in Sellbees\' damaged stock.');
        }
        $costed = InventoryLossCase::where('inventory_handover_id', $handover->id)->where('scope', 'return')->where('status', 'approved');
        $restored = (int) $handover->returns()->where('status', 'approved')->where('condition', 'resalable')->sum('restored_value') + (int) (clone $costed)->sum('carrying_value');
        $remaining = (int) $handover->cogs_value - $restored;
        $unitsLeft = bcsub(bcsub((string) $handover->quantity, (string) $handover->returned_quantity, 2), (string) (clone $costed)->sum('quantity'), 2);
        // Original cost of these units: its share of the handover's recorded cost, and the last units take whatever remains so that nothing is restored twice or left behind.
        $carrying = bccomp($quantity, $unitsLeft, 2) === 0
            ? $remaining
            : min($remaining, (int) bcadd(bcdiv(bcmul((string) $handover->cogs_value, $quantity, 2), (string) $handover->quantity, 6), '0.5', 0));
        if ($carrying <= 0) {
            throw new RuntimeException('There is no original cost left to reverse for these units.');
        }
        $recoverable = self::recoverableFor($case, $carrying);
        $loss = $carrying - $recoverable;
        [$date, $closed] = self::postingDate($case);
        $case->update(['posted_on' => $date]);

        // A valid sale reversal: the cost of goods sold of these units is reversed, the goods come back at their recoverable value, and the loss is recognised on its own.
        // A normal loss stays in cost of goods sold, so only what is recoverable is reversed.
        $abnormal = $case->classification === 'abnormal';
        $reversed = $abnormal ? $carrying : $recoverable;
        $lines = [];
        if ($recoverable > 0) {
            $lines[] = ['assets.inventory_impaired', null, $recoverable, 0];
        }
        if ($abnormal && $loss > 0) {
            $lines[] = ['inventory_loss', null, $loss, 0];
        }
        if ($reversed > 0) {
            $lines[] = ['cogs', null, 0, $reversed];
        }
        $entryId = self::post($case, $date, $closed, $lines, $user);
        self::moveUnits($case, $line, $user);

        return ['posted_on' => $date, 'prior_period' => $closed, 'journal_entry_id' => $entryId, 'carrying_value' => $carrying, 'loss_value' => $loss, 'cogs_reversed' => $reversed,
            'treatment' => $case->outcome === 'write_off' ? ($abnormal ? 'inventory_loss_expense' : 'retained_in_cogs') : 'impaired_inventory'];
    }

    /** @return array<string, mixed> */
    private static function applyCustody(InventoryLossCase $case, InventoryHandover $handover, User $user): array
    {
        $line = SourcingLotLine::findOrFail($case->sourcing_lot_line_id);
        if ($handover->status !== 'approved' || $handover->custody_mode !== 'warehouse') {
            throw new RuntimeException('This handover is no longer held in our warehouse for the client.');
        }
        $quantity = (string) $case->quantity;
        // Custody damage only moves the units to the damaged bucket of the CLIENT'S slice; it is the client's stock, so Sellbees posts no inventory, cost or loss.
        Inventory::move($line, 'custody_damage', ['available' => bcmul($quantity, '-1', 2), 'damaged' => $quantity], 'loss:'.$case->id, null, $user, 'Custody incident, case #'.$case->id.': '.$case->cause, $handover->id);

        return ['treatment' => 'custody_incident', 'carrying_value' => 0, 'loss_value' => 0, 'cogs_reversed' => 0];
    }

    private static function recoverableFor(InventoryLossCase $case, int $carrying): int
    {
        if ($case->recoverable_value === null) {
            throw new RuntimeException('No supported recoverable value is on record: the valuation is still pending.');
        }
        $value = (int) $case->recoverable_value;
        if ($value > $carrying) {
            throw new RuntimeException('The recoverable value ('.Money::format($value).') cannot exceed the carrying value of these units ('.Money::format($carrying).'): inventory is never carried above cost.');
        }

        return $value;
    }

    /** The physical side of an approved case: a write-off takes the units out of the warehouse count, a quarantine from sellable stock moves them to the damaged bucket. */
    private static function moveUnits(InventoryLossCase $case, SourcingLotLine $line, User $user): void
    {
        $quantity = (string) $case->quantity;
        $reason = 'Loss case #'.$case->id.' ('.$case->outcome.', '.$case->classification.'): '.$case->cause;
        if ($case->outcome === 'write_off') {
            Inventory::move($line, 'adjust', [$case->from_bucket => bcmul($quantity, '-1', 2)], 'loss:'.$case->id, null, $user, $reason);
        } elseif ($case->from_bucket === 'available') {
            Inventory::move($line, 'adjust', ['available' => bcmul($quantity, '-1', 2), 'damaged' => $quantity], 'loss:'.$case->id, null, $user, $reason);
        }
    }
}
