<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Models\ReceiptClassification;
use App\Models\ReceiptReview;
use App\Models\RevenueAdjustment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Voiding a customer payment, safely. A payment is never deleted or edited: an approved void only stamps payments.voided_at, which removes the payment from
 * every active total (collections, invoice balances, project receipts, cash, dashboards) through the Payment model's default scope. The row, its allocations
 * and its audit trail stay for good.
 *
 * Maker-checker: Finance proposes with a reason and the bank/bKash evidence; a DIFFERENT Board member approves or rejects. A payment that is protected
 * (already journaled, applied to revenue, adjusted, classified, or in a closed month) cannot be voided here: it needs a separately authorised reversal.
 * Nothing here posts a journal entry or touches another record.
 */
class PaymentVoids
{
    /**
     * Why this payment may not be voided, or an empty list when it may. Checked when a void is proposed and again when it is approved.
     *
     * @return list<string>
     */
    public static function blockers(Payment $payment): array
    {
        $blockers = [];
        if ($payment->isVoided()) {
            $blockers[] = 'This payment is already voided.';
        }
        // 'payment', 'payment_v<n>' (postings) and 'payment_rev<n>' (their reversals) all begin with "payment"; no other source type does.
        $journaled = (int) $payment->posting_version > 0
            || JournalEntry::where('source_id', $payment->id)->where('source_type', 'like', 'payment%')->exists();
        if ($journaled) {
            $blockers[] = 'It has ledger postings, which are protected. It needs a separately authorised reversal, not a void.';
        }
        if (RevenueAdjustment::where('payment_id', $payment->id)->exists()) {
            $blockers[] = 'It has advance, recognition or refund records.';
        }
        if (ReceiptClassification::where('payment_id', $payment->id)->exists() || ReceiptReview::where('payment_id', $payment->id)->exists()) {
            $blockers[] = 'It has a receipt classification or review (including client settlement fees).';
        }
        if (AccountingPeriod::where('month', $payment->paid_on->format('Y-m'))->exists()) {
            $blockers[] = 'Its month ('.$payment->paid_on->format('Y-m').') is closed in the finance ledger.';
        }

        return $blockers;
    }

    /**
     * Finance proposes a void. Nothing changes until a different Board member approves.
     */
    public static function propose(Payment $payment, User $user, string $reason, string $evidence): PaymentVoidRequest
    {
        if (! $user->hasPermission('payment.void.propose')) {
            throw new RuntimeException('You may not propose a payment void.');
        }
        $reason = trim($reason);
        $evidence = trim($evidence);
        if ($reason === '' || $evidence === '') {
            throw new RuntimeException('A reason and the supporting evidence (statement reference) are both required.');
        }

        try {
            return DB::transaction(function () use ($payment, $user, $reason, $evidence): PaymentVoidRequest {
                $payment = Payment::withVoided()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($blockers = self::blockers($payment)) {
                    throw new RuntimeException('This payment cannot be voided: '.implode(' ', $blockers));
                }
                if (PaymentVoidRequest::where('payment_id', $payment->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                    throw new RuntimeException('A void is already pending for this payment.');
                }
                $request = PaymentVoidRequest::create(['payment_id' => $payment->id, 'amount' => $payment->amount, 'reason' => $reason, 'evidence' => $evidence,
                    'requested_by' => $user->id, 'active_payment_id' => $payment->id]);
                Audit::log('payment.void_proposed', 'payment '.$payment->id, 'amount '.$payment->amount.' evidence '.$evidence);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('A void is already pending for this payment.');
        }
    }

    /**
     * A different Board member approves or rejects. Approval re-checks every guard under lock, voids the payment once, and recalculates the invoice
     * and the billing segments it was allocated to.
     */
    public static function decide(PaymentVoidRequest $request, User $user, bool $approve, ?string $note): PaymentVoidRequest
    {
        if (! $user->hasPermission('payment.void.approve')) {
            throw new RuntimeException('Only a Board member may decide a payment void.');
        }
        $note = trim((string) $note);
        if (! $approve && $note === '') {
            throw new RuntimeException('Say why the void is rejected.');
        }

        return DB::transaction(function () use ($request, $user, $approve, $note): PaymentVoidRequest {
            $request = PaymentVoidRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw new RuntimeException('This void request has already been decided.');
            }
            if ($request->requested_by === $user->id) {
                throw new RuntimeException('You cannot decide a void you proposed.');
            }
            $payment = Payment::withVoided()->whereKey($request->payment_id)->lockForUpdate()->firstOrFail();

            if (! $approve) {
                $request->update(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note ?: null, 'active_payment_id' => null]);
                Audit::log('payment.void_rejected', 'payment '.$payment->id, $note);

                return $request;
            }

            if ($blockers = self::blockers($payment)) {
                throw new RuntimeException('This payment can no longer be voided: '.implode(' ', $blockers));
            }
            if ((int) $payment->amount !== (int) $request->amount) {
                throw new RuntimeException('The payment changed after the void was proposed. Reject this request and propose it again.');
            }

            $invoice = $payment->invoice_id ? Invoice::whereKey($payment->invoice_id)->lockForUpdate()->first() : null;
            $segmentIds = $payment->allocations()->pluck('segment_id')->all();

            // Changes nothing but voided_at (not even updated_at) and fires no model events. The original row and its allocations remain.
            DB::table('payments')->where('id', $payment->id)->whereNull('voided_at')->update(['voided_at' => now()]);

            $invoice?->refreshPaymentStatus();
            Invoice::refreshBillingSegments($segmentIds, $invoice?->id);

            $request->update(['status' => 'approved', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note ?: null]);
            Audit::log('payment.void_approved', 'payment '.$payment->id, 'amount '.$payment->amount.' paid '.$payment->paid_on->toDateString().' evidence '.$request->evidence);

            return $request;
        });
    }
}
