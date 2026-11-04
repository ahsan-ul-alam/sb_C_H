<?php

namespace App\Services;

use App\Models\LandedCostReallocation;
use App\Models\SourcingLotCost;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Who benefits from a shared landed cost is a documented policy decision, not an accident of when goods were accepted. When a cost is capitalised it is
 * spread over the lines accepted at that moment; a line accepted later does NOT share it merely because it appeared. Moving a cost's value between lines
 * needs a request that names the beneficiary lines and the cost policy it relies on, approved by a different authorised user. The compensating movements are
 * appended, net to zero (the aggregate and the ledger entry that carries it do not change), and no line is left with a negative value. Once any stock of
 * the lot has been handed over, part of the cost has been consumed and the move is refused until an open-period adjustment is available.
 */
class LandedCostAllocation
{
    /**
     * @param  list<int>  $beneficiaryLineIds
     */
    public static function propose(SourcingLotCost $cost, array $beneficiaryLineIds, string $policyReference, string $basis, User $user): LandedCostReallocation
    {
        return DB::transaction(function () use ($cost, $beneficiaryLineIds, $policyReference, $basis, $user) {
            $lot = $cost->lot()->lockForUpdate()->firstOrFail();
            self::assertAllowed($cost, $lot, $beneficiaryLineIds, $policyReference, $basis);
            if (LandedCostReallocation::where('sourcing_lot_cost_id', $cost->id)->where('status', 'pending')->exists()) {
                throw new RuntimeException('A reallocation of this cost is already awaiting a decision.');
            }
            $reallocation = LandedCostReallocation::create(['sourcing_lot_cost_id' => $cost->id, 'policy_reference' => trim($policyReference), 'basis' => $basis,
                'beneficiary_line_ids' => array_values(array_unique(array_map('intval', $beneficiaryLineIds))), 'prepared_by' => $user->id]);
            Audit::log('inventory.landed_realloc_proposed', 'lot '.$lot->number, 'cost '.$cost->id.' under '.$reallocation->policy_reference);

            return $reallocation;
        });
    }

    public static function decide(LandedCostReallocation $reallocation, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($reallocation, $approve, $user, $note) {
            $reallocation = LandedCostReallocation::whereKey($reallocation->id)->lockForUpdate()->firstOrFail();
            $cost = SourcingLotCost::findOrFail($reallocation->sourcing_lot_cost_id);
            $lot = $cost->lot()->lockForUpdate()->firstOrFail();
            if ($reallocation->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($reallocation->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve a reallocation you proposed.');
            }
            if (! $user->can('sourcing.advance.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide a reallocation.');
            }
            if (! $approve && trim((string) $note) === '') {
                throw new RuntimeException('Give a reason for rejecting.');
            }
            if ($approve) {
                self::assertAllowed($cost, $lot, $reallocation->beneficiary_line_ids, $reallocation->policy_reference, $reallocation->basis);
                InventoryValuation::applyReallocation($reallocation, $user);
            }
            $reallocation->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null]);
            Audit::log('inventory.landed_realloc_'.($approve ? 'approved' : 'rejected'), 'lot '.$lot->number, 'cost '.$cost->id);
        });
    }

    /**
     * @param  list<int>  $beneficiaryLineIds
     */
    private static function assertAllowed(SourcingLotCost $cost, $lot, array $beneficiaryLineIds, string $policyReference, string $basis): void
    {
        if (! InventoryEngine::isValued($lot)) {
            throw new RuntimeException('Only a lot valued by the inventory engine has landed cost layers to reallocate.');
        }
        if (trim($policyReference) === '') {
            throw new RuntimeException('Name the documented cost policy (or Board resolution) that says which goods benefit from this cost.');
        }
        if (! in_array($basis, ['value', 'quantity'], true)) {
            throw new RuntimeException('Choose how the cost is shared: by value or by quantity.');
        }
        if (InventoryValuation::costValueHeld($cost) <= 0) {
            throw new RuntimeException('This cost holds no value on the lot\'s stock.');
        }
        if (InventoryValuation::hasIssued($lot)) {
            throw new RuntimeException('Stock of this lot has already been handed over, so part of the cost is consumed. Moving it needs an open-period cost adjustment, which is not available here.');
        }
        $ids = array_values(array_unique(array_map('intval', $beneficiaryLineIds)));
        if ($ids === []) {
            throw new RuntimeException('Name the lines that benefit from this cost.');
        }
        foreach ($ids as $id) {
            $line = $lot->lines()->find($id);
            if (! $line || $line->received_quantity === null || bccomp((string) $line->usable_quantity, '0', 2) <= 0) {
                throw new RuntimeException('Every beneficiary must be a line of this lot with accepted goods.');
            }
        }
    }
}
