<?php

namespace App\Services;

use App\Models\SourcingLot;
use App\Models\SupplierPayment;
use App\Support\Money;
use RuntimeException;

/**
 * Where a sourcing lot stands in the office's own words, with the two questions kept apart: OPERATIONAL (where are the goods?) and
 * FINANCIAL (what has the accounting done?). Everything is derived from records that already exist; the only value stored is the procurement
 * step, which moves one step at a time and cannot start before the supplier is paid. Also builds the guided checklist shown on the lot.
 */
class LotStatus
{
    /** A supplier payment that has been verified and is still in force. */
    public static function supplierPaid(SourcingLot $lot): bool
    {
        return $lot->payments()->with(['expense', 'corrections'])->get()
            ->contains(fn (SupplierPayment $p) => ! $p->isVoided() && $p->expense?->status === 'verified' && $p->effectiveAmount() > 0);
    }

    /** @return array{label: string, tone: string} */
    public static function operational(SourcingLot $lot): array
    {
        if ($lot->status === 'cancelled') {
            return ['label' => 'Cancelled', 'tone' => 'archived'];
        }
        $lines = $lot->lines()->get();
        $recorded = $lines->whereNotNull('received_quantity');
        if ($recorded->isNotEmpty()) {
            $complete = $recorded->count() === $lines->count() && (float) $recorded->sum('received_quantity') >= (float) $lines->sum('quantity');

            return $complete ? ['label' => 'Fully received', 'tone' => 'paid'] : ['label' => 'Partially received', 'tone' => 'partial'];
        }

        return match ($lot->procurement_status) {
            'ordered' => self::supplierPaid($lot) ? ['label' => 'Supplier paid', 'tone' => 'paid'] : ['label' => 'Awaiting supplier payment', 'tone' => 'unpaid'],
            'preparing', 'dispatched' => ['label' => 'Procurement in progress', 'tone' => 'partial'],
            'in_transit' => ['label' => 'In transit', 'tone' => 'partial'],
            default => ['label' => 'Arrived — to be inspected', 'tone' => 'partial'],
        };
    }

    /** Problems with the goods themselves, independent of the steps. @return list<string> */
    public static function flags(SourcingLot $lot): array
    {
        $flags = [];
        foreach ($lot->lines()->whereNotNull('received_quantity')->get() as $l) {
            $rejected = (float) $l->received_quantity - (float) $l->usable_quantity;
            $missing = (float) $l->quantity - (float) $l->received_quantity;
            if ($rejected > 0 || $missing > 0) {
                $flags[] = $l->description.': '.($rejected > 0 ? $rejected.' damaged/rejected' : '').($rejected > 0 && $missing > 0 ? ', ' : '').($missing > 0 ? $missing.' missing' : '');
            }
        }

        return $flags;
    }

    /** @return array{label: string, tone: string} */
    public static function financial(SourcingLot $lot): array
    {
        if ($lot->status === 'settled') {
            return ['label' => 'Financially completed', 'tone' => 'paid'];
        }
        $pending = $lot->advanceSettlements()->where('status', 'pending')->exists() || $lot->costRecognitions()->where('status', 'pending')->exists()
            || $lot->payments()->whereHas('expense', fn ($q) => $q->where('status', 'pending'))->exists()
            || $lot->costs()->whereHas('expense', fn ($q) => $q->where('status', 'pending'))->exists();
        if ($pending) {
            return ['label' => 'Awaiting financial approval', 'tone' => 'pending'];
        }
        if (! $lot->payments()->exists()) {
            return ['label' => 'No supplier payment recorded', 'tone' => 'unpaid'];
        }
        if (($held = AdvanceSettlementService::unsettled($lot)) > 0) {
            return ['label' => 'Supplier advance held ('.Money::format($held).')', 'tone' => 'partial'];
        }

        return ['label' => 'Open — complete costs and settle', 'tone' => 'partial'];
    }

    /**
     * Move the procurement step. One step at a time, forward only; it cannot start until the supplier payment is verified (accrual basis); and
     * "arrived" needs every line's arrival on record. This changes where the goods are, never the books.
     */
    public static function moveProcurement(SourcingLot $lot, string $to): void
    {
        $order = array_keys(SourcingLot::PROCUREMENT);
        $from = array_search($lot->procurement_status, $order, true);
        $target = array_search($to, $order, true);
        if ($target === false) {
            throw new RuntimeException('Unknown procurement step.');
        }
        if ($lot->isClosed()) {
            throw new RuntimeException('This lot is '.strtolower($lot->statusLabel()).' and can no longer be changed.');
        }
        if ($target === $from) {
            return;
        }
        if ($target < $from) {
            throw new RuntimeException('A lot cannot go back from "'.SourcingLot::PROCUREMENT[$lot->procurement_status].'" to "'.SourcingLot::PROCUREMENT[$to].'".');
        }
        if ($target !== $from + 1) {
            throw new RuntimeException('Move one step at a time: the next step after "'.SourcingLot::PROCUREMENT[$lot->procurement_status].'" is "'.SourcingLot::PROCUREMENT[$order[$from + 1]].'".');
        }
        if ($target >= 1 && SourcingBasis::accrualOn(today()->toDateString()) && ! self::supplierPaid($lot)) {
            throw new RuntimeException('Pay the supplier in full in advance, and have the payment verified, before procurement starts.');
        }
        if ($to === 'arrived' && $lot->lines()->whereNull('received_quantity')->exists()) {
            throw new RuntimeException('Record the arrival of every product line (Receiving tab) to mark the lot as arrived.');
        }
        $lot->update(['procurement_status' => $to]);
        Audit::log('sourcing.procurement', 'lot '.$lot->number, $to);
    }

    /**
     * The guided path from client project to finished project, each step with its state and the screen where it is done.
     *
     * @return list<array{label: string, done: bool, link: string, hint: string}>
     */
    public static function steps(SourcingLot $lot): array
    {
        $f = LotFinancials::for($lot);
        $tab = fn (string $t) => route('sourcing.lots.show', [$lot, 'tab' => $t]);
        $lines = $lot->lines()->get();
        $allReceived = $lines->isNotEmpty() && $lines->whereNull('received_quantity')->isEmpty();
        $paid = self::supplierPaid($lot);
        $settledOk = $allReceived && AdvanceSettlementService::unsettled($lot) === 0 && ! $lot->advanceSettlements()->where('status', 'pending')->exists();
        $projectId = $lot->project_id ?: $lot->allocations()->value('project_id');

        return [
            ['label' => 'Client project chosen', 'done' => (bool) $projectId, 'link' => $tab('receiving'), 'hint' => 'Set the project under Receiving & documents'],
            ['label' => 'Products and supplier added', 'done' => $lines->isNotEmpty(), 'link' => $tab('lines'), 'hint' => 'Add the product lines with supplier prices'],
            ['label' => 'Purchase order number', 'done' => (bool) $lot->purchase_order, 'link' => $tab('receiving'), 'hint' => 'Enter the PO number under Receiving & documents'],
            ['label' => 'Client price agreed and approved', 'done' => in_array($lot->status, ['approved', 'in_progress', 'completed', 'settled'], true), 'link' => $tab('pricing'), 'hint' => 'Price the lot and have it approved'],
            ['label' => 'Allocated to the client', 'done' => $lot->allocations()->exists(), 'link' => $tab('billing'), 'hint' => 'Allocate quantities to the project and bill them'],
            ['label' => 'Supplier paid in advance (verified)', 'done' => $paid, 'link' => $tab('payments'), 'hint' => 'Record the full advance; a Board member verifies it'],
            ['label' => 'Procurement and transport under way', 'done' => array_search($lot->procurement_status, array_keys(SourcingLot::PROCUREMENT), true) >= 2 || $allReceived, 'link' => $tab('receiving'), 'hint' => 'Update the procurement status as the goods move'],
            ['label' => 'Goods received and inspected', 'done' => $allReceived, 'link' => $tab('receiving'), 'hint' => 'Record received, accepted, damaged and missing quantities'],
            ['label' => 'Transport and handling costs finalised', 'done' => $f['costs_unpaid'] === 0 && $f['estimates_open'] === 0 && CostRecognitionService::unconfirmedPrepayments($lot) === 0 && $lot->costs()->exists(), 'link' => $tab('lines'), 'hint' => 'Record each actual cost and pay it, record it as a payable, or confirm the prepaid service'],
            ['label' => 'Supplier advance settled (approved)', 'done' => $settledOk, 'link' => route('sourcing.advances'), 'hint' => 'Propose the settlement; a Board member approves it'],
            ['label' => 'Financially completed', 'done' => $lot->status === 'settled', 'link' => $tab('payments'), 'hint' => 'Settle the lot once every step above is done'],
            ['label' => 'Project financial overview', 'done' => $lot->status === 'settled' && (bool) $projectId, 'link' => $projectId ? route('sourcing.projects.show', $projectId) : $tab('receiving'), 'hint' => 'Review margin and cash surplus'],
        ];
    }
}
