<?php

namespace App\Services;

use App\Models\SourcingLotLine;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Where units physically stand. The location is only a label on the movements of the ONE stock ledger: a transfer is a balanced pair of
 * movements (out of one place, into another), so it can never change what a line holds, who owns it, or what it is worth.
 *
 * On-hand at a location = available + reserved + awaiting inspection of the movements written there. A movement that changes on-hand without
 * naming a location is placed where the units are (the location holding most of the slice); a location whose figure turns out negative is
 * reported as needing verification rather than hidden.
 */
class StockLocations
{
    public static function default(): StockLocation
    {
        return StockLocation::firstOrCreate(['code' => 'MAIN'], ['name' => 'Main warehouse']);
    }

    /** @param array<string, string> $deltas */
    public static function locationFor(SourcingLotLine $line, ?int $handoverId, array $deltas): ?int
    {
        $onHand = '0';
        foreach (['available', 'reserved', 'returned'] as $bucket) {
            $onHand = bcadd($onHand, $deltas[$bucket] ?? '0', 2);
        }
        if (bccomp($onHand, '0', 2) === 0) {
            return null;
        }

        return collect(self::slice($line, $handoverId))->filter(fn ($q, $id) => $id !== '')->sortDesc()->keys()->first() ?: null;
    }

    /**
     * On-hand per location id ('' = not placed) for one slice of a line.
     *
     * @return array<string, string>
     */
    public static function slice(SourcingLotLine $line, ?int $handoverId): array
    {
        return StockMovement::where('sourcing_lot_line_id', $line->id)
            ->when($handoverId === null, fn ($q) => $q->whereNull('inventory_handover_id'), fn ($q) => $q->where('inventory_handover_id', $handoverId))
            ->selectRaw('COALESCE(stock_location_id, 0) loc, COALESCE(SUM(d_available + d_reserved + d_returned),0) q')->groupBy('loc')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->loc === 0 ? '' : (string) (int) $r->loc => bcadd((string) $r->q, '0', 2)])->all();
    }

    public static function onHandAt(SourcingLotLine $line, ?int $handoverId, ?int $locationId): string
    {
        return self::slice($line, $handoverId)[$locationId === null ? '' : (string) $locationId] ?? '0.00';
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
        $line->loadMissing('lot');
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
            if ($t->status !== 'requested') {
                throw new RuntimeException('This transfer is already '.$t->status.'.');
            }
            $line = SourcingLotLine::findOrFail($t->sourcing_lot_line_id);
            $q = (string) $t->quantity;
            $handoverId = $t->inventory_handover_id;
            if (bccomp(self::onHandAt($line, $handoverId, $t->from_location_id), $q, 2) < 0) {
                throw new RuntimeException('Only '.self::onHandAt($line, $handoverId, $t->from_location_id).' stand at the origin; '.$q.' were to be moved.');
            }
            // Out and in are one balanced pair, so the available bucket (and the line's physical total) is unchanged; the slice-available check inside
            // move() still stops units that are reserved or dispatched from being moved.
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
