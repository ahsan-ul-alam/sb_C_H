<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\JournalEntry;
use App\Models\SourcingLot;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Event B of the supplier-advance policy. A supplier was paid in full BEFORE the goods came; the payment sits in
 * assets.supplier_advances. When goods are received and ACCEPTED (with the supplier's documentation) the advance for those goods is
 * settled into product acquisition cost:
 *
 *      Dr assets.inventory (product acquisition) / Cr assets.supplier_advances        no cash moves
 *
 * Only accepted goods settle. What was not accepted (shortage, damage, dispute) stays an identifiable advance, never an automatic
 * loss, until it is refunded, credited or the goods arrive. Settlements are proposed by one person and verified by another, are
 * computed as a delta (so repeating the action changes nothing), refuse closed months, and are undone only by a reversing entry.
 */
class AdvanceSettlementService
{
    /** Verified supplier advances of a lot (payments whose expense is classified supplier_advance and verified), oldest first. */
    public static function advancePayments(SourcingLot $lot, ?int $supplierId = null): Collection
    {
        return $lot->payments()->with(['expense', 'corrections'])->get()
            ->filter(fn (SupplierPayment $p) => ! $p->isVoided() && $p->expense?->classification === 'supplier_advance' && $p->expense->status === 'verified')
            ->when($supplierId, fn ($c) => $c->where('supplier_id', $supplierId))->sortBy('id')->values();
    }

    public static function settledAmount(SupplierPayment $payment, bool $verifiedOnly = false): int
    {
        return (int) AdvanceSettlement::where('supplier_payment_id', $payment->id)->whereIn('status', $verifiedOnly ? ['verified'] : ['pending', 'verified'])->sum('amount');
    }

    /**
     * What an advance still holds as an asset: paid (after cash refunds, which effectiveAmount removes) minus verified supplier credit
     * notes (the supplier owes us a credit instead of cash) minus what has been settled into cost.
     */
    public static function held(SupplierPayment $payment, bool $verifiedSettlementsOnly = false): int
    {
        $creditNotes = (int) $payment->corrections->where('status', 'verified')->where('correction_type', 'credit_note')->sum('amount');

        return max(0, $payment->effectiveAmount() - $creditNotes - self::settledAmount($payment, $verifiedSettlementsOnly));
    }

    /** What can still be proposed for settlement (pending proposals already count as used). */
    public static function available(SupplierPayment $payment): int
    {
        return self::held($payment);
    }

    /** Advance money still held for a lot. A valid outstanding advance is an asset, not an error. */
    public static function unsettled(SourcingLot $lot, ?int $supplierId = null): int
    {
        return (int) self::advancePayments($lot, $supplierId)->sum(fn (SupplierPayment $p) => self::held($p, true));
    }

    /** Verified payments of a lot's supplier made under the cash basis (already expensed): they are cost already, never inventory or advance. */
    public static function legacyPaid(SourcingLot $lot, int $supplierId): int
    {
        return (int) $lot->payments()->with(['expense', 'corrections'])->get()
            ->filter(fn (SupplierPayment $p) => $p->supplier_id === $supplierId && ! $p->isVoided() && $p->expense?->status === 'verified'
                && ! in_array($p->expense->classification, ['supplier_advance', 'supplier_payable'], true))
            ->sum(fn (SupplierPayment $p) => $p->effectiveAmount());
    }

    /**
     * Accepted goods the supplier has not been paid for yet (a payable): verified "payable" settlements minus verified payments that settle
     * them (supplier payments classified supplier_payable).
     */
    public static function payableOutstanding(SourcingLot $lot, int $supplierId): int
    {
        $owed = (int) AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('supplier_id', $supplierId)->where('kind', 'payable')->where('status', 'verified')->sum('amount');
        $paid = (int) $lot->payments()->with(['expense', 'corrections'])->get()
            ->filter(fn (SupplierPayment $p) => $p->supplier_id === $supplierId && ! $p->isVoided() && $p->expense?->classification === 'supplier_payable' && $p->expense->status === 'verified')
            ->sum(fn (SupplierPayment $p) => $p->effectiveAmount());

        return max(0, $owed - $paid);
    }

    /** Every supplier payable still owed for goods accepted under the advance model (all lots). */
    public static function payablesOutstanding(): int
    {
        return (int) SourcingLot::whereHas('advanceSettlements', fn ($q) => $q->where('kind', 'payable')->where('status', 'verified'))->get()
            ->sum(fn (SourcingLot $lot) => $lot->advanceSettlements()->where('kind', 'payable')->where('status', 'verified')->distinct()->pluck('supplier_id')
                ->sum(fn ($supplierId) => self::payableOutstanding($lot, (int) $supplierId)));
    }

    /** Purchase value of the goods ACCEPTED so far for a supplier: accepted quantity × the unit price, only for lines with a recorded arrival. */
    public static function acceptedValue(SourcingLot $lot, int $supplierId): int
    {
        return (int) $lot->lines()->where('supplier_id', $supplierId)->whereNotNull('received_quantity')->get()
            ->sum(fn ($l) => Money::multiply((string) $l->usable_quantity, (int) $l->unit_price));
    }

    /**
     * Propose settling the advances of one supplier up to the value of the goods accepted so far. Returns the new pending settlements;
     * nothing new when everything accepted is already settled or pending, so repeating it is harmless.
     *
     * @return Collection<int, AdvanceSettlement>
     */
    public static function propose(SourcingLot $lot, int $supplierId, User $user): Collection
    {
        try {
            return DB::transaction(function () use ($lot, $supplierId, $user) {
                $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
                if ($lot->status === 'cancelled') {
                    throw new RuntimeException('This lot is cancelled.');
                }
                $received = $lot->lines()->where('supplier_id', $supplierId)->whereNotNull('received_quantity')->get();
                if ($received->isEmpty()) {
                    throw new RuntimeException('Record the arrival and acceptance of the goods first: an advance is settled only for goods that were received.');
                }
                $undocumented = $received->filter(fn ($l) => trim((string) $l->acceptance_reference) === '');
                if ($undocumented->isNotEmpty()) {
                    throw new RuntimeException('Enter the supplier delivery or inspection reference for: '.$undocumented->pluck('description')->implode(', ').'.');
                }

                // Accepted goods not yet covered by a settlement, nor by money already paid and expensed under the cash basis.
                $need = self::acceptedValue($lot, $supplierId) - self::legacyPaid($lot, $supplierId)
                    - (int) AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('supplier_id', $supplierId)->whereIn('status', ['pending', 'verified'])->sum('amount');
                $date = $received->max('arrived_on')?->toDateString() ?? today()->toDateString();
                $created = collect();
                foreach (self::advancePayments($lot, $supplierId) as $payment) {
                    if ($need <= 0) {
                        break;
                    }
                    $take = min($need, self::available($payment));
                    if ($take <= 0) {
                        continue;
                    }
                    $created->push(AdvanceSettlement::create([
                        'sourcing_lot_id' => $lot->id, 'supplier_id' => $supplierId, 'supplier_payment_id' => $payment->id, 'kind' => 'advance', 'amount' => $take, 'settled_on' => $date,
                        'created_by' => $user->id, 'idempotency_key' => 'adv-'.$lot->id.'-'.$supplierId.'-p'.$payment->id.'-'.self::settledAmount($payment),
                    ]));
                    $need -= $take;
                }
                // Accepted goods beyond the money advanced are owed to the supplier: a payable, capitalised with the goods.
                if ($need > 0) {
                    $owed = (int) AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('supplier_id', $supplierId)->where('kind', 'payable')->whereIn('status', ['pending', 'verified'])->sum('amount');
                    $created->push(AdvanceSettlement::create([
                        'sourcing_lot_id' => $lot->id, 'supplier_id' => $supplierId, 'supplier_payment_id' => null, 'kind' => 'payable', 'amount' => $need, 'settled_on' => $date,
                        'created_by' => $user->id, 'idempotency_key' => 'pay-'.$lot->id.'-'.$supplierId.'-'.$owed,
                    ]));
                }
                foreach ($created as $s) {
                    Audit::log('sourcing.advance.settlement_proposed', 'lot '.$lot->number, 'supplier '.$supplierId.' '.$s->amount);
                }

                return $created;
            });
        } catch (UniqueConstraintViolationException) {
            return collect();
        }
    }

    public static function decide(AdvanceSettlement $settlement, bool $approve, User $user, ?string $note = null): void
    {
        if (! $approve && trim((string) $note) === '') {
            throw new RuntimeException('Give a reason for rejecting.');
        }
        DB::transaction(function () use ($settlement, $approve, $user, $note) {
            $lot = SourcingLot::whereKey($settlement->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $settlement = AdvanceSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($settlement->created_by === $user->id) {
                throw new RuntimeException('You cannot verify a settlement you proposed.');
            }
            if ($approve) {
                if (AccountingService::isClosed($settlement->settled_on->toDateString())) {
                    throw new RuntimeException('That month is already closed; the settlement cannot be dated in it.');
                }
                if ($settlement->kind === 'payable') {
                    Ledger::post($settlement->settled_on->toDateString(), 'Accepted goods not yet paid for (supplier payable): lot '.$lot->number, 'advance_settlement', $settlement->id, [
                        ['assets.inventory', null, (int) $settlement->amount, 0], ['liabilities', null, 0, (int) $settlement->amount],
                    ], $user->id);
                } else {
                    $payment = SupplierPayment::with(['expense', 'corrections'])->findOrFail($settlement->supplier_payment_id);
                    if ($payment->isVoided() || $payment->expense?->status !== 'verified') {
                        throw new RuntimeException('The supplier advance behind this settlement is no longer in force.');
                    }
                    if ($settlement->amount > self::held($payment, true)) {
                        throw new RuntimeException('That advance no longer holds enough to settle (a refund or another settlement came first).');
                    }
                    Ledger::post($settlement->settled_on->toDateString(), 'Supplier advance settled into product acquisition: lot '.$lot->number, 'advance_settlement', $settlement->id, [
                        ['assets.inventory', null, (int) $settlement->amount, 0], ['assets.supplier_advances', null, 0, (int) $settlement->amount],
                    ], $user->id);
                }
            }
            $settlement->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now(), 'decision_note' => trim((string) $note) ?: null]);
            Audit::log('sourcing.advance.settlement_'.($approve ? 'verified' : 'rejected'), 'lot '.$lot->number, (string) $settlement->amount);
        });
    }

    /** Undo a verified settlement with a reversing entry. Not possible once the lot's cost has been released into profit. */
    public static function reverse(AdvanceSettlement $settlement, string $reason, User $user): void
    {
        DB::transaction(function () use ($settlement, $reason, $user) {
            $lot = SourcingLot::whereKey($settlement->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $settlement = AdvanceSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'verified') {
                throw new RuntimeException('Only a verified settlement can be reversed.');
            }
            if ($settlement->created_by === $user->id) {
                throw new RuntimeException('You cannot reverse a settlement you proposed.');
            }
            if ($lot->costRecognitions()->whereIn('status', ['pending', 'verified'])->exists()) {
                throw new RuntimeException('This lot\'s cost has been (or is being) released into profit; reverse that first.');
            }
            if (AccountingService::isClosed($settlement->settled_on->toDateString())) {
                throw new RuntimeException('The month of this settlement is closed.');
            }
            if ($settlement->kind === 'payable' && self::payableOutstanding($lot, $settlement->supplier_id) < $settlement->amount) {
                throw new RuntimeException('Part of this payable has already been paid; refund or correct that payment first.');
            }
            $original = JournalEntry::where('source_type', 'advance_settlement')->where('source_id', $settlement->id)->firstOrFail();
            Ledger::reverse($original, $original->entry_date->toDateString(), 'Reversal of advance settlement #'.$settlement->id.': '.$reason, 'advance_settlement_rev', $settlement->id, $user->id);
            $settlement->update(['status' => 'reversed']);
            Audit::log('sourcing.advance.settlement_reversed', 'lot '.$lot->number, $reason);
        });
    }
}
