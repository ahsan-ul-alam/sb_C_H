<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\ClientSettlement;
use App\Models\Project;
use App\Models\SourcingAllocation;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * One project's money in the owner's model, READ-ONLY, in integer minor units, computed on the server from the order lines, the stock ledger and the existing
 * lot, commission and settlement records. It posts nothing and rewrites nothing; staff never recalculate.
 *
 *   gross successful sales            units delivered and not returned x the net selling price
 *   - refunds that reduce the sale    (paid refunds Finance classified as reducing the sale)
 *   - client-borne delivery charge    recorded once at dispatch, shared over what was delivered (ClientOrder::eligibleDeliveryCharge)
 *   = net successful sales            = the orders' commission base
 *   - Sellbees commission             the order's own snapshotted commission
 *
 *   client PROFIT      = net successful sales - commission - client-borne courier charges of failed deliveries and returns - cost of the units sold
 *   client ENTITLEMENT = gross sales - refunds - commission - what the charge's settlement route deducts (ClientOrder::settlementDeduction)
 *
 * The two differ on purpose. Profit deducts every client-borne charge once, whoever paid the courier. Entitlement (what Sellbees owes the client) deducts it once
 * only when the money never reaches the client whole: the courier kept it, Sellbees paid it, or it is still payable. A charge the client paid the courier directly is
 * not deducted from the client's entitlement again, and one Sellbees bears is deducted from neither.
 *
 * Cost of units sold is the APPROVED client price of the line (the approval snapshot), only for units covered by a billed allocation at that exact price. Where a sold
 * unit lacks that evidence, cost, profit and recovery are reported as pending verification, never as an exact-looking number. A draft price is never used.
 *
 * A settlement pays the client's whole held balance and is not tied to a project, so what was settled and what is outstanding are reported client-wide and labelled so;
 * nothing is allocated to a project by guesswork.
 */
class ProjectEconomics
{
    /** @return array<string, mixed> */
    public static function for(Project $project): array
    {
        $client = $project->client ?? Client::findOrFail($project->client_id);
        $clientProjectIds = $client->projects()->pluck('id');
        $orders = ClientOrder::where('project_id', $project->id)->where('fulfilment', 'stock')->with(['items.line.lot', 'invoice'])->orderBy('id')->get();
        $acquisition = [];
        $rows = [];
        $notes = [];
        $t = array_fill_keys(['gross', 'delivery', 'refunds', 'net', 'commission', 'invoiced', 'unsuccessful', 'cost_sold', 'estimate', 'entitlement', 'recoverable', 'courier_cost', 'courier_refunded'], 0);
        $t['units_sold'] = '0';
        $costPending = false;

        foreach ($orders as $o) {
            $f = self::orderFigures($o);
            $units = '0';
            $cost = 0;
            foreach ($o->items as $i) {
                $sold = bcsub((string) $i->delivered_quantity, (string) $i->returned_quantity, 2);
                $units = bcadd($units, $sold, 2);
                if (bccomp($sold, '0', 2) <= 0) {
                    continue;
                }
                $line = $i->line;
                $a = $line ? ($acquisition[$line->id] ??= self::approvedUnitCost($line, $clientProjectIds)) : ['reason' => 'is not linked to a stock lot line'];
                if (isset($a['reason'])) {
                    $costPending = true;
                    $notes[] = 'Order '.$o->reference.': the cost of "'.$i->description.'" '.$a['reason'].'.';

                    continue;
                }
                $cost += Money::multiply($sold, $a['unit']);
            }
            if ($o->delivery_status === 'delivered' && $f['net'] !== (int) $o->net_value) {
                $notes[] = 'Order '.$o->reference.': its stored commission base ('.Money::format((int) $o->net_value).') differs from the calculated net sales ('.Money::format($f['net']).'); an earlier-rule order is kept as recorded.';
            }
            if ($o->delivery_charge > 0 && $o->delivery_charge_bearer === 'client' && $o->delivery_charge_status === 'payable' && $o->delivery_charge_recorded_at !== null) {
                $notes[] = 'Order '.$o->reference.': the delivery charge is recorded but nobody is recorded as having paid it yet (status "not yet paid"); it is deducted from the client once, but how it is settled is still open.';
            }
            $estimate = in_array($o->delivery_status, ['confirmed', 'packed', 'dispatched'], true)
                ? OrderCommission::commission(max(0, $o->orderedValue() - $o->eligibleDeliveryCharge($o->orderedValue())), OrderCommission::rateFor($project)) : 0;

            $rows[] = ['order' => $o, 'units' => $units, 'gross' => $f['sales'], 'delivery' => $f['eligible'], 'net' => $f['net'], 'commission' => $f['commission'], 'cost' => $cost, 'entitlement' => $f['entitlement']];
            foreach (['gross' => 'sales', 'delivery' => 'eligible', 'refunds' => 'refunds', 'net' => 'net', 'commission' => 'commission', 'unsuccessful' => 'unsuccessful', 'entitlement' => 'entitlement'] as $to => $from) {
                $t[$to] += $f[$from];
            }
            $t['invoiced'] += $o->billed_invoice_id ? $f['commission'] : 0;
            $t['cost_sold'] += $cost;
            $t['estimate'] += $estimate;
            $t['recoverable'] += $o->recoverableCharge();
            $t['courier_cost'] += $o->delivery_status === 'delivered' ? $o->unrecoveredCourierCost() : 0;
            $t['courier_refunded'] += (int) $o->courier_refund;
            $t['units_sold'] = bcadd($t['units_sold'], $units, 2);
        }

        // Investment and sourcing margin only from lots whose client price is approved and billed; anything else is pending, not guessed from a draft price.
        $investment = 0;
        $margin = 0;
        $pendingLots = 0;
        foreach (SourcingLot::where('project_id', $project->id)->where('ownership', 'client')->where('status', '!=', 'cancelled')->with('lines')->get() as $lot) {
            $approved = $lot->lines->isNotEmpty() && $lot->lines->every(fn ($l) => $l->approved_price !== null);
            $billed = SourcingAllocation::where('sourcing_lot_id', $lot->id)->where('project_id', $project->id)->where('kind', 'sale')->whereNotNull('billed_invoice_id')->get();
            if (! $approved || $billed->isEmpty()) {
                $pendingLots++;

                continue;
            }
            $investment += (int) $billed->sum(fn ($a) => $a->amount());
            $margin += (int) LotFinancials::for($lot)['profit'];
        }
        if ($pendingLots > 0) {
            $notes[] = $pendingLots.' lot(s) of this project have no approved and billed client price yet, so their investment and sourcing margin are pending verification.';
        }

        $recovered = $costPending ? null : min($t['cost_sold'], max(0, $t['entitlement']));

        return [
            'rows' => $rows, 'notes' => $notes, 'investment' => $investment, 'units_sold' => $t['units_sold'], 'pending_lots' => $pendingLots,
            'gross_sales' => $t['gross'], 'delivery_charges' => $t['delivery'], 'refund_reductions' => $t['refunds'], 'net_sales' => $t['net'],
            'commission' => $t['commission'], 'commission_invoiced' => $t['invoiced'], 'commission_estimate' => $t['estimate'], 'unsuccessful_charges' => $t['unsuccessful'],
            'entitlement' => $t['entitlement'], 'cost_pending' => $costPending, 'cost_of_sold' => $costPending ? null : $t['cost_sold'],
            'investment_recovered' => $recovered, 'client_profit' => $costPending ? null : $t['net'] - $t['commission'] - $t['unsuccessful'] - $t['cost_sold'],
            'investment_unrecovered' => $recovered === null ? null : max(0, $investment - $recovered),
            'courier_advance_withheld' => $t['recoverable'], 'sellbees_courier_cost' => $t['courier_cost'], 'courier_refunded' => $t['courier_refunded'],
            'sourcing_margin' => $margin, 'client' => self::clientSettlement($client),
        ];
    }

    /**
     * Every figure of one order, in one place, so the order, the project and the client always add up to the same numbers.
     *
     * @return array{sales: int, refunds: int, eligible: int, net: int, commission: int, unsuccessful: int, deduction: int, entitlement: int}
     */
    public static function orderFigures(ClientOrder $o): array
    {
        $delivered = $o->delivery_status === 'delivered';
        $sales = $delivered ? $o->deliveredValue() : 0;
        $eligible = $o->eligibleDeliveryCharge($sales);
        $refunds = $delivered ? OrderRefunds::baseReduction($o) : 0;
        $commission = $o->status === 'successful' ? (int) $o->commission : 0;
        $deduction = $o->settlementDeduction($eligible);

        return ['sales' => $sales, 'refunds' => $refunds, 'eligible' => $eligible, 'net' => $delivered ? max(0, $sales - $eligible - $refunds) : 0, 'commission' => $commission,
            'unsuccessful' => $o->unsuccessfulCharges(), 'deduction' => $deduction, 'entitlement' => $sales - $refunds - $commission - $deduction];
    }

    /**
     * The approved client price of a line and proof it was billed at exactly that price. Draft (proposed) prices are never accepted.
     *
     * @param  Collection<int, int>  $clientProjectIds
     * @return array{unit: int, qty: string}|array{reason: string}
     */
    private static function approvedUnitCost(SourcingLotLine $line, Collection $clientProjectIds): array
    {
        if ($line->lot->ownership !== 'client') {
            return ['reason' => 'comes from a lot whose ownership is '.($line->lot->ownership ?? 'not verified').', so no client acquisition cost applies'];
        }
        if ($line->approved_price === null) {
            return ['reason' => 'has no approved client price (cost pending verification; a draft price is not used)'];
        }
        $allocations = SourcingAllocation::where('sourcing_lot_line_id', $line->id)->where('kind', 'sale')->whereIn('project_id', $clientProjectIds)->whereNotNull('billed_invoice_id')->get();
        if ($allocations->isEmpty()) {
            return ['reason' => 'was never billed to the client (cost pending verification)'];
        }
        if ($allocations->contains(fn ($a) => (int) $a->unit_price !== (int) $line->approved_price)) {
            return ['reason' => 'was billed at a price that differs from the approved price (cost pending verification)'];
        }

        return ['unit' => (int) $line->approved_price, 'qty' => $allocations->reduce(fn ($c, $a) => bcadd($c, (string) $a->quantity, 2), '0')];
    }

    /**
     * Client-wide: everything the client's projects earned for them, what has really been paid out or deducted since, and what is still owed. A settlement is not
     * tied to a project, so the settled and outstanding amounts here are explicitly client-wide and unallocated. The cash available now is the existing settlement's
     * figure (money collected and still held), which can be lower while courier remittance is pending.
     *
     * @return array{entitlement: int, settled: int, deductions_applied: int, outstanding: int, cash_available: int, collected: int, pending_remittance: int}
     */
    public static function clientSettlement(Client $client): array
    {
        $entitlement = 0;
        foreach (ClientOrder::where('fulfilment', 'stock')->whereIn('project_id', $client->projects()->select('id'))->with('items')->get() as $o) {
            $entitlement += self::orderFigures($o)['entitlement'];
        }
        $paid = (int) ClientSettlement::where('client_id', $client->id)->sum('paid');
        // Commission and courier recoveries are already inside the entitlement (deducted once there); only storage fees netted at settlement are a further deduction.
        $netted = (int) ClientSettlement::where('client_id', $client->id)->sum('storage_netted');
        $statement = ClientSettlementService::statement($client);

        return ['entitlement' => $entitlement, 'settled' => $paid, 'deductions_applied' => $netted, 'outstanding' => $entitlement - $paid - $netted,
            'cash_available' => max(0, (int) $statement['payable']), 'collected' => (int) $statement['collected'],
            'pending_remittance' => max(0, $entitlement - $paid - $netted - max(0, (int) $statement['payable']))];
    }
}
