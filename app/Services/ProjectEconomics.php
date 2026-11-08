<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\ClientSettlement;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Support\Money;

/**
 * One project's money in the owner's model, READ-ONLY, in integer minor units, computed on the server from the order lines, the stock ledger and the existing
 * lot, commission and settlement records. It posts nothing and rewrites nothing; staff never recalculate.
 *
 *   client investment                 what the lots of this project were billed to the client for (client price x quantity)
 *   gross successful sales            units delivered and not returned x the net selling price
 *   - delivery charges                the client-borne charge, recorded once at dispatch and shared over what was delivered (ClientOrder::eligibleDeliveryCharge)
 *   - refunds that reduce the sale    (paid refunds Finance classified as reducing the sale)
 *   = net successful sales            = the orders' commission base (checked against their stored net value)
 *   - Sellbees commission             the order's own snapshotted commission (the existing commission code); zero-rate contracts stay valid
 *   - courier charges on unsuccessful the failed-delivery and return charges, when client-borne
 *   = client settlement ENTITLEMENT   what the sales earned for the client; the client's product cost is NOT deducted again (it was paid at sourcing)
 *   - cost of the units sold          sold units x the client price of their lot line, deducted ONCE, only for profit
 *   = client product PROFIT
 * Settlement is client-wide (a settlement is not tied to one project), so what has been settled and what is outstanding are reported for the whole client.
 */
class ProjectEconomics
{
    /** @return array<string, mixed> */
    public static function for(Project $project): array
    {
        $orders = ClientOrder::where('project_id', $project->id)->where('fulfilment', 'stock')->with(['items.line', 'invoice'])->orderBy('id')->get();
        $rows = [];
        $t = array_fill_keys(['gross', 'delivery', 'refunds', 'net', 'commission', 'invoiced', 'unsuccessful', 'cost_sold', 'estimate', 'unverified'], 0);
        $t['units_sold'] = '0';
        $notes = [];

        foreach ($orders as $o) {
            $sales = $o->delivery_status === 'delivered' ? $o->deliveredValue() : 0;
            $charge = $o->eligibleDeliveryCharge($sales);
            $refunds = $o->delivery_status === 'delivered' ? OrderRefunds::baseReduction($o) : 0;
            $net = $o->delivery_status === 'delivered' ? max(0, $sales - $charge - $refunds) : 0;
            $units = '0';
            $cost = 0;
            foreach ($o->items as $i) {
                $sold = bcsub((string) $i->delivered_quantity, (string) $i->returned_quantity, 2);
                $units = bcadd($units, $sold, 2);
                $price = $i->line?->clientPrice();
                if (bccomp($sold, '0', 2) > 0 && $price === null) {
                    $t['unverified']++;
                    $notes[] = 'Order '.$o->reference.': "'.$i->description.'" has no client price, so its cost cannot be shown.';
                }
                $cost += $price !== null ? Money::multiply($sold, $price) : 0;
            }
            $commission = $o->status === 'successful' ? (int) $o->commission : 0;
            $unsuccessful = $o->delivery_charge_bearer === 'client' ? (int) $o->failed_delivery_charge + (int) $o->return_charge : 0;
            if ($o->delivery_status === 'delivered' && $net !== (int) $o->net_value) {
                $notes[] = 'Order '.$o->reference.': its stored commission base ('.Money::format((int) $o->net_value).') differs from the calculated net sales ('.Money::format($net).'); an earlier-rule order is kept as recorded.';
            }
            $estimate = in_array($o->delivery_status, ['confirmed', 'packed', 'dispatched'], true)
                ? OrderCommission::commission(max(0, $o->orderedValue() - $o->eligibleDeliveryCharge($o->orderedValue())), OrderCommission::rateFor($o->project ?? $project)) : 0;

            $rows[] = ['order' => $o, 'units' => $units, 'gross' => $sales, 'delivery' => $charge, 'net' => $net, 'commission' => $commission, 'cost' => $cost, 'entitlement' => $net - $commission - $unsuccessful];
            $t['gross'] += $sales;
            $t['delivery'] += $charge;
            $t['refunds'] += $refunds;
            $t['net'] += $net;
            $t['commission'] += $commission;
            $t['invoiced'] += $o->billed_invoice_id ? $commission : 0;
            $t['unsuccessful'] += $unsuccessful;
            $t['cost_sold'] += $cost;
            $t['estimate'] += $estimate;
            $t['units_sold'] = bcadd($t['units_sold'], $units, 2);
        }

        $entitlement = $t['net'] - $t['commission'] - $t['unsuccessful'];
        $lots = SourcingLot::where('project_id', $project->id)->where('ownership', 'client')->where('status', '!=', 'cancelled')->get();
        $investment = 0;
        $margin = 0;
        foreach ($lots as $lot) {
            $f = LotFinancials::for($lot);
            $investment += (int) $f['sales_value'];
            $margin += (int) $f['profit'];
        }

        return [
            'rows' => $rows, 'notes' => $notes, 'investment' => $investment, 'units_sold' => $t['units_sold'],
            'gross_sales' => $t['gross'], 'delivery_charges' => $t['delivery'], 'refund_reductions' => $t['refunds'], 'net_sales' => $t['net'],
            'commission' => $t['commission'], 'commission_invoiced' => $t['invoiced'], 'commission_estimate' => $t['estimate'], 'unsuccessful_charges' => $t['unsuccessful'],
            'entitlement' => $entitlement, 'cost_of_sold' => $t['cost_sold'],
            // The sold units' cost is recovered out of what the sales earned for the client; whatever the sales earned beyond it is profit.
            'investment_recovered' => min($t['cost_sold'], max(0, $entitlement)), 'client_profit' => $entitlement - $t['cost_sold'],
            'investment_unrecovered' => max(0, $investment - min($t['cost_sold'], max(0, $entitlement))),
            'sourcing_margin' => $margin, 'client' => self::clientSettlement($project->client ?? Client::findOrFail($project->client_id)),
        ];
    }

    /**
     * Client-wide: everything the client's projects earned for them, what has really been paid out or deducted since, and what is still owed. The cash that is
     * available now is the existing settlement's figure (money actually collected and still held), which can be lower while courier remittance is pending.
     *
     * @return array{entitlement: int, settled: int, deductions_applied: int, outstanding: int, cash_available: int, collected: int, pending_remittance: int}
     */
    public static function clientSettlement(Client $client): array
    {
        $entitlement = 0;
        foreach ($client->projects()->get() as $p) {
            $x = self::forEntitlementOnly($p);
            $entitlement += $x;
        }
        $paid = (int) ClientSettlement::where('client_id', $client->id)->sum('paid');
        $storage = (int) ClientSettlement::where('client_id', $client->id)->sum('storage_netted');
        $statement = ClientSettlementService::statement($client);

        return ['entitlement' => $entitlement, 'settled' => $paid, 'deductions_applied' => $storage, 'outstanding' => $entitlement - $paid - $storage,
            'cash_available' => max(0, (int) $statement['payable']), 'collected' => (int) $statement['collected'],
            'pending_remittance' => max(0, $entitlement - $paid - $storage - max(0, (int) $statement['payable']))];
    }

    private static function forEntitlementOnly(Project $project): int
    {
        $orders = ClientOrder::where('project_id', $project->id)->where('fulfilment', 'stock')->with('items')->get();
        $sum = 0;
        foreach ($orders as $o) {
            $sales = $o->delivery_status === 'delivered' ? $o->deliveredValue() : 0;
            $net = $o->delivery_status === 'delivered' ? max(0, $sales - $o->eligibleDeliveryCharge($sales) - OrderRefunds::baseReduction($o)) : 0;
            $sum += $net - ($o->status === 'successful' ? (int) $o->commission : 0) - ($o->delivery_charge_bearer === 'client' ? (int) $o->failed_delivery_charge + (int) $o->return_charge : 0);
        }

        return $sum;
    }
}
