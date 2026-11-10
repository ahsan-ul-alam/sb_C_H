<?php

namespace App\Services;

use App\Models\ClientOrder;
use App\Models\ClientOrderItem;
use App\Models\Project;
use App\Models\StockThreshold;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales figures read from the order records, the stock ledger and ProjectEconomics::orderFigures (the one place an order's sales, delivery charge, commission and client
 * entitlement are worked out). READ-ONLY: nothing here posts, updates or recognises anything, and no figure is estimated.
 *
 * Definitions (all integers in minor units, rates in basis points, half-up):
 *   successful order       delivery_status = delivered (a partly delivered order counts once; its value is only what was delivered and not returned)
 *   failed order           delivery_status = failed
 *   success rate           delivered / (delivered + failed): cancelled and still-open orders are not outcomes yet
 *   return rate            delivered orders with at least one returned unit / delivered orders (unit return rate: returned units / delivered units)
 *   average order value    successful sales / successful orders
 *   sale date              the day it was delivered (delivered_at); older orders without it use the order date
 */
class SalesAnalytics
{
    /** A dispatched order still undelivered after this many days is an exception to chase. */
    public const PENDING_DELIVERY_DAYS = 3;

    /**
     * @param  Collection<int, ClientOrder>  $orders  stock orders with their items (and project) loaded
     * @return array<string, mixed>
     */
    public static function figures(Collection $orders): array
    {
        $status = fn (string ...$s) => $orders->whereIn('delivery_status', $s)->count();
        $delivered = $orders->where('delivery_status', 'delivered');
        $failed = $status('failed');
        $zero = ['sales' => 0, 'delivery' => 0, 'net' => 0, 'commission' => 0, 'entitlement' => 0];
        $totals = $zero;
        $buckets = ['daily' => [], 'weekly' => [], 'monthly' => []];
        $products = [];
        $projects = [];
        $withReturns = 0;
        $unitsDelivered = '0';
        $unitsReturned = '0';

        foreach ($orders->groupBy('project_id') as $projectId => $group) {
            $projects[$projectId] = ['project' => $group->first()->project, 'orders' => $group->count(), 'delivered' => $group->where('delivery_status', 'delivered')->count(), 'failed' => $group->where('delivery_status', 'failed')->count(), 'cancelled' => $group->where('delivery_status', 'cancelled')->count()] + $zero;
        }
        foreach ($delivered as $o) {
            $f = ProjectEconomics::orderFigures($o);
            $row = ['sales' => $f['sales'], 'delivery' => $f['eligible'], 'net' => $f['net'], 'commission' => $f['commission'], 'entitlement' => $f['entitlement']];
            foreach ($row as $k => $v) {
                $totals[$k] += $v;
                $projects[$o->project_id][$k] += $v;
            }
            $date = ($o->delivered_at ?? $o->ordered_on)->toDateString();
            $at = Carbon::parse($date);
            foreach (['daily' => $date, 'weekly' => $at->format('o-\WW'), 'monthly' => $at->format('Y-m')] as $name => $key) {
                $buckets[$name][$key] ??= ['orders' => 0, 'sales' => 0];
                $buckets[$name][$key]['orders']++;
                $buckets[$name][$key]['sales'] += $f['sales'];
            }
            $returned = false;
            foreach ($o->items as $i) {
                $unitsDelivered = bcadd($unitsDelivered, (string) $i->delivered_quantity, 2);
                $unitsReturned = bcadd($unitsReturned, (string) $i->returned_quantity, 2);
                $returned = $returned || bccomp((string) $i->returned_quantity, '0', 2) > 0;
                $net = bcsub((string) $i->delivered_quantity, (string) $i->returned_quantity, 2);
                $key = $i->supplier_product_id ? 'p'.$i->supplier_product_id : 'd'.$i->description;
                $products[$key] ??= ['name' => $i->description, 'units' => '0', 'sales' => 0, 'orders' => []];
                $products[$key]['units'] = bcadd($products[$key]['units'], $net, 2);
                $products[$key]['sales'] += Money::multiply($net, $i->netUnitPrice());
                $products[$key]['orders'][$o->id] = true;
            }
            $withReturns += $returned ? 1 : 0;
        }
        foreach ($buckets as &$bucket) {
            krsort($bucket);
        }
        unset($bucket);
        $outcomes = $delivered->count() + $failed;
        $bps = fn (int $part, int $whole) => $whole > 0 ? (int) bcadd(bcdiv(bcmul((string) $part, '10000', 0), (string) $whole, 4), '0.5', 0) : null;
        $topProducts = collect($products)->map(fn ($p) => ['name' => $p['name'], 'units' => $p['units'], 'sales' => $p['sales'], 'orders' => count($p['orders'])])
            ->filter(fn ($p) => bccomp($p['units'], '0', 2) > 0)->sortByDesc(fn ($p) => (float) $p['units'])->take(10)->values();

        return [
            'counts' => ['total' => $orders->count(), 'draft' => $status('pending'), 'confirmed' => $status('confirmed'), 'packed' => $status('packed'), 'dispatched' => $status('dispatched'),
                'delivered' => $delivered->count(), 'failed' => $failed, 'cancelled' => $status('cancelled'), 'with_returns' => $withReturns],
            'totals' => $totals, 'successful_orders' => $delivered->count(),
            'success_rate_bps' => $bps($delivered->count(), $outcomes), 'failure_rate_bps' => $bps($failed, $outcomes), 'return_rate_bps' => $bps($withReturns, $delivered->count()),
            'unit_return_rate_bps' => bccomp($unitsDelivered, '0', 2) > 0 ? (int) bcadd(bcdiv(bcmul($unitsReturned, '10000', 4), $unitsDelivered, 4), '0.5', 0) : null,
            'average_order_value' => $delivered->count() > 0 ? (int) bcadd(bcdiv((string) $totals['sales'], (string) $delivered->count(), 4), '0.5', 0) : null,
            'daily' => array_slice($buckets['daily'], 0, 31, true), 'weekly' => array_slice($buckets['weekly'], 0, 26, true), 'monthly' => array_slice($buckets['monthly'], 0, 24, true),
            'top_products' => $topProducts, 'projects' => collect($projects)->map(fn ($p) => $p + ['success_rate_bps' => $bps($p['delivered'], $p['delivered'] + $p['failed'])])->sortByDesc('sales')->values(),
        ];
    }

    /**
     * What needs chasing: dispatched orders overdue for delivery, and units that came back (a failed delivery or a customer return) and still wait for inspection.
     *
     * @param  Collection<int, ClientOrder>  $orders  stock orders with items loaded
     * @return array{overdue: list<array{order: ClientOrder, days: int}>, awaiting_inspection: list<array{order: ClientOrder, item: ClientOrderItem, quantity: string}>}
     */
    public static function exceptions(Collection $orders): array
    {
        $today = BusinessDate::now()->startOfDay();
        $overdue = $orders->where('delivery_status', 'dispatched')->map(function (ClientOrder $o) use ($today) {
            $since = Carbon::parse(($o->dispatched_at ?? $o->ordered_on)->toDateString());

            return ['order' => $o, 'days' => (int) $since->diffInDays($today->toDateString(), false)];
        })->filter(fn ($r) => $r['days'] >= self::PENDING_DELIVERY_DAYS)->sortByDesc('days')->values()->all();

        $waiting = [];
        foreach ($orders->filter(fn ($o) => $o->delivery_status === 'failed' || ($o->delivery_status === 'delivered' && $o->items->contains(fn ($i) => bccomp((string) $i->returned_quantity, '0', 2) > 0))) as $o) {
            foreach ($o->items as $item) {
                $qty = OrderFulfilment::awaitingInspection($item);
                if (bccomp($qty, '0', 2) > 0) {
                    $waiting[] = ['order' => $o, 'item' => $item, 'quantity' => $qty];
                }
            }
        }

        return ['overdue' => $overdue, 'awaiting_inspection' => $waiting];
    }

    /**
     * Products of a project whose available stock is at or under the minimum Finance set (a threshold for this project, or one for every project).
     *
     * @param  Collection<int, array<string, mixed>>  $positions  ProjectInventory positions of the project
     * @return list<array{product: string, available: string, minimum: string, state: string}>
     */
    public static function lowStock(Project $project, Collection $positions): array
    {
        $out = [];
        foreach (StockThreshold::with('product')->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))->whereNull('stock_location_id')->get() as $t) {
            $mine = $positions->filter(fn ($r) => $r['line']->supplier_product_id === $t->supplier_product_id);
            if ($mine->isEmpty()) {
                continue;
            }
            $available = $mine->reduce(fn ($c, $r) => bcadd($c, $r['b']['available'], 2), '0');
            if (bccomp($available, (string) $t->minimum_quantity, 2) < 0) {
                $out[] = ['product' => $t->product?->name ?? 'Product', 'available' => $available, 'minimum' => (string) $t->minimum_quantity, 'state' => bccomp($available, '0', 2) <= 0 ? 'out' : 'low'];
            }
        }

        return $out;
    }
}
