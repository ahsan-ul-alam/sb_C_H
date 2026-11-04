<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\Expense;
use App\Models\InventoryEngineEnrolment;
use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\InventoryLossCase;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LandedCostReallocation;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SourcingLotLine;
use App\Models\User;
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

    /**
     * What a line holds now (net of issues and returns) and what has been valued at all: "purchase_valued" is the purchase value recognised from settlements,
     * before any stock was handed over, which is what the accepted goods are measured against.
     *
     * @return array{purchase: int, landed: int, quantity: string, purchase_valued: int}
     */
    public static function lineFigures(SourcingLotLine $line): array
    {
        $rows = InventoryValueMovement::where('sourcing_lot_line_id', $line->id)->selectRaw('component, COALESCE(SUM(value_delta),0) v, COALESCE(SUM(quantity_delta),0) q')->groupBy('component')->get()->keyBy('component');
        $valued = (int) InventoryValueMovement::where('sourcing_lot_line_id', $line->id)->where('component', 'purchase')->whereNotIn('type', ['issue', 'issue_return', 'writeoff', 'quarantine'])->sum('value_delta');

        return ['purchase' => (int) ($rows['purchase']->v ?? 0), 'landed' => (int) ($rows['landed']->v ?? 0), 'quantity' => bcadd((string) ($rows['quantity']->q ?? 0), '0', 2), 'purchase_valued' => $valued];
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
            $purchase[$id] = $f['purchase_valued']; // valued against the accepted goods, before any hand-over
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
                                'source_type' => 'lot_cost', 'source_id' => $cost->id, 'sourcing_lot_cost_id' => $cost->id, 'journal_entry_id' => $journal, 'move_key' => $capKey.':'.$lineId, 'effective_on' => self::effectiveOn($date, $enrolment),
                                'verified_by' => $expense->verified_by, 'verified_at' => $expense->verified_at, 'reason' => $cost->category.' capitalised']);
                        }
                    }
                }
            }
            // A verified correction of a capitalised cost takes value back out in the proportions it now sits on the lines. A confirmed transport
            // prepayment is capitalised too: its correction credits inventory (see ExpenseVerifier), and a correction made before the service was
            // confirmed credited the prepayment instead and is already netted out of the capitalised amount.
            if ($expense->classification === 'acquisition' || ($expense->classification === 'transport_prepayment' && $cost->service_completed_on !== null)) {
                $original = InventoryValueMovement::where('sourcing_lot_cost_id', $cost->id)->get()->groupBy('sourcing_lot_line_id')->map(fn ($g) => (int) $g->sum('value_delta'))->filter(fn ($v) => $v > 0);
                foreach (Expense::where('reverses_id', $expense->id)->where('status', 'verified')->orderBy('id')->get() as $correction) {
                    $taken = -self::inventoryNet('expense', $correction->id); // credit to inventory => positive amount to remove
                    $correctionJournal = self::journalId('expense', $correction->id);
                    if ($original->isEmpty() || $taken <= 0 || ! $correctionJournal || InventoryValueMovement::where('source_type', 'cost_correction')->where('source_id', $correction->id)->exists()) {
                        continue;
                    }
                    foreach (Costing::allocateShared($taken, $original->all()) as $lineId => $share) {
                        if ($share > 0) {
                            self::record($lot, $lines[$lineId], $enrolment, ['component' => 'landed', 'type' => 'correction', 'value_delta' => -$share, 'valuation_status' => self::status(self::acceptedValue($lines[$lineId]), $state['purchase'][$lineId]),
                                'source_type' => 'cost_correction', 'source_id' => $correction->id, 'sourcing_lot_cost_id' => $cost->id, 'journal_entry_id' => $correctionJournal, 'move_key' => 'cor:'.$correction->id.':'.$lineId,
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
            // Valued goods are the accepted quantity less what has been handed over; nothing is counted until the purchase is fully valued.
            $target = $accepted > 0 && $state['purchase'][$line->id] >= $accepted ? bcsub(bcsub((string) $line->usable_quantity, self::issuedQuantity($line), 2), self::lossQuantity($line), 2) : '0.00';
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

    /** Net value one capitalised lot cost currently sits on the lot's lines in the subledger (what it put on, less corrections, net of reallocations). */
    public static function costValueHeld(SourcingLotCost $cost): int
    {
        return (int) InventoryValueMovement::where('sourcing_lot_cost_id', $cost->id)->sum('value_delta');
    }

    /** Quantity of the line handed over to clients (issued, less returned). Zero until handovers exist. */
    public static function issuedQuantity(SourcingLotLine $line): string
    {
        $sum = InventoryValueMovement::where('sourcing_lot_line_id', $line->id)->where('component', 'quantity')->whereIn('type', ['issue', 'issue_return'])->sum('quantity_delta');

        return bcmul(bcadd((string) $sum, '0', 2), '-1', 2);
    }

    /** Quantity taken out of the line's valuation by approved write-offs and quarantines (units written off, or held apart at a recoverable value). */
    public static function lossQuantity(SourcingLotLine $line): string
    {
        $sum = InventoryValueMovement::where('sourcing_lot_line_id', $line->id)->where('component', 'quantity')->whereIn('type', ['writeoff', 'quarantine'])->sum('quantity_delta');

        return bcmul(bcadd((string) $sum, '0', 2), '-1', 2);
    }

    /**
     * Take a quantity and its exact value out of the line's cost layers for an approved write-off or quarantine of Sellbees-owned stock. The journal entry carries the
     * loss and, for quarantined goods, the recoverable value recognised separately; nothing here posts.
     */
    public static function recordLoss(InventoryLossCase $case, SourcingLotLine $line, int $purchase, int $landed, int $journalId, User $approver): void
    {
        $lot = SourcingLot::findOrFail($case->sourcing_lot_id);
        $enrolment = InventoryEngineEnrolment::where('sourcing_lot_id', $lot->id)->where('status', 'approved')->where('ownership', 'company')->firstOrFail();
        $common = ['type' => $case->outcome === 'write_off' ? 'writeoff' : 'quarantine', 'project_id' => null, 'valuation_status' => 'valued', 'source_type' => 'inventory_loss_case', 'source_id' => $case->id,
            'journal_entry_id' => $journalId, 'effective_on' => self::effectiveOn($case->posted_on->toDateString(), $enrolment), 'verified_by' => $approver->id, 'verified_at' => now(), 'created_by' => $case->prepared_by,
            'reason' => 'Loss case #'.$case->id.' ('.$case->outcome.', '.$case->classification.')'];
        foreach (['purchase' => $purchase, 'landed' => $landed] as $component => $value) {
            if ($value !== 0) {
                self::record($lot, $line, $enrolment, $common + ['component' => $component, 'value_delta' => -$value, 'move_key' => 'loss:'.$case->id.':'.$component]);
            }
        }
        self::record($lot, $line, $enrolment, $common + ['component' => 'quantity', 'quantity_delta' => bcmul((string) $case->quantity, '-1', 2), 'move_key' => 'loss:'.$case->id.':quantity']);
    }

    /**
     * Apply an approved reallocation: the cost's current net value is spread over the named beneficiary lines by the stated basis, and the difference to
     * what each line holds now is appended as compensating movements that net to zero. The aggregate, and the ledger entry carrying it, do not change.
     */
    public static function applyReallocation(LandedCostReallocation $reallocation, User $approver): void
    {
        $cost = SourcingLotCost::findOrFail($reallocation->sourcing_lot_cost_id);
        $lot = SourcingLot::whereKey($cost->sourcing_lot_id)->lockForUpdate()->firstOrFail();
        $enrolment = InventoryEngineEnrolment::where('sourcing_lot_id', $lot->id)->where('status', 'approved')->where('ownership', 'company')->firstOrFail();
        $lines = $lot->lines()->get()->keyBy('id');
        $state = self::state($lines);
        $total = self::costValueHeld($cost);
        $held = InventoryValueMovement::where('sourcing_lot_cost_id', $cost->id)->get();
        $journal = $held->where('type', 'add')->first()?->journal_entry_id;
        $current = $held->groupBy('sourcing_lot_line_id')->map(fn ($g) => (int) $g->sum('value_delta'));

        $weights = [];
        foreach ($reallocation->beneficiary_line_ids as $lineId) {
            $line = $lines[$lineId];
            $weights[$lineId] = $reallocation->basis === 'quantity' ? (int) bcmul((string) $line->usable_quantity, '100', 0) : self::acceptedValue($line);
        }
        $target = Costing::allocateShared($total, $weights);
        foreach ($lines->keys()->merge($current->keys())->unique() as $lineId) {
            $delta = (int) ($target[$lineId] ?? 0) - (int) ($current[$lineId] ?? 0);
            if ($delta === 0) {
                continue;
            }
            self::record($lot, $lines[$lineId], $enrolment, ['component' => 'landed', 'type' => 'reallocation', 'value_delta' => $delta, 'sourcing_lot_cost_id' => $cost->id,
                'valuation_status' => self::status(self::acceptedValue($lines[$lineId]), $state['purchase'][$lineId]), 'source_type' => 'landed_reallocation', 'source_id' => $reallocation->id,
                'journal_entry_id' => $journal, 'move_key' => 'realloc:'.$reallocation->id.':'.$lineId, 'effective_on' => self::effectiveOn(today()->toDateString(), $enrolment),
                'verified_by' => $approver->id, 'verified_at' => now(), 'created_by' => $reallocation->prepared_by, 'reason' => 'Reallocated under '.$reallocation->policy_reference]);
        }
    }

    /** True once any stock of the lot has been issued (handed over): from then a cost's value is partly consumed and may no longer be moved. */
    public static function hasIssued(SourcingLot $lot): bool
    {
        return InventoryValueMovement::where('sourcing_lot_id', $lot->id)->where('type', 'issue')->exists();
    }

    /**
     * A capitalised cost (acquisition cost, or transport whose service is confirmed) can be reversed only while its value is still in the lot's
     * inventory. Once the lot's cost was released, or the value moved on or was consumed, the correct treatment (an open-period cost adjustment) is not
     * established here, so the reversal is refused instead of crediting inventory that no longer holds it.
     */
    public static function assertCostReversible(SourcingLotCost $cost, int $amount, int $alreadyCounted = 0): void
    {
        $lot = SourcingLot::findOrFail($cost->sourcing_lot_id);
        // $alreadyCounted: at approval time the reversal being decided is already marked verified, so the ledger's held figure no longer includes it.
        if (CostRecognitionService::unreleased($lot) + $alreadyCounted < $amount) {
            throw new RuntimeException('This cost has already been released into cost of goods sold, so it can no longer be taken back out of inventory. Record an authorised cost adjustment instead.');
        }
        if (InventoryEngine::isValued($lot) && InventoryValueMovement::where('sourcing_lot_cost_id', $cost->id)->exists() && (self::costValueHeld($cost) < $amount || self::hasIssued($lot))) {
            throw new RuntimeException('Part of this cost\'s value has been moved or consumed by stock already handed over, so it cannot be reversed here. Record an authorised cost adjustment instead.');
        }
    }

    /**
     * A settlement that valued goods cannot be reversed while capitalised costs still sit on those goods: reversing it would leave value on a line with no
     * valued quantity. The costs must be reversed, reallocated or written off (under an approved adjustment) first; nothing is silently zeroed or moved.
     */
    public static function assertSettlementReversible(AdvanceSettlement $settlement): void
    {
        if (! InventoryEngineEnrolment::where('sourcing_lot_id', $settlement->sourcing_lot_id)->where('status', 'approved')->where('ownership', 'company')->exists()) {
            return;
        }
        $removed = InventoryValueMovement::where('source_type', 'advance_settlement')->where('source_id', $settlement->id)->where('type', 'add')
            ->get()->groupBy('sourcing_lot_line_id')->map(fn ($g) => (int) $g->sum('value_delta'));
        foreach ($removed as $lineId => $value) {
            $line = SourcingLotLine::find($lineId);
            $f = self::lineFigures($line);
            if (self::hasIssued(SourcingLot::findOrFail($settlement->sourcing_lot_id))) {
                throw new RuntimeException('Stock of this lot has already been handed over to clients, so this settlement can no longer be reversed. Reverse the handover first.');
            }
            if ($f['landed'] > 0 && self::acceptedValue($line) > $f['purchase_valued'] - $value) {
                throw new RuntimeException('Capitalised costs ('.Money::format($f['landed']).') still sit on "'.$line->description.'". Reverse, reallocate or write off those costs first, so no value is left on stock that is no longer valued.');
            }
        }
    }

    /**
     * The exact integer value of $quantity leaving a line's cost layers, share by share. Purchase and landed value are each issued in proportion to the
     * valued quantity held, rounded half up, and the issue of everything that is left takes the whole remainder, so what is issued over time always adds
     * up to exactly what was valued: no cumulative rounding drift.
     *
     * @return array{purchase: int, landed: int}
     */
    public static function issueCost(SourcingLotLine $line, string $quantity): array
    {
        $f = self::lineFigures($line);
        if (bccomp($quantity, '0', 2) <= 0 || bccomp($quantity, $f['quantity'], 2) > 0) {
            throw new RuntimeException('Only '.$f['quantity'].' of this line is valued and available to hand over.');
        }
        $share = fn (int $value): int => bccomp($quantity, $f['quantity'], 2) === 0 ? $value : (int) bcadd(bcdiv(bcmul((string) $value, $quantity, 2), $f['quantity'], 6), '0.5', 0);

        return ['purchase' => $share($f['purchase']), 'landed' => $share($f['landed'])];
    }

    /** Remove the valued quantity and its exact value from the line's layers for an approved handover. The journal entry already carries the cost of goods sold. */
    public static function recordIssue(InventoryHandover $handover, SourcingLotLine $line, int $purchase, int $landed, int $journalId, User $approver): void
    {
        $lot = SourcingLot::findOrFail($handover->sourcing_lot_id);
        $enrolment = InventoryEngineEnrolment::where('sourcing_lot_id', $lot->id)->where('status', 'approved')->where('ownership', 'company')->firstOrFail();
        $common = ['type' => 'issue', 'project_id' => $handover->project_id, 'valuation_status' => 'valued', 'source_type' => 'inventory_handover', 'source_id' => $handover->id, 'journal_entry_id' => $journalId,
            'effective_on' => self::effectiveOn($handover->transferred_on->toDateString(), $enrolment), 'verified_by' => $approver->id, 'verified_at' => now(), 'created_by' => $handover->prepared_by,
            'reason' => 'Control transferred to the client (handover #'.$handover->id.')'];
        foreach (['purchase' => $purchase, 'landed' => $landed] as $component => $value) {
            if ($value !== 0) {
                self::record($lot, $line, $enrolment, $common + ['component' => $component, 'value_delta' => -$value, 'move_key' => 'hov:'.$handover->id.':'.$component]);
            }
        }
        self::record($lot, $line, $enrolment, $common + ['component' => 'quantity', 'quantity_delta' => bcmul((string) $handover->quantity, '-1', 2), 'move_key' => 'hov:'.$handover->id.':quantity']);
    }

    /** Put returned resalable goods back at their original cost (no quantity effect for a damaged return, which is recorded on the return only). */
    public static function recordReturn(InventoryHandoverReturn $return, InventoryHandover $handover, SourcingLotLine $line, int $purchase, int $landed, int $journalId, string $date, User $approver): void
    {
        $lot = SourcingLot::findOrFail($handover->sourcing_lot_id);
        $enrolment = InventoryEngineEnrolment::where('sourcing_lot_id', $lot->id)->where('status', 'approved')->where('ownership', 'company')->firstOrFail();
        $common = ['type' => 'issue_return', 'project_id' => $handover->project_id, 'valuation_status' => 'valued', 'source_type' => 'inventory_handover_return', 'source_id' => $return->id, 'journal_entry_id' => $journalId,
            'effective_on' => self::effectiveOn($date, $enrolment), 'verified_by' => $approver->id, 'verified_at' => now(), 'created_by' => $return->prepared_by,
            'reason' => 'Resalable goods returned (handover #'.$handover->id.')'];
        foreach (['purchase' => $purchase, 'landed' => $landed] as $component => $value) {
            if ($value !== 0) {
                self::record($lot, $line, $enrolment, $common + ['component' => $component, 'value_delta' => $value, 'move_key' => 'hovr:'.$return->id.':'.$component]);
            }
        }
        self::record($lot, $line, $enrolment, $common + ['component' => 'quantity', 'quantity_delta' => (string) $return->quantity, 'move_key' => 'hovr:'.$return->id.':quantity']);
    }

    /** Refuse to issue stock whose value is not fully recognised. */
    public static function assertIssuable(SourcingLotLine $line): void
    {
        $status = self::status(self::acceptedValue($line), self::lineFigures($line)['purchase_valued']);
        if ($status !== 'valued') {
            throw new RuntimeException('This stock is not fully valued yet ('.$status.'): settle the accepted goods before issuing them.');
        }
    }
}
