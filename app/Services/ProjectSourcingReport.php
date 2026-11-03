<?php

namespace App\Services;

use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Support\Costing;

/**
 * Project-wise product sourcing figures, kept apart on purpose (the same amount is never reported as two things):
 *
 *   agreed            what clients agreed to pay for the products (sale allocations + agreed cost recoveries)
 *   billed / received invoiced to the client / collected from the client (pro-rata share of each invoice)
 *   cost              the project's share of the lots' landed cost: supplier purchase + every attributable acquisition cost up to
 *                     arrival at Sellbees's room (transport, loading, handling…), exact to the paisa
 *   estimated margin  agreed − cost                       the business's sourcing metric (accrual view, set by LotFinancials)
 *   recognised margin the estimated margin of lots that are COMPLETED or SETTLED, nothing before   (proposed policy, see below)
 *   cash surplus      received − supplier payments made − acquisition costs paid                      (a cash figure, not profit)
 *
 * Landed cost of each line is split over the quantities it is allocated to (largest remainder, so no paisa is lost) plus an
 * "unallocated" bucket for stock not yet promised to a client; that bucket is never charged to a project. Supplier payments,
 * unpaid supplier balance, paid and unpaid acquisition costs and open estimates of a lot follow the same weights, so every column
 * ties back to the lot totals exactly. Nothing here posts to the ledger.
 *
 * Recognition policy (proposed, needs Board approval): margin is recognised when the lot is completed (goods delivered under the
 * existing lot lifecycle), never merely because cash arrived or goods reached the room.
 */
class ProjectSourcingReport
{
    private const FIELDS = ['agreed', 'billed', 'received', 'cost', 'supplier_paid', 'supplier_outstanding', 'costs_paid', 'costs_unpaid', 'estimates_open', 'recognised_cost', 'purchase_cost'];

    /**
     * @return array{projects: array<int, array<string, mixed>>, lots: list<array<string, mixed>>}
     */
    public static function build(): array
    {
        $projects = [];
        $lots = [];
        foreach (SourcingLot::where('status', '!=', 'cancelled')->orderBy('id')->get() as $lot) {
            $figures = LotFinancials::for($lot);
            $buckets = self::buckets($lot, $figures);
            $lots[] = ['lot' => $lot, 'figures' => $figures, 'buckets' => $buckets, 'quantities' => self::quantities($figures)];
            $done = in_array($lot->status, ['completed', 'settled'], true);
            // Cash basis (every existing record): a completed lot's margin is recognised in full (proposed policy). Accrual sourcing basis:
            // only once the Board-approved cost release has been verified, and then against the cost actually released.
            $accrual = config('finance.sourcing_basis') === 'accrual' && SourcingBasis::accrualFrom() !== null;
            $recognised = $accrual ? $lot->costRecognitions()->where('status', 'verified')->exists() : $done;
            // A figure is final only when the lot is finished, every line has arrived, no cost is still an estimate and no advance is held.
            $final = $done && ! $lot->lines()->whereNull('received_quantity')->exists() && (int) $figures['estimates_open'] === 0 && AdvanceSettlementService::unsettled($lot) === 0;
            foreach ($buckets as $key => $b) {
                if ($key === 'unallocated') {
                    continue;
                }
                $p = $projects[$key] ?? array_fill_keys(self::FIELDS, 0) + ['recognised_margin' => 0, 'lots' => [], 'final' => true];
                foreach (self::FIELDS as $f) {
                    $p[$f] += $b[$f];
                }
                $p['recognised_margin'] += $recognised ? $b['agreed'] - ($accrual ? $b['recognised_cost'] : $b['cost']) : 0;
                $p['final'] = $p['final'] && $final;
                $p['lots'][] = $lot->number;
                $projects[$key] = $p;
            }
        }
        foreach ($projects as &$p) {
            $p['margin_status'] = $p['final'] ? 'final' : 'provisional'; // never present an incomplete acquisition as a settled profit
            $p['actual_margin'] = $p['agreed'] - ($p['cost'] - $p['estimates_open']);
            // "cost" includes estimates for costs not incurred yet; "actual_cost" is only what has really been incurred up to arrival.
            $p['actual_cost'] = $p['cost'] - $p['estimates_open'];
            $p['supplier_payable'] = $p['supplier_outstanding']; // owed, whether or not it has been paid yet: an incurred cost never waits for payment
            $p['estimated_margin'] = $p['agreed'] - $p['cost'];
            $p['client_outstanding'] = $p['agreed'] - $p['received'];
            $p['receivable'] = $p['billed'] - $p['received'];
            $p['cash_surplus'] = $p['received'] - $p['supplier_paid'] - $p['costs_paid'];
        }
        unset($p);

        return ['projects' => $projects, 'lots' => $lots];
    }

    /**
     * Per project id (and "unallocated") shares of one lot. Every column sums back to the lot's own total.
     *
     * @param  array<string, mixed>  $f  LotFinancials::for($lot)
     * @return array<int|string, array<string, int|string>>
     */
    public static function buckets(SourcingLot $lot, array $f): array
    {
        $blank = array_fill_keys(self::FIELDS, 0) + ['quantity' => '0.00'];
        $b = ['unallocated' => $blank];

        // 1. Landed cost of every line over the quantities it is allocated to.
        foreach ($f['lines'] as $lineId => $row) {
            $weights = [];
            foreach ($lot->allocations->where('kind', 'sale')->where('sourcing_lot_line_id', $lineId) as $a) {
                $weights[$a->project_id] = ($weights[$a->project_id] ?? 0) + (int) bcmul((string) $a->quantity, '100', 0);
            }
            $weights['unallocated'] = (int) bcmul((string) $row['unallocated_qty'], '100', 0);
            if (array_sum($weights) <= 0) {
                $weights = ['unallocated' => 1];
            }
            foreach (Costing::allocateShared((int) $row['landed'], $weights) as $key => $share) {
                $b[$key] ??= $blank;
                $b[$key]['cost'] += $share;
            }
            // The supplier purchase price alone, split the same way (so purchase + handling always equals the landed cost, to the paisa).
            foreach (Costing::allocateShared((int) $row['purchase'], $weights) as $key => $share) {
                $b[$key] ??= $blank;
                $b[$key]['purchase_cost'] += $share;
            }
        }

        // 2. Agreed, billed and received amounts belong to the client's own allocations.
        foreach ($lot->allocations as $a) {
            $b[$a->project_id] ??= $blank;
            $b[$a->project_id]['agreed'] += $a->amount();
            if ($a->kind === 'sale') {
                $b[$a->project_id]['quantity'] = bcadd($b[$a->project_id]['quantity'], (string) $a->quantity, 2);
            }
        }
        foreach ($lot->allocations->whereNotNull('billed_invoice_id')->groupBy(fn ($a) => $a->project_id.'|'.$a->billed_invoice_id) as $group) {
            $part = (int) $group->sum(fn ($a) => $a->amount());
            $invoice = $group->first()->invoice;
            $projectId = $group->first()->project_id;
            $b[$projectId]['billed'] += $part;
            $b[$projectId]['received'] += $invoice && $invoice->total > 0 ? min($part, intdiv($invoice->amount_paid * $part, $invoice->total)) : 0;
        }

        // 3. Money the lot paid or still owes follows each bucket's share of the cost.
        $replaced = $lot->costs->pluck('replaces_id')->filter()->all();
        $current = $lot->costs->reject(fn ($c) => in_array($c->id, $replaced, true));
        $totals = [
            'supplier_paid' => (int) $f['supplier_paid'], 'supplier_outstanding' => (int) $f['supplier_outstanding'],
            'costs_paid' => (int) $current->where('kind', 'actual')->filter(fn (SourcingLotCost $c) => $c->isPaid())->sum('amount'),
            'costs_unpaid' => (int) $f['costs_unpaid'], 'estimates_open' => (int) $f['estimates_open'],
            'recognised_cost' => (int) $lot->costRecognitions()->where('status', 'verified')->sum('amount'),
        ];
        $weights = array_map(fn ($x) => max(0, (int) $x['cost']), $b);
        if (array_sum($weights) <= 0) {
            $weights = ['unallocated' => 1];
        }
        foreach ($totals as $field => $amount) {
            foreach (Costing::allocateShared($amount, $weights) as $key => $share) {
                $b[$key][$field] += $share;
            }
        }

        return $b;
    }

    /**
     * Quantity integrity per product line. A quantity that has not been recorded is null, never assumed.
     *
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    public static function quantities(array $f): array
    {
        return collect($f['lines'])->map(function ($row) {
            $line = $row['line'];
            $received = $line->received_quantity;

            return [
                'line' => $line, 'purchased' => (string) $line->quantity,
                'received' => $received === null ? null : (string) $received,
                'accepted' => (string) $line->usable_quantity,
                'not_usable' => bcsub((string) $line->quantity, (string) $line->usable_quantity, 2),
                // Of what arrived: accepted + rejected = received. Of what was ordered: received + missing = purchased. Unknown until arrival is recorded.
                'rejected' => $received === null ? null : bcsub((string) $received, (string) $line->usable_quantity, 2),
                'missing' => $received === null ? null : bcsub((string) $line->quantity, (string) $received, 2),
                'acceptance_reference' => $line->acceptance_reference,
                'pending' => $received === null ? null : bcsub((string) $line->quantity, (string) $received, 2),
                'allocated' => $row['allocated_qty'], 'unallocated' => $row['unallocated_qty'], 'arrived_on' => $line->arrived_on,
            ];
        })->values()->all();
    }
}
