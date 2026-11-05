<?php

namespace App\Services;

use App\Models\ClientOrderItem;
use App\Models\InventoryHandover;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock, from ONE source: the stock_movements table. Every balance is a sum of movements, nothing is kept as a counter, and a movement
 * only moves quantity between buckets, so the buckets always add up to what was received and accepted (plus authorised adjustments).
 * Ordered quantity is never stock: the first movement of a line is the receipt written when its arrival is recorded.
 *
 *   receipt → available → reserved (order confirmed) → dispatched → delivered
 *   reserved/dispatched can go back to available (cancelled / failed); delivered can come back as "returned" and is inspected into
 *   available (usable) or damaged. Nothing here edits or deletes history; corrections are new movements with a reason.
 */
class Inventory
{
    public const BUCKETS = ['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'];

    /** @return array{received: string, available: string, reserved: string, dispatched: string, delivered: string, returned: string, damaged: string, adjusted: string} */
    public static function balances(SourcingLotLine $line, ?int $handoverId = null, bool $slice = false): array
    {
        $row = StockMovement::where('sourcing_lot_line_id', $line->id)
            ->when($slice, fn ($q) => $handoverId === null ? $q->whereNull('inventory_handover_id') : $q->where('inventory_handover_id', $handoverId))
            ->selectRaw(
                'COALESCE(SUM(d_available),0) a, COALESCE(SUM(d_reserved),0) r, COALESCE(SUM(d_dispatched),0) p, COALESCE(SUM(d_delivered),0) d, COALESCE(SUM(d_returned),0) t, COALESCE(SUM(d_damaged),0) x,'
                ."COALESCE(SUM(CASE WHEN type = 'receipt' THEN d_available ELSE 0 END),0) rc, COALESCE(SUM(CASE WHEN type = 'adjust' THEN d_available + d_damaged ELSE 0 END),0) adj"
            )->first();

        return [
            'received' => bcadd((string) $row->rc, '0', 2), 'available' => bcadd((string) $row->a, '0', 2), 'reserved' => bcadd((string) $row->r, '0', 2),
            'dispatched' => bcadd((string) $row->p, '0', 2), 'delivered' => bcadd((string) $row->d, '0', 2), 'returned' => bcadd((string) $row->t, '0', 2),
            'damaged' => bcadd((string) $row->x, '0', 2), 'adjusted' => bcadd((string) $row->adj, '0', 2),
        ];
    }

    /**
     * The balances of one slice of a line: a handover's custody stock, or (null) the line's own stock, which on a company lot is what Sellbees still owns.
     * The slices of a line always add up to balances($line); a movement belongs to exactly one.
     *
     * @return array{received: string, available: string, reserved: string, dispatched: string, delivered: string, returned: string, damaged: string, adjusted: string}
     */
    public static function sliceBalances(SourcingLotLine $line, ?int $handoverId): array
    {
        return self::balances($line, $handoverId, true);
    }

    /** Units physically in the warehouse: not yet dispatched or delivered (available + reserved + awaiting inspection). */
    public static function onHand(SourcingLotLine $line, ?string $upTo = null): string
    {
        $q = StockMovement::where('sourcing_lot_line_id', $line->id)->when($upTo, fn ($q) => $q->whereDate('occurred_on', '<=', $upTo));

        return bcadd((string) $q->selectRaw('COALESCE(SUM(d_available + d_reserved + d_returned),0) s')->value('s'), '0', 2);
    }

    /** True when the buckets add up to what was received and adjusted (the self-check shown on the detail page and used in tests). */
    public static function reconciles(SourcingLotLine $line): bool
    {
        $b = self::balances($line);
        $sum = '0';
        foreach (['available', 'reserved', 'dispatched', 'delivered', 'returned', 'damaged'] as $k) {
            $sum = bcadd($sum, $b[$k], 2);
        }

        return bccomp($sum, bcadd($b['received'], $b['adjusted'], 2), 2) === 0 && bccomp($b['available'], '0', 2) >= 0;
    }

    /**
     * @param  array<string, string>  $deltas  bucket => signed quantity
     */
    public static function move(SourcingLotLine $line, string $type, array $deltas, ?string $key = null, ?ClientOrderItem $item = null, ?User $user = null, ?string $reason = null, ?int $handoverId = null, ?int $locationId = null, ?int $arrivalEventId = null): ?StockMovement
    {
        $handoverId ??= $item?->inventory_handover_id; // an order line moves the units of the custody slice it was reserved from

        return DB::transaction(function () use ($line, $type, $deltas, $key, $item, $user, $reason, $handoverId, $locationId, $arrivalEventId) {
            SourcingLot::whereKey($line->sourcing_lot_id)->lockForUpdate()->firstOrFail(); // serialises everything that touches this lot's stock
            if ($key && StockMovement::where('move_key', $key)->exists()) {
                return null;
            }
            $now = self::sliceBalances($line, $handoverId); // a slice can never go negative, whatever the other slices hold
            foreach ($deltas as $bucket => $delta) {
                if (bccomp(bcadd($now[$bucket], $delta, 2), '0', 2) < 0) {
                    throw new RuntimeException('Not enough stock: '.$line->description.' has '.$now[$bucket].' '.$bucket.' but '.ltrim(bcmul($delta, '-1', 2), '-').' would be taken.');
                }
            }
            // An on-hand change is placed where the units are; 0 states "not placed" explicitly (a transfer out of unplaced stock).
            $locationId = $locationId === 0 ? null : ($locationId ?? StockLocations::locationFor($line, $handoverId, $deltas));
            try {
                return StockMovement::create(['sourcing_lot_line_id' => $line->id, 'client_order_item_id' => $item?->id, 'inventory_handover_id' => $handoverId, 'type' => $type, 'occurred_on' => BusinessDate::today(),
                    'reason' => $reason, 'move_key' => $key, 'created_by' => $user?->id, 'stock_location_id' => $locationId, 'arrival_event_id' => $arrivalEventId]
                    + collect($deltas)->mapWithKeys(fn ($v, $k) => ['d_'.$k => $v])->all());
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        });
    }

    /** Make the line's receipt movements equal its accepted quantity once an arrival is on record (re-recording an arrival posts only the difference). */
    public static function syncReceipt(SourcingLotLine $line, ?User $user = null, ?int $arrivalEventId = null, ?int $locationId = null): void
    {
        $line->refresh();
        if ($line->received_quantity === null) {
            return;
        }
        $have = (string) StockMovement::where('sourcing_lot_line_id', $line->id)->where('type', 'receipt')->sum('d_available');
        $delta = bcsub((string) $line->usable_quantity, bcadd($have, '0', 2), 2);
        if (bccomp($delta, '0', 2) !== 0) {
            self::move($line, 'receipt', ['available' => $delta], null, null, $user, 'Arrival recorded: accepted '.$line->usable_quantity.' of '.$line->quantity.' ordered', null, $locationId, $arrivalEventId);
        }
    }

    /** Authorised correction of the physical count (a stock-take difference); always with a reason and the person's name. */
    public static function adjust(SourcingLotLine $line, string $delta, string $reason, User $user, bool $asDamage = false): void
    {
        if (trim($reason) === '' || bccomp($delta, '0', 2) === 0) {
            throw new RuntimeException('Enter a non-zero quantity and the reason for the adjustment.');
        }
        if ($asDamage && bccomp($delta, '0', 2) > 0) {
            throw new RuntimeException('Damaged stock is written off with a negative quantity.');
        }
        self::move($line, 'adjust', ['available' => $delta] + ($asDamage ? ['damaged' => bcmul($delta, '-1', 2)] : []), null, null, $user, $reason);
        Audit::log('inventory.adjusted', 'line '.$line->id, ($asDamage ? 'written off ' : 'adjusted ').$delta.': '.$reason);
    }

    /**
     * Lines of a client's own stock (their lots, stated as client-owned, with an arrival on record) that can fill an order, oldest arrival first.
     *
     * With a project, the client's custody stock from approved handovers to THAT project is added after the client-owned lots (custody of another project or
     * client is never visible). Each row names its handover (null for a client-owned lot).
     *
     * @return Collection<int, array{line: SourcingLotLine, available: string, handover: ?InventoryHandover}>
     */
    public static function stockFor(int $clientId, ?int $productId = null, ?int $projectId = null): Collection
    {
        $owned = SourcingLotLine::query()
            ->whereNotNull('received_quantity')
            ->whereHas('lot', fn ($q) => $q->where('ownership', 'client')->where('status', '!=', 'cancelled')->whereHas('project', fn ($p) => $p->where('client_id', $clientId)))
            ->when($productId, fn ($q) => $q->where('supplier_product_id', $productId))
            ->orderBy('arrived_on')->orderBy('id')->get()
            ->map(fn ($l) => ['line' => $l, 'available' => self::sliceBalances($l, null)['available'], 'handover' => null]);
        $custody = $projectId === null ? collect() : InventoryHandover::query()->with('line')
            ->where('status', 'approved')->where('custody_mode', 'warehouse')->where('client_id', $clientId)->where('project_id', $projectId)
            ->when($productId, fn ($q) => $q->where('supplier_product_id', $productId))
            ->orderBy('transferred_on')->orderBy('id')->get()
            ->map(fn (InventoryHandover $h) => ['line' => $h->line, 'available' => self::sliceBalances($h->line, $h->id)['available'], 'handover' => $h]);

        return $owned->concat($custody)->filter(fn ($r) => bccomp($r['available'], '0', 2) > 0)->values();
    }

    /**
     * First-in-first-out allocation of a requested quantity over the client's stock lines (never another client's, never an unreceived or
     * damaged unit). Returns [line, quantity] pairs; throws when the stock is not there.
     *
     * @return list<array{0: SourcingLotLine, 1: string, 2: ?InventoryHandover}>
     */
    public static function allocate(int $clientId, int $productId, string $quantity, ?int $projectId = null): array
    {
        $left = $quantity;
        $plan = [];
        foreach (self::stockFor($clientId, $productId, $projectId) as $row) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $take = bccomp($row['available'], $left, 2) >= 0 ? $left : $row['available'];
            $plan[] = [$row['line'], $take, $row['handover']];
            $left = bcsub($left, $take, 2);
        }
        if (bccomp($left, '0', 2) > 0) {
            throw new RuntimeException('Only '.bcsub($quantity, $left, 2).' of that product is available in this client\'s stock; '.$quantity.' were requested.');
        }

        return $plan;
    }
}
