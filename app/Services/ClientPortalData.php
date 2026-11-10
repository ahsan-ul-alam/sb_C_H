<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Everything the Client Portal shows, for ONE client, read from the existing records and services (ProjectEconomics, ProjectInventory, SalesAnalytics,
 * ClientSettlementService via ProjectEconomics::clientSettlement). READ-ONLY: nothing here posts, updates or recognises anything. Every query starts from the
 * given client, so a caller can never reach another client's rows, whatever ids it holds.
 *
 * What is deliberately never returned: the supplier unit price and supplier names, supplier contracts, the sourcing margin, Sellbees' own courier cost, KYC
 * identification, internal notes, and a lot's staff-entered title (it can carry the supplier's name). Only a client price that Finance approved (approved_price) is shown.
 */
class ClientPortalData
{
    /** @return array<string, mixed> */
    public static function profile(Client $client): array
    {
        return ['name' => $client->name, 'sb_number' => $client->sb_number, 'email' => $client->email, 'email_verified' => $client->hasVerifiedEmail(),
            'phone' => $client->mobile, 'address' => $client->address, 'country' => $client->country, 'status' => $client->isArchived() ? 'Inactive' : 'Active'];
    }

    /** @return Collection<int, Project> */
    public static function projects(Client $client): Collection
    {
        return $client->projects()->orderByDesc('id')->get();
    }

    /**
     * Stock positions of the client's own and custody stock only (never Sellbees-owned stock or another client's).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function positions(Client $client, ?int $projectId = null): Collection
    {
        return ProjectInventory::positions()->filter(fn ($r) => $r['project'] !== null && $r['project']->client_id === $client->id && in_array($r['ownership'], ['client', 'client_custody'], true)
            && ($projectId === null || $r['project']->id === $projectId))->values();
    }

    /** @return Collection<int, ClientOrder> */
    private static function allOrders(Client $client): Collection
    {
        return ClientOrder::where('fulfilment', 'stock')->whereIn('project_id', $client->projects()->select('id'))->with(['items', 'project'])->get();
    }

    /**
     * Economics per project and client-wide. The sourcing margin and Sellbees' courier cost are dropped here.
     *
     * @return array{projects: list<array<string, mixed>>, total: array<string, mixed>, client_wide: array<string, int>}
     */
    public static function economics(Client $client): array
    {
        $keys = ['investment', 'gross_sales', 'delivery_charges', 'refund_reductions', 'net_sales', 'commission', 'entitlement', 'cost_of_sold', 'investment_recovered', 'client_profit', 'investment_unrecovered'];
        $total = array_fill_keys($keys, 0);
        $pending = false;
        $projects = [];
        foreach (self::projects($client) as $project) {
            $e = ProjectEconomics::for($project);
            $row = ['project' => $project, 'units_sold' => $e['units_sold'], 'cost_pending' => $e['cost_pending'], 'pending_lots' => $e['pending_lots'], 'notes' => $e['notes']];
            foreach ($keys as $k) {
                $row[$k] = $e[$k];
                if ($e[$k] === null) {
                    $pending = true;
                } else {
                    $total[$k] += $e[$k];
                }
            }
            $projects[] = $row;
        }

        return ['projects' => $projects, 'total' => $total + ['pending' => $pending], 'client_wide' => ProjectEconomics::clientSettlement($client)];
    }

    /** @return array<string, mixed> */
    public static function overview(Client $client): array
    {
        $projects = self::projects($client);
        $lotIds = SourcingLot::whereIn('project_id', $projects->pluck('id'))->where('ownership', 'client')->where('status', '!=', 'cancelled')->pluck('id');
        $positions = self::positions($client);
        $sales = SalesAnalytics::figures(self::allOrders($client));
        $e = self::economics($client);

        return [
            'client' => self::profile($client), 'active_projects' => $projects->filter(fn ($p) => $p->status->value === 'active' && ! $p->isArchived())->count(), 'projects' => $projects->count(),
            'sourced_products' => SourcingLotLine::whereIn('sourcing_lot_id', $lotIds)->count(),
            'available_stock' => $positions->reduce(fn ($c, $r) => bcadd($c, $r['b']['available'], 2), '0'),
            'orders' => $sales['counts']['total'], 'successful_orders' => $sales['successful_orders'], 'successful_sales' => $sales['totals']['sales'],
            'economics' => $e['total'], 'client_wide' => $e['client_wide'],
        ];
    }

    /**
     * Lots of the client's projects with their products and quantities. A price is shown only when Finance approved it for the client.
     *
     * @return list<array<string, mixed>>
     */
    public static function lots(Client $client, ?int $projectId = null): array
    {
        $projects = self::projects($client)->pluck('id');
        $positions = self::positions($client, $projectId)->groupBy(fn ($r) => $r['line']->id);
        $out = [];
        foreach (SourcingLot::whereIn('project_id', $projects)->when($projectId, fn ($q) => $q->where('project_id', $projectId))->where('ownership', 'client')->where('status', '!=', 'cancelled')->with(['project', 'lines'])->orderByDesc('id')->get() as $lot) {
            $lines = $lot->lines->map(function (SourcingLotLine $l) use ($positions) {
                $b = array_fill_keys(['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'], '0');
                foreach ($positions->get($l->id, collect()) as $r) {
                    foreach ($b as $k => $v) {
                        $b[$k] = bcadd($v, $r['b'][$k], 2);
                    }
                }

                return ['product' => $l->description, 'unit' => $l->unit ?? 'pcs', 'client_price' => $l->approved_price === null ? null : (int) $l->approved_price, 'ordered' => (string) $l->quantity,
                    'received' => $l->received_quantity === null ? null : (string) $l->received_quantity, 'accepted' => $l->received_quantity === null ? null : (string) $l->usable_quantity, 'buckets' => $b];
            })->all();
            $out[] = ['lot' => $lot, 'number' => $lot->number, 'status' => $lot->status, 'project' => $lot->project, 'lines' => $lines];
        }

        return $out;
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public static function orders(Client $client, Request $request): LengthAwarePaginator
    {
        $q = trim((string) $request->query('q'));
        $status = (string) $request->query('status');
        $project = (int) $request->query('project');
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $page = ClientOrder::where('fulfilment', 'stock')->whereIn('project_id', $client->projects()->select('id'))->with(['items', 'project', 'collections'])
            ->when($project > 0, fn ($b) => $b->where('project_id', $project))
            ->when(in_array($status, ['confirmed', 'packed', 'dispatched', 'delivered', 'failed', 'cancelled'], true), fn ($b) => $b->where('delivery_status', $status))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('tracking_reference', 'like', $like)->orWhereHas('items', fn ($i) => $i->where('description', 'like', $like))))
            ->orderByDesc('ordered_on')->orderByDesc('id')->paginate(15)->withQueryString();

        return $page->through(function (ClientOrder $o) {
            $f = ProjectEconomics::orderFigures($o);
            $delivered = $o->items->reduce(fn ($c, $i) => bcadd($c, bcsub((string) $i->delivered_quantity, (string) $i->returned_quantity, 2), 2), '0');
            $returned = $o->items->reduce(fn ($c, $i) => bcadd($c, (string) $i->returned_quantity, 2), '0');
            $collected = (int) $o->collections->sum('amount');
            $collectible = $o->collectibleValue();

            return ['order' => $o, 'products' => $o->items->map(fn ($i) => $i->description.' × '.rtrim(rtrim((string) $i->quantity, '0'), '.'))->implode(', '), 'delivered_units' => $delivered, 'returned_units' => $returned,
                'delivery_charge' => $f['eligible'], 'commission' => $f['commission'], 'entitlement' => $f['entitlement'], 'refunds' => $f['refunds'], 'sales' => $f['sales'],
                'collection' => $o->delivery_status !== 'delivered' ? 'Not due yet' : ($collectible <= 0 ? 'Nothing to collect' : ($collected >= $collectible ? 'Collected' : ($collected > 0 ? 'Partly collected' : 'Awaiting collection'))),
                'return_status' => bccomp($returned, '0', 2) > 0 ? ($f['refunds'] > 0 ? 'Returned, refund recorded' : 'Returned') : '—'];
        });
    }

    /** @return Collection<int, string> */
    public static function orderStatuses(): Collection
    {
        return collect(['confirmed' => 'Confirmed', 'packed' => 'Packed', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'failed' => 'Failed delivery', 'cancelled' => 'Cancelled']);
    }

    /** @return LengthAwarePaginator<int, Invoice> */
    public static function invoices(Client $client): LengthAwarePaginator
    {
        return self::invoiceQuery($client)->with(['project', 'payments'])->orderByDesc('issued_on')->orderByDesc('id')->paginate(15);
    }

    /** The client's own invoice, or null: a number from another client never resolves. */
    public static function invoice(Client $client, int $id): ?Invoice
    {
        return self::invoiceQuery($client)->whereKey($id)->first();
    }

    private static function invoiceQuery(Client $client)
    {
        return Invoice::whereIn('project_id', $client->projects()->select('id'));
    }
}
