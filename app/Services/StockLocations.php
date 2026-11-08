<?php

namespace App\Services;

use App\Models\ClientOrderItem;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Where the physical units of a lot line stand. The location is a label on the movements of the ONE stock ledger, so it can never disagree with it:
 *
 *   global on-hand of a line (available + reserved + awaiting inspection) = SUM of its located balances + its explicitly unplaced balance.
 *
 * Rules enforced here, inside the lot lock Inventory::move already holds:
 *  - a receipt names its location; older stock stays "unplaced" (never given an invented location) until a transfer places it.
 *  - a movement that takes units out of the warehouse (dispatch, write-off) is split over the locations that really hold them, largest first, and
 *    unplaced units last; no location can be driven below zero, and a request larger than what is located is refused.
 *  - a movement that returns units (failed delivery, customer return) puts them back where that same order line took them from.
 *  - reserving or releasing changes availability, not where units stand, so it is not placed.
 *  - ownership-only movements (custody) are location-neutral: the physical units do not move.
 * Reservations are per line, not per location: the pick location is chosen at dispatch.
 */
class StockLocations
{
    /** Movement types that change who holds the units, not where they stand. */
    private const OWNERSHIP_ONLY = ['custody_out', 'custody_in', 'custody_return'];

    public static function default(): StockLocation
    {
        return StockLocation::firstOrCreate(['code' => 'MAIN'], ['name' => 'Main warehouse']);
    }

    /** True once warehouse locations have been configured (receipts must then name one). */
    public static function configured(): bool
    {
        return StockLocation::query()->exists();
    }

    /**
     * On-hand units per location of a line; the key '' is the explicitly unplaced balance. Zero balances are left out.
     *
     * @return array<string, string>
     */
    public static function balances(SourcingLotLine $line): array
    {
        return StockMovement::where('sourcing_lot_line_id', $line->id)
            ->selectRaw('COALESCE(stock_location_id, 0) loc, COALESCE(SUM(d_available + d_reserved + d_returned),0) q')->groupBy('loc')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->loc === 0 ? '' : (string) (int) $r->loc => bcadd((string) $r->q, '0', 2)])
            ->filter(fn ($q) => bccomp($q, '0', 2) !== 0)->all();
    }

    public static function onHandAt(SourcingLotLine $line, ?int $locationId): string
    {
        return self::balances($line)[$locationId === null ? '' : (string) $locationId] ?? '0.00';
    }

    /**
     * Available units per location ('' = unplaced); only positive balances are returned.
     *
     * @return array<string, string>
     */
    public static function availableBalances(SourcingLotLine $line): array
    {
        return StockMovement::where('sourcing_lot_line_id', $line->id)->selectRaw('COALESCE(stock_location_id, 0) loc, COALESCE(SUM(d_available),0) q')->groupBy('loc')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->loc === 0 ? '' : (string) (int) $r->loc => bcadd((string) $r->q, '0', 2)])->filter(fn ($q) => bccomp($q, '0', 2) > 0)->all();
    }

    public static function availableAt(SourcingLotLine $line, ?int $locationId): string
    {
        return self::availableBalances($line)[$locationId === null ? '' : (string) $locationId] ?? '0.00';
    }

    /**
     * What one order line currently holds in a bucket ('d_reserved'), per location.
     *
     * @return array<string, string>
     */
    private static function heldByItem(ClientOrderItem $item, string $column): array
    {
        return StockMovement::where('client_order_item_id', $item->id)->selectRaw("COALESCE(stock_location_id, 0) loc, COALESCE(SUM($column),0) q")->groupBy('loc')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->loc === 0 ? '' : (string) (int) $r->loc => bcadd((string) $r->q, '0', 2)])->filter(fn ($q) => bccomp($q, '0', 2) > 0)->all();
    }

    /**
     * Take a quantity from per-location balances, placed locations (largest first) before the unplaced balance; refuses when they hold less.
     *
     * @param  array<string, string>  $balances
     * @return list<array{0: ?int, 1: string}>
     */
    private static function takeFrom(array $balances, string $size, SourcingLotLine $line, string $bucket): array
    {
        $order = collect($balances)->reject(fn ($q, $id) => $id === '')->sortDesc()->keys()->all();
        if (isset($balances[''])) {
            $order[] = '';
        }
        $chunks = [];
        $left = $size;
        foreach ($order as $id) {
            $take = bccomp($balances[$id], $left, 2) >= 0 ? $left : $balances[$id];
            $chunks[] = [$id === '' ? null : (int) $id, $take];
            $left = bcsub($left, $take, 2);
            if (bccomp($left, '0', 2) === 0) {
                return $chunks;
            }
        }

        throw new RuntimeException('Only '.bcsub($size, $left, 2).' '.$bucket.' of '.$line->description.' stand in recorded locations; '.$size.' were needed.');
    }

    /**
     * Split one movement into per-location movements. Returns [location id|null, deltas] parts that add up to the requested deltas.
     *
     * @param  array<string, string>  $deltas
     * @return list<array{0: ?int, 1: array<string, string>}>
     */
    public static function plan(SourcingLotLine $line, string $type, array $deltas, ?ClientOrderItem $item, ?int $explicit): array
    {
        $onHand = '0';
        foreach (['available', 'reserved', 'returned'] as $bucket) {
            $onHand = bcadd($onHand, $deltas[$bucket] ?? '0', 2);
        }
        if ($explicit !== null) { // 0 states "unplaced" explicitly
            $location = $explicit === 0 ? null : $explicit;
            if (bccomp($onHand, '0', 2) < 0 && bccomp(self::onHandAt($line, $location), bcmul($onHand, '-1', 2), 2) < 0) {
                throw new RuntimeException('Only '.self::onHandAt($line, $location).' stand at that location; '.bcmul($onHand, '-1', 2).' were to be taken.');
            }
            // Units that are reserved are not available to move or reserve again, so the location's AVAILABLE units must cover any take from "available".
            if (bccomp($deltas['available'] ?? '0', '0', 2) < 0 && bccomp(self::availableAt($line, $location), bcmul($deltas['available'], '-1', 2), 2) < 0) {
                throw new RuntimeException('Only '.self::availableAt($line, $location).' of '.$line->description.' are available at that location (the rest is reserved or elsewhere).');
            }

            return [[$location, $deltas]];
        }
        if (in_array($type, self::OWNERSHIP_ONLY, true)) {
            return [[null, $deltas]];
        }
        // A reservation is taken from the locations that hold available units; releasing it or dispatching it works from the locations it was reserved at.
        if ($type === 'reserve' && bccomp($deltas['reserved'] ?? '0', '0', 2) > 0) {
            return self::scale($deltas, self::takeFrom(self::availableBalances($line), ltrim($deltas['available'], '-'), $line, 'available'), ltrim($deltas['available'], '-'));
        }
        if ($item && in_array($type, ['release', 'dispatch'], true)) {
            $held = self::heldByItem($item, 'd_reserved');
            if (bccomp(array_reduce($held, fn ($c, $q) => bcadd($c, $q, 2), '0'), ltrim($deltas['reserved'], '-'), 2) >= 0) {
                return self::scale($deltas, self::takeFrom($held, ltrim($deltas['reserved'], '-'), $line, 'reserved'), ltrim($deltas['reserved'], '-'));
            }
        }
        // An inspection settles units where they came back to (a failed delivery or customer return is put back at the place it left), so a usable unit becomes
        // available in that same place and not in an invented "unplaced" balance.
        if ($item && $type === 'inspect' && bccomp($deltas['returned'] ?? '0', '0', 2) < 0) {
            $held = self::heldByItem($item, 'd_returned');
            $size = ltrim($deltas['returned'], '-');
            if (bccomp(array_reduce($held, fn ($c, $q) => bcadd($c, $q, 2), '0'), $size, 2) >= 0) {
                return self::scale($deltas, self::takeFrom($held, $size, $line, 'returned'), $size);
            }
        }
        if (bccomp($onHand, '0', 2) === 0) {
            return [[null, $deltas]];
        }
        $size = ltrim($onHand, '-');
        if (bccomp($onHand, '0', 2) < 0) {
            $held = collect(self::balances($line))->filter(fn ($q) => bccomp($q, '0', 2) > 0);
            $order = $held->reject(fn ($q, $id) => $id === '')->sortDesc()->keys()->all();
            if ($held->has('')) {
                $order[] = '';
            }
            $chunks = [];
            $left = $size;
            foreach ($order as $id) {
                $take = bccomp($held[$id], $left, 2) >= 0 ? $left : $held[$id];
                $chunks[] = [$id === '' ? null : (int) $id, $take];
                $left = bcsub($left, $take, 2);
                if (bccomp($left, '0', 2) === 0) {
                    break;
                }
            }
            if (bccomp($left, '0', 2) > 0) {
                throw new RuntimeException('Only '.bcsub($size, $left, 2).' of '.$line->description.' stand in recorded locations; '.$size.' were to be taken.');
            }
        } else {
            $chunks = self::returnChunks($line, $item, $size);
        }

        return self::scale($deltas, $chunks, $size);
    }

    /**
     * Where returning units go: back to the locations this order line took them from (what it still owes those places), the rest unplaced.
     *
     * @return list<array{0: ?int, 1: string}>
     */
    private static function returnChunks(SourcingLotLine $line, ?ClientOrderItem $item, string $size): array
    {
        $chunks = [];
        $left = $size;
        if ($item) {
            $owed = StockMovement::where('client_order_item_id', $item->id)->selectRaw('COALESCE(stock_location_id, 0) loc, COALESCE(SUM(d_available + d_reserved + d_returned),0) q')->groupBy('loc')->get();
            foreach ($owed as $row) {
                $room = ltrim(bcadd((string) $row->q, '0', 2), '-');
                if ((int) $row->loc === 0 || bccomp((string) $row->q, '0', 2) >= 0 || bccomp($left, '0', 2) === 0) {
                    continue;
                }
                $take = bccomp($room, $left, 2) >= 0 ? $left : $room;
                $chunks[] = [(int) $row->loc, $take];
                $left = bcsub($left, $take, 2);
            }
        }
        if (bccomp($left, '0', 2) > 0) {
            $placed = collect(self::balances($line))->filter(fn ($q, $id) => $id !== '' && bccomp($q, '0', 2) > 0);
            $chunks[] = [$item === null && $placed->count() === 1 ? (int) $placed->keys()->first() : null, $left];
        }

        return $chunks;
    }

    /**
     * @param  array<string, string>  $deltas
     * @param  list<array{0: ?int, 1: string}>  $chunks
     * @return list<array{0: ?int, 1: array<string, string>}>
     */
    private static function scale(array $deltas, array $chunks, string $size): array
    {
        $parts = [];
        $given = array_fill_keys(array_keys($deltas), '0');
        foreach ($chunks as $i => [$loc, $qty]) {
            $part = [];
            foreach ($deltas as $bucket => $delta) {
                $part[$bucket] = $i === array_key_last($chunks) ? bcsub($delta, $given[$bucket], 2) : bcdiv(bcmul($delta, $qty, 4), $size, 2);
                $given[$bucket] = bcadd($given[$bucket], $part[$bucket], 2);
            }
            $parts[] = [$loc, $part];
        }

        return $parts;
    }

    public static function create(string $code, string $name): StockLocation
    {
        $code = strtoupper(trim($code));
        if ($code === '' || trim($name) === '' || StockLocation::where('code', $code)->exists()) {
            throw new RuntimeException('Give the location a unique code and a name.');
        }

        return StockLocation::create(['code' => $code, 'name' => trim($name)]);
    }

    /** Ask to move units; nothing moves until it is completed. */
    public static function request(SourcingLotLine $line, ?int $handoverId, ?int $fromId, int $toId, string $quantity, string $reason, User $user): StockTransfer
    {
        if (trim($reason) === '' || bccomp($quantity, '0', 2) <= 0) {
            throw new RuntimeException('Enter a quantity above zero and the reason for the transfer.');
        }
        if ($fromId === $toId) {
            throw new RuntimeException('Choose two different locations.');
        }
        if ($line->received_quantity === null) {
            throw new RuntimeException('Only received stock can be moved.');
        }
        $transfer = StockTransfer::create(['sourcing_lot_line_id' => $line->id, 'inventory_handover_id' => $handoverId, 'from_location_id' => $fromId, 'to_location_id' => $toId,
            'quantity' => $quantity, 'reason' => trim($reason), 'requested_by' => $user->id]);
        Audit::log('inventory.transfer_requested', 'transfer '.$transfer->id, $quantity.' of line '.$line->id);

        return $transfer;
    }

    public static function complete(StockTransfer $transfer, User $user): void
    {
        DB::transaction(function () use ($transfer, $user) {
            $t = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            SourcingLot::whereKey(SourcingLotLine::whereKey($t->sourcing_lot_line_id)->value('sourcing_lot_id'))->lockForUpdate()->firstOrFail(); // the same lock every stock write takes
            if ($t->status !== 'requested') {
                throw new RuntimeException('This transfer is already '.$t->status.'.');
            }
            $line = SourcingLotLine::findOrFail($t->sourcing_lot_line_id);
            $q = (string) $t->quantity;
            $handoverId = $t->inventory_handover_id;
            // Out and in are one balanced pair: the line total never changes. Only available units can be moved (reserved or dispatched ones cannot), and the
            // origin must really hold them (checked again inside move()).
            Inventory::move($line, 'transfer', ['available' => bcmul($q, '-1', 2)], 'xfo:'.$t->id, null, $user, 'Transfer out: '.$t->reason, $handoverId, $t->from_location_id ?? 0);
            Inventory::move($line, 'transfer', ['available' => $q], 'xfi:'.$t->id, null, $user, 'Transfer in: '.$t->reason, $handoverId, $t->to_location_id);
            $t->update(['status' => 'completed', 'completed_by' => $user->id, 'completed_at' => now()]);
            Audit::log('inventory.transfer_completed', 'transfer '.$t->id, $q);
        });
    }

    public static function cancel(StockTransfer $transfer): void
    {
        if ($transfer->status !== 'requested') {
            throw new RuntimeException('Only a requested transfer can be cancelled.');
        }
        $transfer->update(['status' => 'cancelled']);
        Audit::log('inventory.transfer_cancelled', 'transfer '.$transfer->id);
    }
}
