<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cash going out for a lot. The lot's COST is recognised in LotFinancials; here only the PAYMENT creates an
 * Expense. Record a payment only after the money has actually left the account — the expense is then verified
 * automatically under a standing Board policy, or by the Board, and posted to the ledger once.
 * An unpaid payable never reduces the lot's cost.
 */
class SourcingPayments
{
    /** Economic events a verified payment can be corrected for. */
    public const CORRECTIONS = [
        'recording_error' => 'Recording error / duplicate — the money never left (cash is restored to its true balance; the payable returns)',
        'refund_received' => 'Supplier refunded money to us (cash comes back; the payable returns)',
        'credit_note' => 'Supplier credit note — no cash moves (recorded as a supplier credit; the payment stays paid)',
    ];

    /**
     * @param  array{sourcing?: bool, lot_ok?: bool, unexpected?: bool}  $context
     * @param  array<string, mixed>  $extra
     */
    private static function expenseFor(string $category, string $description, int $amount, string $date, User $user, array $context = [], array $extra = []): Expense
    {
        if (AccountingPeriod::where('month', substr($date, 0, 7))->exists()) {
            throw new RuntimeException('That month is already closed; record it in an open month.');
        }

        return ExpenseVerifier::record(['category' => $category, 'description' => $description, 'amount' => $amount, 'spent_on' => $date, ...$extra], $user, $context);
    }

    private static function pricingApproved(SourcingLot $lot): bool
    {
        return in_array($lot->status, ['approved', 'in_progress', 'completed'], true);
    }

    /**
     * @param  array{supplier_id: int, amount: int, paid_on: string, method?: ?string, reference?: ?string, notes?: ?string, idempotency_key?: ?string, purchase_order?: ?string, evidence_reference?: ?string, financial_account_id?: ?int}  $data
     * @return SupplierPayment|null null when the same submission was already recorded
     */
    public static function recordSupplierPayment(SourcingLot $lot, array $data, User $user): ?SupplierPayment
    {
        $key = ($data['idempotency_key'] ?? null) ?: (string) str()->uuid();
        if (SupplierPayment::where('idempotency_key', $key)->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($lot, $data, $user, $key) {
                $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
                if ($lot->isClosed()) {
                    throw new RuntimeException('This lot is '.strtolower($lot->statusLabel()).'; reopen it to adjust payments.');
                }
                if ($data['amount'] <= 0) {
                    throw new RuntimeException('Amount must be greater than zero.');
                }
                $supplierId = (int) $data['supplier_id'];
                $payable = $lot->lines->where('supplier_id', $supplierId)->sum(fn ($l) => $l->purchaseCost());
                $paid = self::paidTotal($lot->payments()->where('supplier_id', $supplierId)->with(['expense', 'corrections'])->get());
                if ($data['amount'] > $payable - $paid) {
                    throw new RuntimeException('Payment exceeds what this lot owes the supplier ('.Money::format(max(0, $payable - $paid)).' outstanding).');
                }
                // Settlements awaiting the Board decide whether accepted goods are covered by an advance or are a payable. A payment recorded
                // meanwhile would be classified on a position that is about to change (a payable paid as a new advance), so it waits.
                if (AdvanceSettlementService::pendingSettlements($lot, $supplierId) > 0) {
                    throw new RuntimeException('Settlements for this supplier await Board verification. Verify or reject them before recording another payment, so the payment is classified correctly.');
                }
                // Accrual basis (forward-only, Board-approved): the payment is a SUPPLIER ADVANCE, an asset, and must say what it paid for.
                $advance = SourcingBasis::accrualOn($data['paid_on']);
                // Accepted goods not yet paid for are a payable: paying it is a settlement of that liability (Dr payable / Cr cash), not a new advance.
                // Payments of it still awaiting verification count here, so two pending payments cannot together pay more than is owed.
                $payableDue = $advance ? AdvanceSettlementService::payableOutstanding($lot, $supplierId, includePending: true) : 0;
                if ($payableDue > 0 && $data['amount'] > $payableDue) {
                    throw new RuntimeException('The supplier is owed '.Money::format($payableDue).' for accepted goods; pay that amount (or less) first.');
                }
                if ($advance) {
                    $required = $payableDue > 0 ? ['reference' => 'the payment reference']
                        : ['purchase_order' => 'the purchase order', 'reference' => 'the payment reference', 'evidence_reference' => 'the supporting evidence (supplier invoice, proforma or payment slip)'];
                    foreach ($required as $field => $label) {
                        if (trim((string) ($data[$field] ?? '')) === '') {
                            throw new RuntimeException('A supplier '.($payableDue > 0 ? 'payment' : 'advance').' needs '.$label.'.');
                        }
                    }
                }
                $expense = self::expenseFor('Sourcing: supplier payment', $lot->number.' — supplier #'.$supplierId.($data['reference'] ?? null ? ' ref '.$data['reference'] : ''),
                    $data['amount'], $data['paid_on'], $user, ['sourcing' => true, 'lot_ok' => self::pricingApproved($lot)],
                    $advance ? ['classification' => $payableDue > 0 ? 'supplier_payable' : 'supplier_advance', 'financial_account_id' => $data['financial_account_id'] ?? null, 'vendor' => $lot->lines->firstWhere('supplier_id', $supplierId)?->supplier?->name] : []);
                $payment = SupplierPayment::create([
                    'supplier_id' => $supplierId, 'sourcing_lot_id' => $lot->id, 'amount' => $data['amount'], 'paid_on' => $data['paid_on'],
                    'method' => $data['method'] ?? null, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                    'purchase_order' => $data['purchase_order'] ?? null, 'evidence_reference' => $data['evidence_reference'] ?? null,
                    'idempotency_key' => $key, 'expense_id' => $expense->id, 'created_by' => $user->id,
                ]);
                Audit::log('sourcing.supplier_payment', 'lot '.$lot->number, 'supplier '.$supplierId.' '.$data['amount'].($expense->auto_policy_id ? ' (auto-verified, policy #'.$expense->auto_policy_id.')' : ''));

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** What has really been paid, after rejected expenses, voids and cash-restoring corrections. */
    public static function paidTotal(iterable $payments): int
    {
        return (int) collect($payments)->sum(fn (SupplierPayment $p) => $p->effectiveAmount());
    }

    /**
     * Undo or correct a payment.
     * - Not yet verified: it is simply withdrawn (the pending expense is rejected).
     * - Verified: the posted entry is never edited. A separate correcting expense states the real economic event and
     *   needs Board verification (never automatic); only then do the ledger and the payable change.
     */
    public static function voidSupplierPayment(SupplierPayment $payment, string $reason, User $user, string $type = 'recording_error', ?int $amount = null, ?string $reference = null, ?int $refundAccountId = null): void
    {
        DB::transaction(function () use ($payment, $reason, $user, $type, $amount, $reference, $refundAccountId) {
            $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $expense = Expense::whereKey($payment->expense_id)->lockForUpdate()->firstOrFail();
            if ($payment->isVoided()) {
                throw new RuntimeException('This payment is already voided.');
            }
            if ($expense->status === 'verified') {
                if (! array_key_exists($type, self::CORRECTIONS)) {
                    throw new RuntimeException('Choose what actually happened: a recording error, a supplier refund or a credit note.');
                }
                if ($payment->reversal_expense_id && Expense::whereKey($payment->reversal_expense_id)->where('status', 'pending')->exists()) {
                    throw new RuntimeException('A correction for this payment is already awaiting Board verification.');
                }
                if (in_array($type, ['refund_received', 'credit_note'], true) && trim((string) $reference) === '') {
                    throw new RuntimeException('Enter the bank receipt or credit-note reference.');
                }
                // An advance already settled into accepted goods is no longer refundable: only what is still held can be returned.
                $remaining = $payment->amount - (int) $payment->corrections()->whereIn('status', ['verified', 'pending'])->sum('amount') - AdvanceSettlementService::settledAmount($payment);
                $amount ??= $remaining;
                if ($amount <= 0 || $amount > $remaining) {
                    throw new RuntimeException('The correction must be between 0.01 and '.Money::format($remaining).' (what is not yet corrected).');
                }
                $reversal = self::expenseFor('Sourcing: supplier payment correction', 'Reverses expense #'.$expense->id.' ('.$type.') — '.$reason.($reference ? ' ref '.$reference : ''), $amount, today()->toDateString(), $user, [],
                    // Money coming back goes to the account that actually receives it: the one chosen here, else the one it left from
                    // (never silently to the default account). The correction keeps the classification of the payment it reverses, so a
                    // refund of an advance (an asset) is not read as a cost reduction; a cash-basis payment keeps its cash-basis treatment.
                    ['reverses_id' => $expense->id, 'correction_type' => $type, 'classification' => $expense->classification, 'is_asset' => $expense->is_asset,
                        'financial_account_id' => $type === 'credit_note' ? $expense->financial_account_id : ($refundAccountId ?? $expense->financial_account_id)]);
                $payment->update(['reversal_expense_id' => $reversal->id, 'void_reason' => $reason]);
                Audit::log('sourcing.supplier_payment.correction_requested', 'lot '.optional($payment->lot)->number, $type.' '.$amount.': '.$reason);

                return;
            }
            if ($expense->status === 'pending') {
                $expense->update(['status' => 'rejected']);
            }
            $payment->update(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
            Audit::log('sourcing.supplier_payment.voided', 'lot '.optional($payment->lot)->number, $reason);
        });
    }

    /** Called when the Board decides any expense: finishes the bookkeeping of a correction on its payment. */
    public static function onCorrectionDecided(Expense $expense, bool $approved): void
    {
        if (! $expense->reverses_id) {
            return;
        }
        $payment = SupplierPayment::where('expense_id', $expense->reverses_id)->first();
        if (! $payment) {
            return;
        }
        if ($payment->reversal_expense_id === $expense->id) {
            $payment->update(['reversal_expense_id' => null]);
        }
        $payment->load('corrections');
        if ($approved && $payment->effectiveAmount() === 0 && ! $payment->isVoided()) {
            $payment->update(['voided_at' => now(), 'voided_by' => $expense->verified_by]);
        }
    }

    /**
     * Pay a recorded ACTUAL cost (transport, VAT, ...). Creates its expense once; a rejected expense lets it be paid again.
     */
    public static function payCost(SourcingLotCost $cost, string $paidOn, User $user, ?int $accountId = null, bool $asPayable = false, bool $prepaid = false): void
    {
        DB::transaction(function () use ($cost, $paidOn, $user, $accountId, $asPayable, $prepaid) {
            $lot = SourcingLot::whereKey($cost->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $cost = SourcingLotCost::with('expense')->findOrFail($cost->id);
            if ($lot->isClosed()) {
                throw new RuntimeException('This lot is '.strtolower($lot->statusLabel()).'.');
            }
            if ($cost->kind !== 'actual') {
                throw new RuntimeException('Record the actual cost before paying it.');
            }
            if ($cost->expense_id && $cost->expense?->status !== 'rejected') {
                throw new RuntimeException($cost->isPaid() ? 'This cost is already paid.' : 'This cost is already recorded as a payable; pay it under Expenses → Payments.');
            }
            $accrual = SourcingBasis::accrualOn($paidOn);
            if (($asPayable || $prepaid) && ! $accrual) {
                throw new RuntimeException('Recording a cost as a payable or a prepayment needs the accrual sourcing basis; under the cash basis a cost is recorded when it is paid.');
            }
            if ($asPayable && $prepaid) {
                throw new RuntimeException('A cost is either paid in advance or incurred and unpaid, not both.');
            }
            $expense = self::expenseFor('Sourcing: '.$cost->category, $lot->number.($cost->description ? ' — '.$cost->description : ''), $cost->amount, $paidOn, $user,
                ['sourcing' => true, 'lot_ok' => self::pricingApproved($lot), 'unexpected' => $cost->is_unexpected],
                // Accrual basis: a transport/loading/handling cost is part of the product's acquisition cost (inventory), not an expense. Paid now:
                // Dr inventory / Cr cash account (even if the service is still to come: the cost stays in inventory, never in profit). Incurred but
                // unpaid: Dr inventory / Cr payable, paid later through the normal expense payment, which cannot deduct cash twice.
                // Paid BEFORE the service: Dr transport prepayment / Cr cash, until the service is confirmed (completeService).
                $accrual ? ['classification' => $prepaid ? 'transport_prepayment' : 'acquisition', 'financial_account_id' => $accountId, 'payment_status' => $asPayable ? 'unpaid' : 'paid', 'vendor' => $cost->payee] : ($accountId ? ['financial_account_id' => $accountId] : []));
            $cost->update(['expense_id' => $expense->id]);
            Audit::log('sourcing.cost_paid', 'lot '.$lot->number, $cost->category.' '.$cost->amount.($expense->auto_policy_id ? ' (auto-verified, policy #'.$expense->auto_policy_id.')' : ''));
        });
    }

    /**
     * The transport (or handling) service paid in advance has been performed: its cost moves from the prepayment into the product's acquisition
     * cost, Dr inventory / Cr transport prepayment. No cash moves. Confirmed by someone other than the person who recorded the payment and
     * holding the Board's approval right; done once; never in a closed month.
     */
    public static function completeService(SourcingLotCost $cost, string $on, User $user): void
    {
        DB::transaction(function () use ($cost, $on, $user) {
            $lot = SourcingLot::whereKey($cost->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $cost = SourcingLotCost::with('expense')->whereKey($cost->id)->lockForUpdate()->firstOrFail();
            $expense = $cost->expense;
            if (! $expense || $expense->classification !== 'transport_prepayment' || $expense->status !== 'verified') {
                throw new RuntimeException('Only a verified advance payment for transport can be confirmed as a completed service.');
            }
            if ($cost->service_completed_on !== null) {
                throw new RuntimeException('This service has already been confirmed.');
            }
            if (! $user->can('sourcing.advance.approve') || $expense->created_by === $user->id) {
                throw new RuntimeException('The service must be confirmed by a Board member other than the person who recorded the payment.');
            }
            if ($on > today()->toDateString() || $on < $expense->spent_on->toDateString()) {
                throw new RuntimeException('The service date must be between the payment date and today.');
            }
            if (AccountingService::isClosed($on)) {
                throw new RuntimeException('That month is already closed; confirm the service in an open month.');
            }
            $amount = (int) $expense->amount - (int) Expense::where('reverses_id', $expense->id)->where('status', 'verified')->sum('amount');
            if ($amount <= 0) {
                throw new RuntimeException('This prepayment has been refunded; there is no cost to move.');
            }
            Ledger::post($on, 'Transport service done, prepayment moved into acquisition cost: lot '.$lot->number, 'transport_service', $cost->id, [
                ['assets.inventory', null, $amount, 0], ['assets.transport_prepaid', null, 0, $amount],
            ], $user->id);
            $cost->update(['service_completed_on' => $on]);
            Audit::log('sourcing.transport_service_done', 'lot '.$lot->number, $cost->category.' '.$amount);
            InventoryValuation::syncLot($lot->id);
        });
    }
}
