<?php

namespace App\Services;

use App\Models\ClientOrder;
use App\Models\SourcingArrivalEvent;
use App\Models\SourcingLotLine;
use App\Models\StockLocation;
use App\Models\StockThreshold;
use Illuminate\Support\Collection;

/**
 * What needs a person's attention in stock. Read-only: it never orders from a supplier and never posts anything, it only points at the record.
 */
class InventoryExceptions
{
    public const STALE_RESERVATION_DAYS = 7;

    /** @return Collection<int, array{type: string, message: string, route: string, param: int|string|null}> */
    public static function all(): Collection
    {
        $positions = ProjectInventory::positions();
        $found = collect();
        $add = function (string $type, string $message, string $route, $param = null) use ($found) {
            $found->push(['type' => $type, 'message' => $message, 'route' => $route, 'param' => $param]);
        };

        foreach ($positions as $r) {
            $line = $r['line'];
            $name = $line->description.' ('.$line->lot->number.')';
            if ($r['own_slice'] && $r['attribution'] === 'none' && bccomp((string) $r['accepted'], '0', 2) > 0) {
                $add('Accepted stock without project attribution', $name.': '.$r['accepted'].' accepted but the lot has no project and no allocation.', 'product.inventory.show', $line->id);
            }
            if (in_array($r['valuation'], ['pending', 'partial'], true)) {
                $add('Accepted stock pending valuation', $name.': accepted goods are not fully valued yet ('.$r['valuation'].').', 'product.inventory.show', $line->id);
            }
            if ($r['ownership'] === 'unstated') {
                $add('Ownership mismatch', $name.': received stock with no owner stated.', 'product.inventory.show', $line->id);
            }
            if ($r['ownership'] === 'client' && ! $line->lot->project_id) {
                $add('Ownership mismatch', $name.': stated client-owned but the lot has no client project.', 'product.inventory.show', $line->id);
            }
            if ($r['own_slice'] && bccomp($r['b']['returned'], '0', 2) > 0) {
                $add('Unreconciled return', $name.': '.$r['b']['returned'].' returned units are waiting for inspection.', 'product.inventory.show', $line->id);
            }
            foreach (Inventory::BUCKETS as $bucket) {
                if (bccomp($r['b'][$bucket], '0', 2) < 0) {
                    $add('Negative or contradictory balance', $name.': '.$bucket.' is '.$r['b'][$bucket].'.', 'product.inventory.show', $line->id);
                }
            }
            foreach ($r['locations'] as $place => $qty) {
                if (bccomp($qty, '0', 2) < 0) {
                    $add('Stock location to verify', $name.': '.$place.' shows '.$qty.' (units left from a place they were not recorded in).', 'product.inventory.show', $line->id);
                } elseif ($place === 'Not placed' && bccomp($qty, '0', 2) > 0) {
                    $add('Stock location to verify', $name.': '.$qty.' units are not placed in any location.', 'product.inventory.show', $line->id);
                }
            }
        }
        foreach (SourcingLotLine::whereNotNull('received_quantity')->get() as $line) {
            if (! Inventory::reconciles($line)) {
                $add('Negative or contradictory balance', $line->description.': the stock buckets do not add up to what was received and adjusted.', 'product.inventory.show', $line->id);
            }
        }
        SourcingArrivalEvent::query()->whereNotNull('reference')->selectRaw('reference, COUNT(DISTINCT sourcing_lot_line_id) n, MIN(sourcing_lot_line_id) first_line')->groupBy('reference')->get()
            ->filter(fn ($e) => $e->n > 1 && SourcingLotLine::whereIn('id', SourcingArrivalEvent::where('reference', $e->reference)->pluck('sourcing_lot_line_id'))->distinct()->count('sourcing_lot_id') > 1)
            ->each(fn ($e) => $add('Duplicate receiving reference', 'Reference '.$e->reference.' is used for arrivals on different lots.', 'product.inventory.show', $e->first_line));
        ClientOrder::where('fulfilment', 'stock')->where('delivery_status', 'confirmed')->where('updated_at', '<', now()->subDays(self::STALE_RESERVATION_DAYS))->get()
            ->each(fn ($o) => $add('Stale reservation', 'Order '.$o->reference.' has held stock for more than '.self::STALE_RESERVATION_DAYS.' days without being dispatched.', 'product.orders'));

        return $found->values();
    }

    /**
     * Low and out-of-stock rows for the configured thresholds. A threshold with a project counts only that project's stock; with a location only the
     * units standing there (on-hand, since a location holds physical units, not reservations).
     *
     * @return Collection<int, array{threshold: StockThreshold, quantity: string, state: string}>
     */
    public static function lowStock(): Collection
    {
        $positions = ProjectInventory::positions();

        return StockThreshold::with(['product', 'project', 'location'])->get()->map(function (StockThreshold $t) use ($positions) {
            $rows = $positions->filter(fn ($r) => $r['line']->supplier_product_id === $t->supplier_product_id && (! $t->project_id || $r['project']?->id === $t->project_id));
            $qty = $rows->reduce(function ($c, $r) use ($t) {
                $q = $t->stock_location_id ? (StockLocations::slice($r['line'], $r['handover']?->id)[(string) $t->stock_location_id] ?? '0') : $r['b']['available'];

                return bcadd($c, $q, 2);
            }, '0');

            return ['threshold' => $t, 'quantity' => $qty, 'state' => bccomp($qty, '0', 2) <= 0 ? 'out' : (bccomp($qty, (string) $t->minimum_quantity, 2) < 0 ? 'low' : 'ok')];
        });
    }

    public static function locationList(): Collection
    {
        return StockLocation::where('is_active', true)->orderBy('name')->get();
    }
}
