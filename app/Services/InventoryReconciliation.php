<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\InventoryEngineEnrolment;
use App\Models\InventoryValueMovement;
use App\Models\SourcingLot;

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
            $row['unvalued_accepted'] += max(0, $accepted - $f['purchase']);
            $row['over_valued'] += max(0, $f['purchase'] - $accepted);
            $row['lines'][] = ['line_id' => $line->id, 'description' => $line->description, 'accepted_quantity' => (string) $line->usable_quantity, 'valued_quantity' => $f['quantity'],
                'accepted_value' => $accepted, 'purchase_value' => $f['purchase'], 'landed_value' => $f['landed'], 'value' => $value,
                'unit_cost' => InventoryValuation::unitCost($line), 'status' => InventoryValuation::status($accepted, $f['purchase'])];
            if ($value < 0) {
                $row['exceptions'][] = 'Line '.$line->id.' has a negative value.';
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
            'ok' => $gl === $byLot && $duplicates === 0 && $negative === 0 && $engine->every(fn ($r) => $r['ok']),
        ];
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
