<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\SourcingLot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Event D of the supplier-advance policy: release a lot's capitalised product cost into profit.
 *
 *      Dr expense (direct sourcing cost) / Cr assets.inventory          no cash moves
 *
 * WHEN is a Board decision (finance.sourcing_recognition_trigger) and is OFF until made: arrival is never a trigger and neither is
 * the client having paid. Under the one supported trigger (lot_completed) a lot's cost is released only when the lot is completed,
 * every supplier advance is settled or otherwise resolved, and every line has a recorded, documented arrival. A lot's cost can be
 * released once (a unique index, so simultaneous clicks cannot do it twice); the amount is exactly what the lot holds in inventory.
 */
class CostRecognitionService
{
    /**
     * Product acquisition cost held for a lot: verified advance settlements plus verified paid acquisition costs (transport, loading…),
     * less verified corrections of those, less what has been released. All integer paisa.
     */
    public static function held(SourcingLot $lot): int
    {
        $settled = (int) AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('status', 'verified')->sum('amount');
        $costExpenseIds = $lot->costs()->whereNotNull('expense_id')->pluck('expense_id');
        $capitalised = Expense::whereIn('id', $costExpenseIds)->where('classification', 'acquisition')->where('status', 'verified');
        $costs = (int) (clone $capitalised)->sum('amount');
        $corrections = (int) Expense::whereIn('reverses_id', (clone $capitalised)->pluck('id'))->where('status', 'verified')->sum('amount');

        return $settled + $costs - $corrections;
    }

    /** Capitalised cost of the lot not yet released into profit. */
    public static function unreleased(SourcingLot $lot): int
    {
        return self::held($lot) - (int) CostRecognition::where('sourcing_lot_id', $lot->id)->where('status', 'verified')->sum('amount');
    }

    public static function releasedBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) CostRecognition::where('status', 'verified')->whereDate('recognised_on', '>=', $from)->whereDate('recognised_on', '<=', $to)->sum('amount');
    }

    /** @return list<string> reasons the lot's cost cannot be released yet (empty = it can) */
    public static function obstacles(SourcingLot $lot): array
    {
        $obstacles = [];
        if (SourcingBasis::recognitionTrigger() === null) {
            $obstacles[] = 'Cost recognition is switched off: the Board has not approved when product cost reaches profit (FINANCE_SOURCING_RECOGNITION).';

            return $obstacles;
        }
        if (! in_array($lot->status, ['completed', 'settled'], true)) {
            $obstacles[] = 'The lot is not completed.';
        }
        if ($lot->lines()->whereNull('received_quantity')->exists()) {
            $obstacles[] = 'Not every line has a recorded arrival.';
        }
        if ($lot->lines()->whereNotNull('received_quantity')->whereRaw("COALESCE(acceptance_reference, '') = ''")->exists()) {
            $obstacles[] = 'Not every received line has the supplier documentation reference.';
        }
        if (AdvanceSettlementService::unsettled($lot) > 0) {
            $obstacles[] = 'A supplier advance is still unsettled (settle it against accepted goods, or have it refunded or credited).';
        }
        if (AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('status', 'pending')->exists()) {
            $obstacles[] = 'A settlement is awaiting verification.';
        }

        return $obstacles;
    }

    public static function propose(SourcingLot $lot, string $recognisedOn, User $user): CostRecognition
    {
        try {
            return DB::transaction(function () use ($lot, $recognisedOn, $user) {
                $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
                if ($obstacles = self::obstacles($lot)) {
                    throw new RuntimeException($obstacles[0]);
                }
                if ($recognisedOn > today()->toDateString()) {
                    throw new RuntimeException('The recognition date cannot be in the future.');
                }
                if (AccountingService::isClosed($recognisedOn)) {
                    throw new RuntimeException('That month is already closed; recognise the cost in an open month.');
                }
                if (CostRecognition::where('sourcing_lot_id', $lot->id)->whereIn('status', ['pending', 'verified'])->exists()) {
                    throw new RuntimeException('This lot\'s cost has already been released (or a release is awaiting verification).');
                }
                $amount = self::unreleased($lot);
                if ($amount <= 0) {
                    throw new RuntimeException('This lot holds no capitalised cost to release.');
                }
                $recognition = CostRecognition::create([
                    'sourcing_lot_id' => $lot->id, 'amount' => $amount, 'recognised_on' => $recognisedOn, 'trigger' => (string) SourcingBasis::recognitionTrigger(),
                    'created_by' => $user->id, 'active_lot_id' => $lot->id,
                ]);
                Audit::log('sourcing.cost_recognition_proposed', 'lot '.$lot->number, (string) $amount);

                return $recognition;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('This lot\'s cost has already been released (or a release is awaiting verification).');
        }
    }

    public static function decide(CostRecognition $recognition, bool $approve, User $user): void
    {
        DB::transaction(function () use ($recognition, $approve, $user) {
            $lot = SourcingLot::whereKey($recognition->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $recognition = CostRecognition::whereKey($recognition->id)->lockForUpdate()->firstOrFail();
            if ($recognition->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($recognition->created_by === $user->id) {
                throw new RuntimeException('You cannot verify a release you proposed.');
            }
            if ($approve) {
                if ($obstacles = self::obstacles($lot)) {
                    throw new RuntimeException($obstacles[0]);
                }
                if ($recognition->amount !== self::held($lot)) {
                    throw new RuntimeException('The lot\'s capitalised cost changed since this release was proposed; propose it again.');
                }
                if (AccountingService::isClosed($recognition->recognised_on->toDateString())) {
                    throw new RuntimeException('That month is already closed.');
                }
                Ledger::post($recognition->recognised_on->toDateString(), 'Product cost recognised: lot '.$lot->number, 'cost_recognition', $recognition->id, [
                    ['expense', null, (int) $recognition->amount, 0], ['assets.inventory', null, 0, (int) $recognition->amount],
                ], $user->id);
            }
            $recognition->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now(), 'active_lot_id' => $approve ? $lot->id : null]);
            Audit::log('sourcing.cost_recognition_'.($approve ? 'verified' : 'rejected'), 'lot '.$lot->number, (string) $recognition->amount);
        });
    }

    public static function reverse(CostRecognition $recognition, string $reason, User $user): void
    {
        DB::transaction(function () use ($recognition, $reason, $user) {
            $recognition = CostRecognition::whereKey($recognition->id)->lockForUpdate()->firstOrFail();
            if ($recognition->status !== 'verified') {
                throw new RuntimeException('Only a verified release can be reversed.');
            }
            if ($recognition->created_by === $user->id) {
                throw new RuntimeException('You cannot reverse a release you proposed.');
            }
            if (AccountingService::isClosed($recognition->recognised_on->toDateString())) {
                throw new RuntimeException('The month of this release is closed.');
            }
            $original = JournalEntry::where('source_type', 'cost_recognition')->where('source_id', $recognition->id)->firstOrFail();
            Ledger::reverse($original, $original->entry_date->toDateString(), 'Reversal of cost recognition #'.$recognition->id.': '.$reason, 'cost_recognition_rev', $recognition->id, $user->id);
            $recognition->update(['status' => 'reversed', 'active_lot_id' => null]);
            Audit::log('sourcing.cost_recognition_reversed', 'lot '.$recognition->lot?->number, $reason);
        });
    }
}
