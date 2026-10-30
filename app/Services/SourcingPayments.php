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
     * @param  array{supplier_id: int, amount: int, paid_on: string, method?: ?string, reference?: ?string, notes?: ?string, idempotency_key?: ?string}  $data
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
                $expense = self::expenseFor('Sourcing: supplier payment', $lot->number.' — supplier #'.$supplierId.($data['reference'] ?? null ? ' ref '.$data['reference'] : ''),
                    $data['amount'], $data['paid_on'], $user, ['sourcing' => true, 'lot_ok' => self::pricingApproved($lot)]);
                $payment = SupplierPayment::create([
                    'supplier_id' => $supplierId, 'sourcing_lot_id' => $lot->id, 'amount' => $data['amount'], 'paid_on' => $data['paid_on'],
                    'method' => $data['method'] ?? null, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
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
    public static function voidSupplierPayment(SupplierPayment $payment, string $reason, User $user, string $type = 'recording_error', ?int $amount = null, ?string $reference = null): void
    {
        DB::transaction(function () use ($payment, $reason, $user, $type, $amount, $reference) {
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
                $remaining = $payment->amount - (int) $payment->corrections()->whereIn('status', ['verified', 'pending'])->sum('amount');
                $amount ??= $remaining;
                if ($amount <= 0 || $amount > $remaining) {
                    throw new RuntimeException('The correction must be between 0.01 and '.Money::format($remaining).' (what is not yet corrected).');
                }
                $reversal = self::expenseFor('Sourcing: supplier payment correction', 'Reverses expense #'.$expense->id.' ('.$type.') — '.$reason.($reference ? ' ref '.$reference : ''), $amount, today()->toDateString(), $user, [],
                    ['reverses_id' => $expense->id, 'correction_type' => $type]);
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
    public static function payCost(SourcingLotCost $cost, string $paidOn, User $user): void
    {
        DB::transaction(function () use ($cost, $paidOn, $user) {
            $lot = SourcingLot::whereKey($cost->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $cost = SourcingLotCost::with('expense')->findOrFail($cost->id);
            if ($lot->isClosed()) {
                throw new RuntimeException('This lot is '.strtolower($lot->statusLabel()).'.');
            }
            if ($cost->kind !== 'actual') {
                throw new RuntimeException('Record the actual cost before paying it.');
            }
            if ($cost->isPaid()) {
                throw new RuntimeException('This cost is already paid.');
            }
            $expense = self::expenseFor('Sourcing: '.$cost->category, $lot->number.($cost->description ? ' — '.$cost->description : ''), $cost->amount, $paidOn, $user,
                ['sourcing' => true, 'lot_ok' => self::pricingApproved($lot), 'unexpected' => $cost->is_unexpected]);
            $cost->update(['expense_id' => $expense->id]);
            Audit::log('sourcing.cost_paid', 'lot '.$lot->number, $cost->category.' '.$cost->amount.($expense->auto_policy_id ? ' (auto-verified, policy #'.$expense->auto_policy_id.')' : ''));
        });
    }
}
