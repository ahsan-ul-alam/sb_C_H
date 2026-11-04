<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\Expense;
use App\Models\InventoryEngineEnrolment;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SourcingLotLine;
use App\Support\Costing;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The value of Sellbees-owned stock, per lot line, kept in an append-only subledger. It never posts to the ledger: every value it records is
 * already carried by a posted journal entry (a verified settlement of accepted goods, a verified capitalised cost, a confirmed transport service), and
 * each movement names that entry. So the general ledger's inventory asset is not debited a second time, and the two can be reconciled.
 *
 *   purchase value  = accepted quantity x unit price, recognised only as verified settlements cover it. Accepted goods that are not settled yet are
 *                     PENDING: they carry no value (never an invented zero cost) and cannot be issued.
 *   landed cost     = capitalised acquisition cost (verified) and confirmed transport, spread over the lines accepted at that time, by the lot's basis
 *                     (value or quantity), largest-remainder so the parts are exactly the whole; a cost for one line goes to that line.
 *   quantity        = the line's accepted quantity, counted only once its purchase value is fully covered.
 *
 * Applying is idempotent: sync recomputes what each source event should have contributed and appends only the difference, under a per-lot lock.
 */
class InventoryValuation
{
    /** Settle the lot's subledger with the ledger events that have happened. Does nothing for a lot the engine does not value. */
    public static function syncLot(int $lotId): void
    {
        $enrolment = InventoryEngineEnrolment::where('sourcing_lot_id', $lotId)->where('status', 'approved')->where('ownership', 'company')->first();
        if (! $enrolment) {
            return;
        }
        DB::transaction(function () use ($lotId, $enrolment) {
            $lot = SourcingLot::whereKey($lotId)->lockForUpdate()->firstOrFail();
            $lines = $lot->lines()->get()->keyBy('id');
            $state = self::state($lines);

            self::applySettlements($lot, $lines, $enrolment, $state);
            self::applyCosts($lot, $lines, $enrolment, $state);
            self::syncQuantities($lot, $lines, $enrolment, $state);
        });
    }

    /** After an expense was journaled: a capitalised cost of an enrolled lot (or its correction) changes that lot's value. */
    public static function onExpensePosted(Expense $expense): void
    {
        $original = $expense->reverses_id ?: $expense->id;
        $lotId = SourcingLotCost::where('expense_id', $original)->value('sourcing_lot_id');
        if ($lotId) {
            self::syncLot((int) $lotId);
        }
    }

    // ── Reading the subledger ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{purchase: int, landed: int, quantity: string} */
    public static function lineFigures(SourcingLotLine $line): array
    {
        $rows = InventoryValueMovement::where('sourcing_lot_line_id', $line->id)->selectRaw('component, COALESCE(SUM(value_delta),0) v, COALESCE(SUM(quantity_delta),0) q')->groupBy('component')->get()->keyBy('component');

        return ['purchase' => (int) ($rows['purchase']->v ?? 0), 'landed' => (int) ($rows['landed']->v ?? 0), 'quantity' => bcadd((string) ($rows['quantity']->q ?? 0), '0', 2)];
    }

    /** Value of the line's stock in the subledger, integer minor units. */
    public static function lineValue(SourcingLotLine $line): int
    {
        $f = self::lineFigures($line);

        return $f['purchase'] + $f['landed'];
    }

    /** Purchase value of the quantity accepted so far (accepted x unit price); zero until an arrival is on record. */
    public static function acceptedValue(SourcingLotLine $line): int
    {
        return $line->received_quantity === null ? 0 : Money::multiply((string) $line->usable_quantity, (int) $line->unit_price);
    }

    /** pending | partial | valued | over_valued | none, from what has been valued against what was accepted. */
    public static function status(int $accepted, int $valued): string
    {
        return match (true) {
            $accepted <= 0 => $valued > 0 ? 'over_valued' : 'none',
            $valued <= 0 => 'pending',
            $valued < $accepted => 'partial',
            $valued === $accepted => 'valued',
            default => 'over_valued',
        };
    }

    /** Derived average unit cost of what the line holds (display only: postings use exact allocated values). */
    public static function unitCost(SourcingLotLine $line): string
    {
        $f = self::lineFigures($line);

        return Costing::unitCost($f['purchase'] + $f['landed'], $f['quantity']);
    }

    // ── Applying ledger events ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{purchase: array<int, int>, quantity: array<int, string>} running figures per line while a sync runs */
    private static function state($lines): array
    {
        $purchase = [];
        $quantity = [];
        foreach ($lines as $id => $line) {
            $f = self::lineFigures($line);
            $purchase[$id] = $f['purchase'];
            $quantity[$id] = $f['quantity'];
        }

        return ['purchase' => $purchase, 'quantity' => $quantity];
    }

    /** The date a movement carries: the source date, but never before the enrolment and never inside a closed month (then today). */
    private static function effectiveOn(string $sourceDate, InventoryEngineEnrolment $enrolment): string
    {
        $date = max($sourceDate, $enrolment->effective_on->toDateString());
        if (! AccountingService::isClosed($date)) {
            return $date;
        }
        $today = today()->toDateString();
        AccountingService::assertOpen($today, 'an inventory valuation movement');

        return $today;
    }

    private static function journalId(string $sourceType, int $sourceId): ?int
    {
        return JournalEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->value('id');
    }

    /** What the journal entries of this source put into (debit) or took out of (credit) the inventory asset. */
    public static function inventoryNet(string $sourceType, int $sourceId): int
    {
        $ids = JournalEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->pluck('id');

        return (int) JournalLine::whereIn('journal_entry_id', $ids)->where('account', 'assets.inventory')->sum('debit') - (int) JournalLine::whereIn('journal_entry_id', $ids)->where('account', 'assets.inventory')->sum('credit');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function record(SourcingLot $lot, SourcingLotLine $line, InventoryEngineEnrolment $enrolment, array $attributes): void
    {
        InventoryValueMovement::create($attributes + [
            'sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $line->id, 'supplier_id' => $line->supplier_id, 'supplier_product_id' => $line->supplier_product_id,
            'project_id' => $lot->project_id, 'ownership' => $enrolment->ownership, 'quantity_delta' => 0, 'value_delta' => 0,
        ]);
    }

    /**
     * @param  Collection<int, SourcingLotLine>  $lines
     * @param  array{purchase: array<int, int>, quantity: array<int, string>}  $state
     */
    private static function applySettlements(SourcingLot $lot, $lines, InventoryEngineEnrolment $enrolment, array &$state): void
    {
        foreach (AdvanceSettlement::where('sourcing_lot_id', $lot->id)->whereIn('status', ['verified', 'reversed'])->orderBy('id')->get() as $settlement) {
            $applied = InventoryValueMovement::where('source_type', 'advance_settlement')->where('source_id', $settlement->id)->where('component', 'purchase')->where('type', 'add')->orderBy('id')->get();

            if ($settlement->status === 'reversed') {
                $reversalEntry = self::journalId('advance_settlement_rev', $settlement->id);
                foreach ($applied as $movement) {
                    if (! $reversalEntry || InventoryValueMovement::where('reverses_id', $movement->id)->exists()) {
                        continue;
                    }
                    $line = $lines[$movement->sourcing_lot_line_id];
                    $state['purchase'][$line->id] -= (int) $movement->value_delta;
                    self::record($lot, $line, $enrolment, ['component' => 'purchase', 'type' => 'reversal', 'value_delta' => -(int) $movement->value_delta, 'reverses_id' => $movement->id,
                        'valuation_status' => self::status(self::acceptedValue($line), $state['purchase'][$line->id]), 'source_type' => 'advance_settlement_rev', 'source_id' => $settlement->id,
                        'journal_entry_id' => $reversalEntry, 'move_key' => 'rev:'.$movement->id, 'effective_on' => self::effectiveOn(JournalEntry::find($reversalEntry)->entry_date->toDateString(), $enrolment),
                        'reason' => 'Settlement #'.$settlement->id.' reversed', 'verified_by' => $settlement->verified_by, 'verified_at' => $settlement->verified_at]);
                }

                continue;
            }

            $journal = self::journalId('advance_settlement', $settlement->id);
            $leftover = (int) $settlement->amount - (int) $applied->sum('value_delta');
            if (! $journal || $leftover <= 0) {
                continue; // nothing posted to trace to, or already fully applied
            }
            // The settlement values goods of ITS supplier on this lot, up to what each line has accepted and not yet had valued.
            $capacity = [];
            foreach ($lines->where('supplier_id', $settlement->supplier_id) as $line) {
                $room = self::acceptedValue($line) - $state['purchase'][$line->id];
                if ($room > 0) {
                    $capacity[$line->id] = $room;
                }
            }
            $take = min($leftover, (int) array_sum($capacity));
            if ($take <= 0) {
                continue;
            }
            foreach (Costing::allocateShared($take, $capacity) as $lineId => $share) {
                if ($share <= 0) {
                    continue;
                }
                $line = $lines[$lineId];
                $state['purchase'][$lineId] += $share;
                $sequence = InventoryValueMovement::where('source_type', 'advance_settlement')->where('source_id', $settlement->id)->where('sourcing_lot_line_id', $lineId)->count() + 1;
                self::record($lot, $line, $enrolment, ['component' => 'purchase', 'type' => 'add', 'value_delta' => $share, 'valuation_status' => self::status(self::acceptedValue($line), $state['purchase'][$lineId]),
                    'source_type' => 'advance_settlement', 'source_id' => $settlement->id, 'journal_entry_id' => $journal, 'move_key' => 'adv:'.$settlement->id.':'.$lineId.':'.$sequence,
                    'effective_on' => self::effectiveOn($settlement->settled_on->toDateString(), $enrolment), 'verified_by' => $settlement->verified_by, 'verified_at' => $settlement->verified_at,
                    'reason' => 'Accepted goods settled ('.$settlement->kind.')']);
            }
        }
    }

    /**
     * @param  Collection<int, SourcingLotLine>  $lines
     * @param  array{purchase: array<int, int>, quantity: array<int, string>}  $state
     */
    private static function applyCosts(SourcingLot $lot, $lines, InventoryEngineEnrolment $enrolment, array &$state): void
    {
        $replaced = $lot->costs()->pluck('replaces_id')->filter()->all();
        foreach ($lot->costs()->where('kind', 'actual')->whereNotNull('expense_id')->with('expense')->get() as $cost) {
            if (in_array($cost->id, $replaced, true) || ! $cost->expense) {
                continue;
            }
            $expense = $cost->expense;
            [$sourceType, $sourceId, $date] = match (true) {
                $expense->classification === 'acquisition' && $expense->status === 'verified' => ['expense', $expense->id, $expense->spent_on->toDateString()],
                $expense->classification === 'transport_prepayment' && $expense->status === 'verified' && $cost->service_completed_on !== null => ['transport_service', $cost->id, $cost->service_completed_on->toDateString()],
                default => [null, null, null],
            };
            if ($sourceType === null) {
                continue; // not capitalised (yet): an unverified cost or a prepayment whose service is not confirmed
            }
            $journal = self::journalId($sourceType, $sourceId);
            $amount = self::inventoryNet($sourceType, $sourceId);
            $capKey = 'cap:'.$cost->id;
            if ($journal && $amount > 0 && ! InventoryValueMovement::where('source_type', 'lot_cost')->where('source_id', $cost->id)->where('type', 'add')->exists()) {
                $eligible = $cost->sourcing_lot_line_id ? $lines->where('id', $cost->sourcing_lot_line_id) : $lines;
                $eligible = $eligible->filter(fn (SourcingLotLine $l) => $l->received_quantity !== null && bccomp((string) $l->usable_quantity, '0', 2) > 0);
                if ($eligible->isNotEmpty()) {
                    // Shared cost follows the lot's basis over the lines accepted now; a cost tied to one line goes to that line.
                    $weights = $eligible->mapWithKeys(fn (SourcingLotLine $l) => [$l->id => $lot->allocation_basis === 'quantity' ? (int) bcmul((string) $l->usable_quantity, '100', 0) : self::acceptedValue($l)])->all();
                    foreach (Costing::allocateShared($amount, $weights) as $lineId => $share) {
                        if ($share > 0) {
                            self::record($lot, $lines[$lineId], $enrolment, ['component' => 'landed', 'type' => 'add', 'value_delta' => $share, 'valuation_status' => self::status(self::acceptedValue($lines[$lineId]), $state['purchase'][$lineId]),
                                'source_type' => 'lot_cost', 'source_id' => $cost->id, 'journal_entry_id' => $journal, 'move_key' => $capKey.':'.$lineId, 'effective_on' => self::effectiveOn($date, $enrolment),
                                'verified_by' => $expense->verified_by, 'verified_at' => $expense->verified_at, 'reason' => $cost->category.' capitalised']);
                        }
                    }
                }
            }
            // A verified correction of a capitalised cost takes value back out in the proportions it went in.
            if ($expense->classification === 'acquisition') {
                $original = InventoryValueMovement::where('source_type', 'lot_cost')->where('source_id', $cost->id)->where('type', 'add')->get()->groupBy('sourcing_lot_line_id')->map(fn ($g) => (int) $g->sum('value_delta'));
                foreach (Expense::where('reverses_id', $expense->id)->where('status', 'verified')->orderBy('id')->get() as $correction) {
                    $taken = -self::inventoryNet('expense', $correction->id); // credit to inventory => positive amount to remove
                    $correctionJournal = self::journalId('expense', $correction->id);
                    if ($original->isEmpty() || $taken <= 0 || ! $correctionJournal || InventoryValueMovement::where('source_type', 'cost_correction')->where('source_id', $correction->id)->exists()) {
                        continue;
                    }
                    foreach (Costing::allocateShared($taken, $original->all()) as $lineId => $share) {
                        if ($share > 0) {
                            self::record($lot, $lines[$lineId], $enrolment, ['component' => 'landed', 'type' => 'correction', 'value_delta' => -$share, 'valuation_status' => self::status(self::acceptedValue($lines[$lineId]), $state['purchase'][$lineId]),
                                'source_type' => 'cost_correction', 'source_id' => $correction->id, 'journal_entry_id' => $correctionJournal, 'move_key' => 'cor:'.$correction->id.':'.$lineId,
                                'effective_on' => self::effectiveOn($correction->spent_on->toDateString(), $enrolment), 'verified_by' => $correction->verified_by, 'verified_at' => $correction->verified_at, 'reason' => 'Correction of '.$cost->category]);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  Collection<int, SourcingLotLine>  $lines
     * @param  array{purchase: array<int, int>, quantity: array<int, string>}  $state
     */
    private static function syncQuantities(SourcingLot $lot, $lines, InventoryEngineEnrolment $enrolment, array &$state): void
    {
        foreach ($lines as $line) {
            $accepted = self::acceptedValue($line);
            $target = $accepted > 0 && $state['purchase'][$line->id] >= $accepted ? bcadd((string) $line->usable_quantity, '0', 2) : '0.00';
            $delta = bcsub($target, $state['quantity'][$line->id], 2);
            if (bccomp($delta, '0', 2) === 0) {
                continue;
            }
            $sequence = InventoryValueMovement::where('sourcing_lot_line_id', $line->id)->where('component', 'quantity')->count() + 1;
            self::record($lot, $line, $enrolment, ['component' => 'quantity', 'type' => bccomp($delta, '0', 2) > 0 ? 'add' : 'correction', 'quantity_delta' => $delta,
                'valuation_status' => self::status($accepted, $state['purchase'][$line->id]), 'source_type' => 'lot_line', 'source_id' => $line->id, 'journal_entry_id' => null,
                'move_key' => 'qty:'.$line->id.':'.$sequence, 'effective_on' => self::effectiveOn(today()->toDateString(), $enrolment), 'reason' => 'Accepted quantity valued']);
            $state['quantity'][$line->id] = $target;
        }
    }

    /** Refuse to issue stock whose value is not fully recognised (used by the handover step in a later phase). */
    public static function assertIssuable(SourcingLotLine $line): void
    {
        $status = self::status(self::acceptedValue($line), self::lineFigures($line)['purchase']);
        if ($status !== 'valued') {
            throw new RuntimeException('This stock is not fully valued yet ('.$status.'): settle the accepted goods before issuing them.');
        }
    }
}
