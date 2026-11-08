<?php

namespace App\Services;

use App\Models\ClientOrder;
use App\Models\ClientOrderItem;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\StockAllocation;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Which project may sell how many units of a client-owned lot line. The lot's own project (its HOME project) is entitled to everything that was accepted
 * (plus authorised count corrections) less what it handed to other projects; another project is entitled only to the quantity explicitly handed to it.
 *
 *   entitlement(line, P) = home: accepted - active allocations;   other: its active allocations;   anyone else: 0
 *   committed(line, P)   = the units P's orders took out of available and have not put back (reserved, dispatched, delivered, damaged), read from the ledger
 *   remaining(line, P)   = entitlement - committed
 *
 * The entitlements of all projects therefore add up to the accepted quantity, so no unit can be sold by two projects. Everything runs inside the lot lock that
 * Inventory::move uses, so concurrent allocations and reservations are serialised. Billing allocations of a lot (SourcingAllocation) play no part here.
 */
class ProjectStockAllocation
{
    /** Accepted units of the line's own slice (what the home project started with, including authorised count corrections). */
    private static function base(SourcingLotLine $line): string
    {
        $b = Inventory::sliceBalances($line, null);

        return bcadd($b['received'], $b['adjusted'], 2);
    }

    private static function allocated(SourcingLotLine $line, ?int $toProjectId = null): string
    {
        return bcadd((string) StockAllocation::where('sourcing_lot_line_id', $line->id)->whereNull('cancelled_at')->when($toProjectId, fn ($q, $id) => $q->where('project_id', $id))->sum('quantity'), '0', 2);
    }

    public static function entitlement(SourcingLotLine $line, int $projectId): string
    {
        $home = $line->lot->project_id;
        if ($home === $projectId) {
            return bcsub(self::base($line), self::allocated($line), 2);
        }

        return self::allocated($line, $projectId);
    }

    public static function committed(SourcingLotLine $line, int $projectId): string
    {
        $items = ClientOrderItem::whereIn('client_order_id', ClientOrder::where('project_id', $projectId)->select('id'))->select('id');
        $sum = StockMovement::where('sourcing_lot_line_id', $line->id)->whereNull('inventory_handover_id')->whereIn('client_order_item_id', $items)->sum('d_available');

        return bcmul(bcadd((string) $sum, '0', 2), '-1', 2);
    }

    public static function remaining(SourcingLotLine $line, int $projectId): string
    {
        $left = bcsub(self::entitlement($line, $projectId), self::committed($line, $projectId), 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    /** Hand part of a client-owned lot line from its home project to another project of the same client. */
    public static function allocate(SourcingLotLine $line, Project $to, string $quantity, string $reason, User $user): StockAllocation
    {
        return DB::transaction(function () use ($line, $to, $quantity, $reason, $user) {
            SourcingLot::whereKey($line->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $line = SourcingLotLine::with('lot.project')->findOrFail($line->id);
            $home = $line->lot->project;
            if ($line->received_quantity === null || $line->lot->ownership !== 'client' || $home === null) {
                throw new RuntimeException('Only received stock of a client-owned lot that belongs to a project can be allocated to another project.');
            }
            if ($to->id === $home->id || $to->client_id !== $home->client_id) {
                throw new RuntimeException('Stock can only be allocated to a different project of the same client.');
            }
            if (bccomp($quantity, '0', 2) <= 0 || trim($reason) === '') {
                throw new RuntimeException('Enter a quantity above zero and the reason for the allocation.');
            }
            $free = self::remaining($line, $home->id);
            $physical = Inventory::sliceBalances($line, null)['available'];
            if (bccomp($quantity, $free, 2) > 0 || bccomp($quantity, $physical, 2) > 0) {
                throw new RuntimeException('Only '.(bccomp($free, $physical, 2) < 0 ? $free : $physical).' unit(s) of '.$line->description.' are free to allocate (the rest are reserved, used or already allocated).');
            }
            $allocation = StockAllocation::create(['sourcing_lot_line_id' => $line->id, 'project_id' => $to->id, 'quantity' => $quantity, 'reason' => trim($reason), 'created_by' => $user->id]);
            Audit::log('stock.allocated', 'line '.$line->id, $quantity.' to project '.$to->id.': '.trim($reason));

            return $allocation;
        });
    }

    /** Take an allocation back. Refused once the receiving project has used those units (the orders must be cancelled or returned first). */
    public static function cancel(StockAllocation $allocation, User $user): void
    {
        DB::transaction(function () use ($allocation, $user) {
            $line = SourcingLotLine::with('lot')->findOrFail($allocation->sourcing_lot_line_id);
            SourcingLot::whereKey($line->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $allocation = StockAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($allocation->cancelled_at !== null) {
                throw new RuntimeException('This allocation was already cancelled.');
            }
            if (bccomp(self::remaining($line, $allocation->project_id), (string) $allocation->quantity, 2) < 0) {
                throw new RuntimeException('Those units are already used by orders of the receiving project; cancel or return those orders first.');
            }
            $allocation->update(['cancelled_at' => now(), 'cancelled_by' => $user->id]);
            Audit::log('stock.allocation_cancelled', 'line '.$line->id, $allocation->quantity.' from project '.$allocation->project_id);
        });
    }
}
