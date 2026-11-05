<?php

namespace App\Services;

use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\InventoryEngineEnrolment;
use App\Models\SourcingLot;
use App\Models\SupplierPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Which lots the inventory valuation engine may value, and how a lot gets there. Entry is explicit, approved and forward-only:
 *  - the engine is off until finance.inventory_engine_from is set;
 *  - a lot is enrolled by a proposal naming its ownership, the evidence for it and an effective date, approved by a DIFFERENT authorised user;
 *  - ownership is never assumed: a lot with no stated ownership is classified by that approval, and only then;
 *  - only Sellbees-owned (company) stock is valued; client-owned stock is custody only and its value is never a Sellbees asset;
 *  - a lot whose cost was already expensed under the cash basis, or already released as a whole lot, stays outside for good (no second recognition).
 */
class InventoryEngine
{
    /** Expense classifications of the accrual sourcing basis; anything else linked to a lot was expensed when paid. */
    private const ACCRUAL_CLASSIFICATIONS = ['supplier_advance', 'supplier_payable', 'acquisition', 'transport_prepayment'];

    /** First day of the month the engine starts, or null while it is switched off. */
    public static function engineFrom(): ?string
    {
        $from = config('finance.inventory_engine_from');

        return $from ? CarbonImmutable::parse((string) $from)->startOfMonth()->toDateString() : null;
    }

    public static function enrolment(SourcingLot $lot): ?InventoryEngineEnrolment
    {
        return InventoryEngineEnrolment::where('sourcing_lot_id', $lot->id)->first();
    }

    /** True when the engine values this lot (an approved enrolment of Sellbees-owned stock). */
    public static function isValued(SourcingLot $lot): bool
    {
        return (bool) self::enrolment($lot)?->isValued();
    }

    /** True when any enrolment exists for the lot: the legacy whole-lot cost release must then leave it alone. */
    public static function isOnEngine(SourcingLot $lot): bool
    {
        return InventoryEngineEnrolment::where('sourcing_lot_id', $lot->id)->whereIn('status', ['pending', 'approved'])->exists();
    }

    /**
     * Why this lot cannot (or can no longer) be valued as $ownership. Empty means it can.
     *
     * @return list<string>
     */
    public static function ineligibility(SourcingLot $lot, string $ownership): array
    {
        $reasons = [];
        if (self::engineFrom() === null) {
            $reasons[] = 'The inventory valuation engine is not switched on (finance.inventory_engine_from).';
        }
        if ($lot->status === 'cancelled') {
            $reasons[] = 'The lot is cancelled.';
        }
        if (! in_array($ownership, ['company', 'client'], true)) {
            $reasons[] = 'State whether the stock is company-owned or client-owned.';
        }
        if ($lot->ownership !== null && $lot->ownership !== $ownership) {
            $reasons[] = 'The lot is already recorded as '.$lot->ownership.'-owned.';
        }
        if (CostRecognition::where('sourcing_lot_id', $lot->id)->exists()) {
            $reasons[] = 'The lot\'s cost was already released as a whole lot (legacy cost recognition); it stays outside the engine.';
        }
        $paymentExpenseIds = SupplierPayment::where('sourcing_lot_id', $lot->id)->pluck('expense_id');
        $costExpenseIds = $lot->costs()->whereNotNull('expense_id')->pluck('expense_id');
        if (Expense::whereIn('id', $paymentExpenseIds->merge($costExpenseIds))->whereIn('status', ['pending', 'verified'])->whereNull('reverses_id')
            ->whereNotIn('classification', self::ACCRUAL_CLASSIFICATIONS)->exists()) {
            $reasons[] = 'Part of the lot\'s cost was already expensed under the cash basis; it stays outside the engine so it is never recognised twice.';
        }

        return $reasons;
    }

    /** Propose bringing a lot into the engine. The ownership classification, its evidence and the effective date are recorded for the approver. */
    public static function propose(SourcingLot $lot, string $ownership, string $classificationSource, string $effectiveOn, User $user): InventoryEngineEnrolment
    {
        return DB::transaction(function () use ($lot, $ownership, $classificationSource, $effectiveOn, $user) {
            $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (trim($classificationSource) === '') {
                throw new RuntimeException('State the evidence for the ownership classification (contract, resolution or signed delivery note).');
            }
            $existing = InventoryEngineEnrolment::where('sourcing_lot_id', $lot->id)->lockForUpdate()->first();
            if ($existing && $existing->status !== 'rejected') {
                throw new RuntimeException('This lot already has an enrolment ('.$existing->status.').');
            }
            if ($reasons = self::ineligibility($lot, $ownership)) {
                throw new RuntimeException($reasons[0]);
            }
            self::assertDate($effectiveOn);

            if ($existing) { // a rejected proposal can be made again with better evidence; the earlier decisions stay in the audit log
                $existing->update(['ownership' => $ownership, 'classification_source' => trim($classificationSource), 'effective_on' => $effectiveOn, 'status' => 'pending',
                    'prepared_by' => $user->id, 'approved_by' => null, 'approved_at' => null, 'decision_note' => null]);
                Audit::log('inventory.engine_reproposed', 'lot '.$lot->number, $ownership.' from '.$effectiveOn.': '.$existing->classification_source);

                return $existing->refresh();
            }
            $enrolment = InventoryEngineEnrolment::create(['sourcing_lot_id' => $lot->id, 'ownership' => $ownership, 'classification_source' => trim($classificationSource),
                'effective_on' => $effectiveOn, 'prepared_by' => $user->id]);
            Audit::log('inventory.engine_proposed', 'lot '.$lot->number, $ownership.' from '.$effectiveOn.': '.$enrolment->classification_source);

            return $enrolment;
        });
    }

    /** The Board's decision: a different authorised user approves or rejects. Approval classifies the lot's ownership (once) and starts its valuation. */
    public static function decide(InventoryEngineEnrolment $enrolment, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($enrolment, $approve, $user, $note) {
            $enrolment = InventoryEngineEnrolment::whereKey($enrolment->id)->lockForUpdate()->firstOrFail();
            $lot = SourcingLot::whereKey($enrolment->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            if ($enrolment->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($enrolment->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve an enrolment you proposed.');
            }
            if (! $user->can('sourcing.advance.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide an enrolment.');
            }
            if (! $approve && trim((string) $note) === '') {
                throw new RuntimeException('Give a reason for rejecting.');
            }
            if ($approve) {
                // The facts may have changed since the proposal.
                if ($reasons = self::ineligibility($lot, $enrolment->ownership)) {
                    throw new RuntimeException($reasons[0]);
                }
                self::assertDate($enrolment->effective_on->toDateString());
                if ($lot->ownership === null) {
                    $lot->update(['ownership' => $enrolment->ownership]); // the approved classification, recorded with its approver on the enrolment
                }
            }
            $enrolment->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null]);
            Audit::log('inventory.engine_'.($approve ? 'approved' : 'rejected'), 'lot '.$lot->number, $enrolment->ownership.' from '.$enrolment->effective_on->toDateString().(trim((string) $note) !== '' ? ': '.trim($note) : ''));
            if ($approve && $enrolment->ownership === 'company') {
                InventoryValuation::syncLot($lot->id);
            }
        });
    }

    private static function assertDate(string $effectiveOn): void
    {
        $from = self::engineFrom();
        if ($from !== null && $effectiveOn < $from) {
            throw new RuntimeException('The effective date cannot be before the month the engine starts ('.substr($from, 0, 7).').');
        }
        AccountingService::assertOpen($effectiveOn, 'an inventory enrolment');
    }
}
