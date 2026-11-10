<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SourcingAllocation;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Support\Money;
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
     * Stock positions of the lots attached to the client's projects: the client's own and custody stock, plus received stock whose ownership has not been stated yet
     * (older lots; ownership 'unstated', never counted as verified client stock). Never Sellbees-owned stock or another client's.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function positions(Client $client, ?int $projectId = null): Collection
    {
        return ProjectInventory::positions([], true)->filter(fn ($r) => $r['project'] !== null && $r['project']->client_id === $client->id && in_array($r['ownership'], ['client', 'client_custody', 'unstated'], true)
            && ($projectId === null || $r['project']->id === $projectId))->values();
    }

    /**
     * Splits positions into verified client stock and stock whose ownership is still to be confirmed.
     *
     * @param  Collection<int, array<string, mixed>>  $positions
     * @return array{confirmed: Collection<int, array<string, mixed>>, awaiting: Collection<int, array<string, mixed>>}
     */
    public static function splitByOwnership(Collection $positions): array
    {
        return ['confirmed' => $positions->reject(fn ($r) => $r['ownership'] === 'unstated')->values(), 'awaiting' => $positions->filter(fn ($r) => $r['ownership'] === 'unstated')->values()];
    }

    /** Lots that belong on the client's projects: client-owned, or with ownership not yet stated. Company-owned lots are never the client's. */
    private static function clientLotQuery(Client $client, ?int $projectId = null)
    {
        return SourcingLot::whereIn('project_id', $client->projects()->select('id'))->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where(fn ($q) => $q->where('ownership', 'client')->orWhereNull('ownership'))->where('status', '!=', 'cancelled');
    }

    /**
     * What the client has been billed for products and what has been paid, kept apart from the approved investment:
     *   linked     invoices that sourcing billing allocations point at (the authoritative product billing). Fully product invoices give billed / paid / outstanding;
     *              a mixed invoice (product plus other items) is shown separately because a payment applies to the whole invoice, never to one item.
     *   other      invoices with no allocation behind them (joining fees, services, or product invoices raised before billing was linked to a lot). Never labelled product.
     *   awaiting   lots on the client's projects that cannot yet count as verified investment, each with the reason (ownership not stated, client price not approved, not billed).
     * Paid is the invoice's verified paid amount (voided payments excluded). Nothing here posts or rewrites anything.
     *
     * @return array<string, mixed>
     */
    public static function productBilling(Client $client): array
    {
        $projectIds = $client->projects()->pluck('id');
        $allocations = SourcingAllocation::whereIn('project_id', $projectIds)->where('kind', 'sale')->whereNotNull('billed_invoice_id')->get();
        $linked = $allocations->groupBy('billed_invoice_id')->map(fn ($g) => (int) $g->sum(fn ($a) => $a->amount()));
        $out = ['billed' => 0, 'paid' => 0, 'outstanding' => 0, 'invoices' => 0, 'mixed' => ['count' => 0, 'product_billed' => 0, 'invoice_total' => 0, 'paid' => 0],
            'other' => ['count' => 0, 'billed' => 0, 'paid' => 0, 'outstanding' => 0, 'list' => []]];
        foreach (self::invoiceQuery($client)->with('payments')->orderBy('issued_on')->orderBy('id')->get() as $i) {
            $l = (int) ($linked[$i->id] ?? 0);
            if ($l > 0 && $l >= (int) $i->total) {
                $out['billed'] += (int) $i->total;
                $out['paid'] += (int) $i->amount_paid;
                $out['outstanding'] += $i->balance();
                $out['invoices']++;
            } elseif ($l > 0) {
                $out['mixed']['count']++;
                $out['mixed']['product_billed'] += $l;
                $out['mixed']['invoice_total'] += (int) $i->total;
                $out['mixed']['paid'] += (int) $i->amount_paid;
            } else {
                $out['other']['count']++;
                $out['other']['billed'] += (int) $i->total;
                $out['other']['paid'] += (int) $i->amount_paid;
                $out['other']['outstanding'] += $i->balance();
                $out['other']['list'][] = ['number' => $i->number, 'total' => (int) $i->total, 'paid' => (int) $i->amount_paid];
            }
        }

        $billedLots = $allocations->pluck('sourcing_lot_id')->unique();
        $awaiting = [];
        foreach (self::clientLotQuery($client)->with('lines')->get() as $lot) {
            $reasons = [];
            if ($lot->ownership !== 'client') {
                $reasons[] = 'Ownership of this stock is still being confirmed';
            }
            if ($lot->lines->isEmpty() || $lot->lines->contains(fn ($l) => $l->approved_price === null)) {
                $reasons[] = 'Your price is not approved yet';
            }
            if (! $billedLots->contains($lot->id)) {
                $reasons[] = 'Not yet billed to you against this lot';
            }
            if ($reasons !== []) {
                $awaiting[] = ['number' => $lot->number, 'reasons' => $reasons, 'units' => (string) $lot->lines->reduce(fn ($c, $l) => bcadd($c, (string) ($l->received_quantity ?? '0'), 2), '0'),
                    'approved_value' => (int) $lot->lines->sum(fn ($l) => $l->approved_price === null ? 0 : Money::multiply((string) $l->quantity, (int) $l->approved_price))];
            }
        }

        return $out + ['awaiting' => $awaiting, 'awaiting_count' => count($awaiting), 'awaiting_value' => (int) collect($awaiting)->sum('approved_value')];
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
        $lotIds = self::clientLotQuery($client)->pluck('id');
        $split = self::splitByOwnership(self::positions($client));
        $positions = $split['confirmed'];
        $sales = SalesAnalytics::figures(self::allOrders($client));
        $e = self::economics($client);

        return [
            'client' => self::profile($client), 'active_projects' => $projects->filter(fn ($p) => $p->status->value === 'active' && ! $p->isArchived())->count(), 'projects' => $projects->count(),
            'sourced_products' => SourcingLotLine::whereIn('sourcing_lot_id', $lotIds)->count(),
            'available_stock' => $positions->reduce(fn ($c, $r) => bcadd($c, $r['b']['available'], 2), '0'),
            'orders' => $sales['counts']['total'], 'successful_orders' => $sales['successful_orders'], 'successful_sales' => $sales['totals']['sales'],
            'economics' => $e['total'], 'client_wide' => $e['client_wide'],
            'counts' => $sales['counts'], 'pending_deliveries' => $sales['counts']['confirmed'] + $sales['counts']['packed'] + $sales['counts']['dispatched'],
            'received_units' => (string) SourcingLotLine::whereIn('sourcing_lot_id', $lotIds)->sum('received_quantity'), 'stock' => self::stockBuckets($positions), 'stock_awaiting' => self::stockBuckets($split['awaiting']), 'billing' => self::productBilling($client), 'financials' => ClientFinancials::for($client), 'project_cards' => self::projectCards($client, $e), 'recent_orders' => self::recentOrders($client), 'invoices' => self::invoiceSummary($client),
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
        $billed = SourcingAllocation::whereIn('project_id', $projects)->where('kind', 'sale')->whereNotNull('billed_invoice_id')->pluck('sourcing_lot_id')->unique();
        foreach (self::clientLotQuery($client, $projectId)->with(['project', 'lines.product'])->orderByDesc('id')->get() as $lot) {
            $lines = $lot->lines->map(function (SourcingLotLine $l) use ($positions, $lot) {
                $b = array_fill_keys(['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'], '0');
                foreach ($positions->get($l->id, collect()) as $r) {
                    foreach ($b as $k => $v) {
                        $b[$k] = bcadd($v, $r['b'][$k], 2);
                    }
                }

                return ['stage' => self::lineStage($l->received_quantity === null ? null : (string) $l->received_quantity, $b, $lot->status), 'product' => $l->description, 'sku' => $l->product?->sku, 'unit' => $l->unit ?? 'pcs', 'client_price' => $l->approved_price === null ? null : (int) $l->approved_price, 'ordered' => (string) $l->quantity,
                    'received' => $l->received_quantity === null ? null : (string) $l->received_quantity, 'accepted' => $l->received_quantity === null ? null : (string) $l->usable_quantity, 'buckets' => $b];
            })->all();
            $out[] = ['lot' => $lot, 'number' => $lot->number, 'status' => $lot->status, 'status_label' => self::lotStatusLabel($lot->status), 'project' => $lot->project, 'lines' => $lines,
                'ownership_confirmed' => $lot->ownership === 'client', 'billed' => $billed->contains($lot->id)];
        }

        return $out;
    }

    /** The lot's own workflow state in plain words (only states that exist on the lot record). */
    public static function lotStatusLabel(string $status): string
    {
        return match ($status) {
            'draft', 'costing', 'priced' => 'Being prepared',
            'approved' => 'Approved',
            'in_progress' => 'Sourcing in progress',
            'completed', 'settled' => 'Completed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * Where a product is, read only from the lot's state, whether it has been received and its stock buckets. Never an invented stage.
     *
     * @param  array<string, string>  $b
     */
    public static function lineStage(?string $received, array $b, string $lotStatus): string
    {
        if ($received === null) {
            return in_array($lotStatus, ['draft', 'costing', 'priced', 'approved'], true) ? 'Awaiting sourcing' : ($lotStatus === 'in_progress' ? 'Sourcing in progress' : 'Awaiting arrival');
        }
        foreach (['available' => 'Available', 'reserved' => 'Reserved', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'returned' => 'Returned', 'damaged' => 'Damaged'] as $k => $label) {
            if (bccomp($b[$k] ?? '0', '0', 2) > 0) {
                return $label;
            }
        }

        return 'Received';
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

        return $page->through(fn (ClientOrder $o) => self::orderRow($o));
    }

    /**
     * One order as the portal shows it. The figures come from ProjectEconomics::orderFigures, the one place an order's sales, delivery charge, commission and entitlement are worked out.
     *
     * @return array<string, mixed>
     */
    private static function orderRow(ClientOrder $o): array
    {
        $f = ProjectEconomics::orderFigures($o);
        $delivered = $o->items->reduce(fn ($c, $i) => bcadd($c, bcsub((string) $i->delivered_quantity, (string) $i->returned_quantity, 2), 2), '0');
        $returned = $o->items->reduce(fn ($c, $i) => bcadd($c, (string) $i->returned_quantity, 2), '0');
        $collected = (int) $o->collections->sum('amount');
        $collectible = $o->collectibleValue();

        return ['order' => $o, 'products' => $o->items->map(fn ($i) => $i->description.' × '.rtrim(rtrim((string) $i->quantity, '0'), '.'))->implode(', '), 'delivered_units' => $delivered, 'returned_units' => $returned,
            'delivery_charge' => $f['eligible'], 'commission' => $f['commission'], 'entitlement' => $f['entitlement'], 'refunds' => $f['refunds'], 'sales' => $f['sales'],
            'collection' => $o->delivery_status !== 'delivered' ? 'Not due yet' : ($collectible <= 0 ? 'Nothing to collect' : ($collected >= $collectible ? 'Collected' : ($collected > 0 ? 'Partly collected' : 'Awaiting collection'))),
            'return_status' => bccomp($returned, '0', 2) > 0 ? ($f['refunds'] > 0 ? 'Returned, refund recorded' : 'Returned') : '—'];
    }

    /** @return Collection<int, array<string, mixed>> */
    public static function recentOrders(Client $client, ?int $projectId = null, int $limit = 5): Collection
    {
        return ClientOrder::where('fulfilment', 'stock')->whereIn('project_id', $client->projects()->select('id'))->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->with(['items', 'project', 'collections'])->orderByDesc('ordered_on')->orderByDesc('id')->limit($limit)->get()->map(fn (ClientOrder $o) => self::orderRow($o));
    }

    /**
     * Order counts by stage for the whole client or one project: the same counting SalesAnalytics uses.
     *
     * @return array<string, int>
     */
    public static function orderCounts(Client $client, ?int $projectId = null): array
    {
        $orders = self::allOrders($client)->when($projectId, fn ($c) => $c->where('project_id', $projectId));
        $c = SalesAnalytics::figures($orders)['counts'];

        return $c + ['pending_deliveries' => $c['confirmed'] + $c['packed'] + $c['dispatched']];
    }

    /**
     * Invoice totals and the open ones, read from the invoices themselves (their stored totals and the verified payments).
     *
     * @return array{total: int, paid: int, outstanding: int, open: int, open_list: Collection<int, Invoice>}
     */
    public static function invoiceSummary(Client $client): array
    {
        $all = self::invoiceQuery($client)->with(['project', 'payments'])->get();
        $open = $all->filter(fn (Invoice $i) => $i->balance() > 0)->sortBy('due_on')->values();

        return ['total' => (int) $all->sum('total'), 'paid' => (int) $all->sum('amount_paid'), 'outstanding' => (int) $open->sum(fn (Invoice $i) => $i->balance()), 'open' => $open->count(), 'open_list' => $open->take(5)];
    }

    /**
     * Sum of the stock buckets of a set of positions.
     *
     * @param  Collection<int, array<string, mixed>>  $positions
     * @return array<string, string>
     */
    public static function stockBuckets(Collection $positions): array
    {
        $b = array_fill_keys(['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'], '0');
        foreach ($positions as $r) {
            foreach ($b as $k => $v) {
                $b[$k] = bcadd($v, $r['b'][$k], 2);
            }
        }

        return $b;
    }

    /**
     * A card per project: its economics row (client price and sales figures only) and its stock.
     *
     * @return list<array<string, mixed>>
     */
    public static function projectCards(Client $client, ?array $economics = null): array
    {
        $positions = self::positions($client)->groupBy(fn ($r) => $r['project']->id);
        $counts = self::allOrders($client)->groupBy('project_id');

        $billing = self::productBilling($client);
        $awaitingLots = collect($billing['awaiting']);

        return collect(($economics ?? self::economics($client))['projects'])->map(function ($row) use ($positions, $counts, $client, $awaitingLots) {
            $split = self::splitByOwnership($positions->get($row['project']->id, collect()));
            $projectLots = self::clientLotQuery($client, $row['project']->id)->pluck('number');

            return $row + ['stock' => self::stockBuckets($split['confirmed']), 'stock_awaiting' => self::stockBuckets($split['awaiting']), 'orders' => $counts->get($row['project']->id, collect())->count(),
                'awaiting_lots' => $awaitingLots->whereIn('number', $projectLots->all())->count()];
        })->all();
    }

    /**
     * One of the client's projects in detail. Null when the project is not the client's.
     *
     * @return array<string, mixed>|null
     */
    public static function projectDetail(Client $client, ?int $projectId): ?array
    {
        $card = collect(self::projectCards($client))->first(fn ($c) => $c['project']->id === $projectId);
        if ($card === null) {
            return null;
        }

        return $card + ['lots' => self::lots($client, $projectId), 'recent_orders' => self::recentOrders($client, $projectId), 'counts' => self::orderCounts($client, $projectId),
            'positions' => self::positions($client, $projectId)];
    }

    /**
     * Successful sales by month (delivered orders, by the day they were delivered), newest first.
     *
     * @return array<string, array{orders: int, sales: int}>
     */
    public static function monthlySales(Client $client): array
    {
        return SalesAnalytics::figures(self::allOrders($client))['monthly'];
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
