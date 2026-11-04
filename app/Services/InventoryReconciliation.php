<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\InventoryEngineEnrolment;
use App\Models\InventoryHandover;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;

/**
 * Read-only reconciliation of the inventory value subledger to the general ledger.
 *
 * The value of a lot in the ledger is NOT taken from one aggregate balance: it is built from the journal entries of that lot's own events (verified
 * settlements and their reversals, capitalised costs and their corrections, confirmed transport, and any whole-lot release), so lots on the legacy cash
 * or whole-lot basis and lots on the engine can sit side by side and each is judged on its own. The company-wide view then adds the lots up and compares
 * the total with the inventory asset in the ledger; what no lot explains is reported, never hidden.
 */
class InventoryReconciliation
{
    /**
     * @return array<string, mixed>
     */
    public static function lot(SourcingLot $lot): array
    {
        $enrolment = InventoryEngine::enrolment($lot);
        $journal = self::journalValue($lot);
        $valued = $enrolment?->isValued() ?? false;
        $row = [
            'lot' => $lot->number, 'lot_id' => $lot->id, 'ownership' => $lot->ownership, 'enrolment' => $enrolment?->status, 'valued' => $valued,
            'journal_value' => $journal, 'exclusion' => $valued ? null : self::exclusion($lot, $enrolment),
            'subledger_value' => null, 'difference' => null, 'purchase_valued' => 0, 'landed_valued' => 0, 'accepted_purchase_value' => 0,
            'unvalued_accepted' => 0, 'over_valued' => 0, 'unallocated_capitalised' => null, 'lines' => [], 'exceptions' => [], 'ok' => true,
        ];
        if (! $valued) {
            return $row;
        }

        $subledger = 0;
        $purchaseAll = 0;
        $landedAll = 0;
        $acceptedAll = 0;
        foreach ($lot->lines()->get() as $line) {
            $f = InventoryValuation::lineFigures($line);
            $accepted = InventoryValuation::acceptedValue($line);
            $value = $f['purchase'] + $f['landed'];
            $subledger += $value;
            $purchaseAll += $f['purchase'];
            $landedAll += $f['landed'];
            $acceptedAll += $accepted;
            $row['unvalued_accepted'] += max(0, $accepted - $f['purchase_valued']);
            $row['over_valued'] += max(0, $f['purchase_valued'] - $accepted);
            $row['lines'][] = ['line_id' => $line->id, 'description' => $line->description, 'accepted_quantity' => (string) $line->usable_quantity, 'valued_quantity' => $f['quantity'],
                'accepted_value' => $accepted, 'purchase_value' => $f['purchase'], 'landed_value' => $f['landed'], 'value' => $value,
                'unit_cost' => InventoryValuation::unitCost($line), 'status' => InventoryValuation::status($accepted, $f['purchase_valued'])];
            if ($value < 0) {
                $row['exceptions'][] = 'Line '.$line->id.' has a negative value.';
            }
            // Value with no goods to carry it is never left unexplained. (Accepted goods whose purchase is not settled yet are "pending": explained.)
            if ($value > 0 && bccomp($f['quantity'], '0', 2) === 0 && $accepted <= 0) {
                $row['exceptions'][] = 'Line '.$line->id.' carries value '.$value.' but has no accepted goods to hold it.';
            }
            if (bccomp($f['quantity'], '0', 2) < 0) {
                $row['exceptions'][] = 'Line '.$line->id.' has a negative quantity.';
            }
        }
        // Per source event: a settlement is applied at most once, and a capitalised cost at most to the amount the journal carries.
        foreach (AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('status', 'verified')->get() as $s) {
            $applied = (int) InventoryValueMovement::where('source_type', 'advance_settlement')->where('source_id', $s->id)->sum('value_delta');
            if ($applied > (int) $s->amount) {
                $row['exceptions'][] = 'Settlement #'.$s->id.' is applied for '.$applied.' but is worth '.$s->amount.' (applied more than once).';
            }
        }
        $capitalised = 0;
        foreach ($lot->costs()->whereNotNull('expense_id')->get() as $cost) {
            $applied = (int) InventoryValueMovement::where('source_type', 'lot_cost')->where('source_id', $cost->id)->where('type', 'add')->sum('value_delta');
            $capitalised += $applied;
            $carried = max(InventoryValuation::inventoryNet('expense', (int) $cost->expense_id), InventoryValuation::inventoryNet('transport_service', $cost->id));
            if ($applied > $carried) {
                $row['exceptions'][] = 'Cost #'.$cost->id.' is applied for '.$applied.' but the journal carries '.$carried.' (applied more than once).';
            }
        }
        if ($row['over_valued'] > 0) {
            // Settled goods that were later found not accepted: the value stays visible until the settlement is reversed or corrected, never hidden.
            $row['exceptions'][] = 'The lot carries value above what was accepted: '.$row['over_valued'].' (an arrival was corrected after settlement; reverse or correct the settlement).';
        }
        $row['unallocated_capitalised'] = self::capitalisedByJournal($lot) - $capitalised; // capitalised in the ledger but with no accepted line to take it yet
        $row['purchase_valued'] = $purchaseAll;
        $row['landed_valued'] = $landedAll;
        $row['accepted_purchase_value'] = $acceptedAll;
        $row['subledger_value'] = $subledger;
        $row['difference'] = $journal - $subledger;
        if ($row['difference'] !== 0) {
            $parts = [];
            if ($row['unvalued_accepted'] > 0) {
                $parts[] = 'accepted goods not valued yet: '.$row['unvalued_accepted'];
            }
            if ($row['unallocated_capitalised'] > 0) {
                $parts[] = 'capitalised cost with no accepted line to carry it: '.$row['unallocated_capitalised'];
            }
            if ($row['over_valued'] > 0) {
                $parts[] = 'value above what was accepted: '.$row['over_valued'];
            }
            $row['exceptions'][] = 'The ledger carries '.$journal.' for this lot, the subledger '.$subledger.' (difference '.$row['difference'].')'.($parts ? ' — '.implode('; ', $parts) : ' — unexplained').'.';
        }
        $row['ok'] = $row['exceptions'] === [] && $row['unvalued_accepted'] === 0;

        return $row;
    }

    /**
     * Every lot, the company total of what the ledger and the subledger say, and what no lot explains.
     *
     * @return array{lots: list<array<string, mixed>>, gl_inventory: int, journal_by_lot: int, unattributed: int, engine_subledger: int, engine_journal: int, engine_unvalued: int, duplicates: int, negative_lines: int, ok: bool}
     */
    public static function company(): array
    {
        $rows = SourcingLot::orderBy('id')->get()->map(fn (SourcingLot $lot) => self::lot($lot))->all();
        $engine = collect($rows)->where('valued', true);
        $duplicates = (int) InventoryValueMovement::selectRaw('move_key, COUNT(*) n')->groupBy('move_key')->havingRaw('COUNT(*) > 1')->get()->count();
        $negative = collect($rows)->sum(fn ($r) => collect($r['lines'])->where('value', '<', 0)->count());
        $gl = Ledger::debitBalance('assets.inventory');
        $byLot = (int) collect($rows)->sum('journal_value');

        return [
            'lots' => $rows, 'gl_inventory' => $gl, 'journal_by_lot' => $byLot, 'unattributed' => $gl - $byLot,
            'engine_subledger' => (int) $engine->sum('subledger_value'), 'engine_journal' => (int) $engine->sum('journal_value'), 'engine_unvalued' => (int) $engine->sum('unvalued_accepted'),
            'duplicates' => $duplicates, 'negative_lines' => $negative,
            'handovers' => $handovers = self::handovers(),
            'ok' => $gl === $byLot && $duplicates === 0 && $negative === 0 && $engine->every(fn ($r) => $r['ok']) && $handovers['ok'],
        ];
    }

    /**
     * Bridge between what sourcing accepted, what the valuation engine counts, and what the physical stock ledger shows, per lot line and by ownership.
     * Differences are reported with their cause and are never corrected automatically: a count difference needs an approved adjustment, not a silent fix.
     *
     * @return list<array<string, mixed>>
     */
    public static function quantities(): array
    {
        $rows = [];
        foreach (SourcingLotLine::with('lot')->orderBy('id')->get() as $line) {
            $lot = $line->lot;
            $enrolment = InventoryEngine::enrolment($lot);
            $balances = Inventory::balances($line);
            $accepted = $line->received_quantity === null ? '0.00' : bcadd((string) $line->usable_quantity, '0', 2);
            $class = match (true) {
                $lot->status === 'cancelled' => 'cancelled',
                $enrolment?->isValued() => 'company_valued',
                $lot->ownership === 'client' || ($enrolment?->status === 'approved' && $enrolment->ownership === 'client') => 'client_custody',
                $lot->ownership === null => 'unclassified',
                default => 'company_not_enrolled',
            };
            $figures = InventoryValuation::lineFigures($line);
            $issued = InventoryValuation::issuedQuantity($line);
            $held = '0';
            foreach (['available', 'reserved', 'returned', 'damaged', 'dispatched'] as $bucket) {
                $held = bcadd($held, $balances[$bucket], 2);
            }
            $differences = [];
            if (bccomp($balances['received'], $accepted, 2) !== 0) {
                $differences[] = 'Physical receipts ('.$balances['received'].') differ from the accepted quantity ('.$accepted.').';
            }
            if (! Inventory::reconciles($line)) {
                $differences[] = 'The physical stock buckets do not add up to what was received and adjusted.';
            }
            if ($class === 'company_valued') {
                $status = InventoryValuation::status(InventoryValuation::acceptedValue($line), $figures['purchase_valued']);
                $expected = $status === 'valued' ? bcsub($accepted, $issued, 2) : '0.00';
                if (bccomp($figures['quantity'], $expected, 2) !== 0) {
                    $differences[] = 'Valued quantity ('.$figures['quantity'].') differs from what the settled, accepted goods imply ('.$expected.').';
                }
                // Goods handed over to a client stay in our warehouse as the client's custody stock, so the physical count is the valued quantity plus what was handed over.
                $expectedHeld = bcadd($figures['quantity'], $issued, 2);
                if ($status === 'valued' && bccomp($held, $expectedHeld, 2) !== 0) {
                    $differences[] = 'The physical count held ('.$held.') differs from the valued quantity ('.$figures['quantity'].') plus goods handed over ('.$issued.'): this needs an approved stock adjustment; nothing is corrected automatically.';
                }
            }
            $rows[] = ['line_id' => $line->id, 'lot' => $lot->number, 'description' => $line->description, 'classification' => $class, 'ordered' => (string) $line->quantity, 'accepted' => $accepted,
                'valued' => $class === 'company_valued' ? $figures['quantity'] : null, 'handed_over' => $issued, 'physical_received' => $balances['received'], 'physical_held' => $held,
                'available' => $balances['available'], 'reserved' => $balances['reserved'], 'delivered' => $balances['delivered'], 'returned' => $balances['returned'], 'damaged' => $balances['damaged'],
                'differences' => $differences, 'ok' => $differences === []];
        }

        return $rows;
    }

    /**
     * Cost of goods sold from handovers, traced from each approved handover to its journal entry and its value movements, and totalled by project and in the
     * ledger. Nothing is posted to close a gap: a difference is reported with the handover it comes from.
     *
     * @return array{rows: list<array<string, mixed>>, by_project: array<int, int>, gl_cogs: int, handover_net_cogs: int, difference: int, problems: list<string>, damaged_awaiting_classification: int, ok: bool}
     */
    public static function handovers(): array
    {
        $rows = [];
        $problems = [];
        $byProject = [];
        $net = 0;
        $damaged = 0;
        foreach (InventoryHandover::with('lot')->where('status', 'approved')->orderBy('id')->get() as $h) {
            $entryIds = JournalEntry::where('source_type', 'inventory_handover')->where('source_id', $h->id)->pluck('id');
            $journal = (int) JournalLine::whereIn('journal_entry_id', $entryIds)->where('account', 'cogs')->sum('debit');
            $moved = -(int) InventoryValueMovement::where('source_type', 'inventory_handover')->where('source_id', $h->id)->whereIn('component', ['purchase', 'landed'])->sum('value_delta');
            $restoredJournal = 0;
            $restoredMoved = 0;
            foreach ($h->returns()->where('status', 'approved')->where('condition', 'resalable')->get() as $r) {
                $ids = JournalEntry::where('source_type', 'inventory_handover_return')->where('source_id', $r->id)->pluck('id');
                $restoredJournal += (int) JournalLine::whereIn('journal_entry_id', $ids)->where('account', 'cogs')->sum('credit');
                $restoredMoved += (int) InventoryValueMovement::where('source_type', 'inventory_handover_return')->where('source_id', $r->id)->whereIn('component', ['purchase', 'landed'])->sum('value_delta');
            }
            $netCogs = (int) $h->cogs_value - $restoredJournal;
            $row = ['handover' => $h->id, 'project_id' => $h->project_id, 'lot' => $h->lot->number, 'quantity' => (string) $h->quantity, 'cogs_value' => (int) $h->cogs_value, 'journal_cogs' => $journal, 'movement_value' => $moved,
                'restored_journal' => $restoredJournal, 'restored_movements' => $restoredMoved, 'net_cogs' => $netCogs, 'issues' => []];
            if ((int) $h->cogs_value !== $journal || $journal !== $moved) {
                $row['issues'][] = 'Handover #'.$h->id.': recorded '.$h->cogs_value.', journal '.$journal.', value movements '.$moved.' do not agree.';
            }
            if ($restoredJournal !== $restoredMoved) {
                $row['issues'][] = 'Handover #'.$h->id.': restored cost in the journal ('.$restoredJournal.') and in the value movements ('.$restoredMoved.') differ.';
            }
            if ($netCogs < 0 || bccomp($h->netQuantity(), '0', 2) < 0) {
                $row['issues'][] = 'Handover #'.$h->id.' has returned more than was handed over.';
            }
            if ($h->lot->ownership !== 'company' || ! InventoryEngine::isValued($h->lot)) {
                $row['issues'][] = 'Handover #'.$h->id.' is on a lot that is not Sellbees-owned and valued by the engine.';
            }
            if (CostRecognition::where('sourcing_lot_id', $h->sourcing_lot_id)->exists()) {
                $row['issues'][] = 'Handover #'.$h->id.' is on a lot whose cost was already released as a whole lot (recognised twice).';
            }
            $problems = [...$problems, ...$row['issues']];
            $byProject[$h->project_id] = ($byProject[$h->project_id] ?? 0) + $netCogs;
            $net += $netCogs;
            $damaged += bccomp((string) $h->damaged_returned_quantity, '0', 2) > 0 ? 1 : 0;
            $rows[] = $row;
        }
        $gl = Ledger::debitBalance('cogs');
        if ($gl !== $net) {
            $problems[] = 'Cost of goods sold in the ledger ('.$gl.') differs from the handovers ('.$net.').';
        }

        return ['rows' => $rows, 'by_project' => $byProject, 'gl_cogs' => $gl, 'handover_net_cogs' => $net, 'difference' => $gl - $net, 'problems' => $problems, 'damaged_awaiting_classification' => $damaged, 'ok' => $problems === []];
    }

    /** Inventory value of one lot as its own journal entries state it (settlements, capitalised costs, transport, whole-lot release, and their reversals). */
    public static function journalValue(SourcingLot $lot): int
    {
        $total = 0;
        foreach (AdvanceSettlement::where('sourcing_lot_id', $lot->id)->whereIn('status', ['verified', 'reversed'])->pluck('id') as $id) {
            $total += InventoryValuation::inventoryNet('advance_settlement', $id) + InventoryValuation::inventoryNet('advance_settlement_rev', $id);
        }
        foreach ($lot->costs()->whereNotNull('expense_id')->get() as $cost) {
            $total += InventoryValuation::inventoryNet('expense', (int) $cost->expense_id) + InventoryValuation::inventoryNet('transport_service', $cost->id);
            foreach (Expense::where('reverses_id', $cost->expense_id)->where('status', 'verified')->pluck('id') as $correctionId) {
                $total += InventoryValuation::inventoryNet('expense', $correctionId);
            }
        }
        foreach (CostRecognition::where('sourcing_lot_id', $lot->id)->pluck('id') as $id) {
            $total += InventoryValuation::inventoryNet('cost_recognition', $id) + InventoryValuation::inventoryNet('cost_recognition_rev', $id);
        }
        foreach (InventoryHandover::where('sourcing_lot_id', $lot->id)->get() as $handover) {
            $total += InventoryValuation::inventoryNet('inventory_handover', $handover->id);
            foreach ($handover->returns()->pluck('id') as $returnId) {
                $total += InventoryValuation::inventoryNet('inventory_handover_return', $returnId);
            }
        }

        return $total;
    }

    /** Capitalised cost (and its corrections) the ledger carries for the lot, apart from purchase value. */
    private static function capitalisedByJournal(SourcingLot $lot): int
    {
        $total = 0;
        foreach ($lot->costs()->whereNotNull('expense_id')->get() as $cost) {
            $total += InventoryValuation::inventoryNet('expense', (int) $cost->expense_id) + InventoryValuation::inventoryNet('transport_service', $cost->id);
            foreach (Expense::where('reverses_id', $cost->expense_id)->where('status', 'verified')->pluck('id') as $correctionId) {
                $total += InventoryValuation::inventoryNet('expense', $correctionId);
            }
        }

        return $total;
    }

    /** Why a lot is outside the engine, in plain words. */
    private static function exclusion(SourcingLot $lot, ?InventoryEngineEnrolment $enrolment): string
    {
        return match (true) {
            $enrolment === null && $lot->ownership === null => 'ownership not stated: classify it through an approved enrolment before it can be valued',
            $enrolment === null && $lot->ownership === 'client' => 'client-owned stock: custody only, not a Sellbees asset',
            $enrolment === null => 'not enrolled in the valuation engine (legacy treatment applies)',
            $enrolment->status === 'pending' => 'enrolment awaiting approval',
            $enrolment->status === 'rejected' => 'enrolment rejected',
            $enrolment->ownership === 'client' => 'client-owned stock: custody only, not a Sellbees asset',
            default => 'outside the engine',
        };
    }
}
