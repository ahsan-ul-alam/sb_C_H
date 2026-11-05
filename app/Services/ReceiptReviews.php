<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\ReceiptReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Proposed classification of a historical customer receipt, in four separate steps by different people:
 *   propose (with the owner's confirmation that the money was received) -> review -> approve -> apply.
 * Applying writes only the existing ReceiptClassification; it posts nothing to the ledger, never edits the payment (date, reference, invoice stay as they were), and never
 * marks the account or the revenue recognition as verified. Income is recognised only by the normal rules (period close or real-time posting), so a receipt can never be
 * counted twice. A receipt classified as product sourcing then waits, as before, for its sourcing record and supplier cost.
 */
class ReceiptReviews
{
    public const NATURES = ['service' => 'Service fee', 'product_sourcing' => 'Product sourcing'];

    /** A suggestion from the invoice or segment the receipt was recorded against; it is only ever a proposal for a person to confirm. */
    public static function suggest(Payment $payment): array
    {
        $payment->loadMissing('invoice.items');
        $text = collect($payment->invoice?->items ?? [])->pluck('description')->push($payment->invoice?->notes)->filter()->implode(' ');
        if ($text === '' && ($segmentId = DB::table('payment_allocations')->where('payment_id', $payment->id)->value('segment_id'))) {
            $text = (string) DB::table('segments')->where('id', $segmentId)->value('name');
        }

        return match (true) {
            (bool) preg_match('/product|sourcing|purchas|hoodie|sweater|shirt|stock/i', $text) => ['product_sourcing', 'Invoice/segment says: '.$text],
            (bool) preg_match('/onboard|marketing|service|fee|subscription/i', $text) => ['service', 'Invoice/segment says: '.$text],
            default => [null, $text === '' ? 'No invoice or segment text to suggest from' : 'Unclear: '.$text],
        };
    }

    public static function propose(Payment $payment, string $nature, string $ownerConfirmedOn, string $ownerReference, User $user): ReceiptReview
    {
        if (! array_key_exists($nature, self::NATURES)) {
            throw new RuntimeException('Choose service fee or product sourcing.');
        }
        if (trim($ownerReference) === '') {
            throw new RuntimeException('Record how the owner confirmed the money was received (who, when, how).');
        }
        if ($ownerConfirmedOn > today()->toDateString()) {
            throw new RuntimeException('The owner\'s confirmation cannot be dated in the future.');
        }
        if (ReceiptClassification::where('payment_id', $payment->id)->exists() || ReceiptReview::where('payment_id', $payment->id)->exists()) {
            throw new RuntimeException('Receipt #'.$payment->id.' is already classified or under review.');
        }
        [, $basis] = self::suggest($payment);
        $review = ReceiptReview::create(['payment_id' => $payment->id, 'proposed_nature' => $nature, 'suggestion_basis' => mb_substr($basis, 0, 250), 'owner_confirmed_on' => $ownerConfirmedOn,
            'owner_confirmation_reference' => trim($ownerReference), 'proposed_by' => $user->id]);
        Audit::log('receipt_review.proposed', 'payment '.$payment->id, $nature);

        return $review;
    }

    public static function review(ReceiptReview $review, User $user): void
    {
        self::locked($review, function (ReceiptReview $r) use ($user) {
            self::expect($r, 'proposed');
            if ($r->proposed_by === $user->id) {
                throw new RuntimeException('Finance review must be done by someone other than the person who proposed it.');
            }
            $r->update(['status' => 'reviewed', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            Audit::log('receipt_review.reviewed', 'payment '.$r->payment_id);
        });
    }

    public static function approve(ReceiptReview $review, User $user): void
    {
        self::locked($review, function (ReceiptReview $r) use ($user) {
            self::expect($r, 'reviewed');
            if (in_array($user->id, [$r->proposed_by, $r->reviewed_by], true)) {
                throw new RuntimeException('The approver must be different from the person who proposed and the person who reviewed it.');
            }
            $r->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
            Audit::log('receipt_review.approved', 'payment '.$r->payment_id);
        });
    }

    public static function reject(ReceiptReview $review, string $note, User $user): void
    {
        self::locked($review, function (ReceiptReview $r) use ($note, $user) {
            if (! in_array($r->status, ['proposed', 'reviewed'], true)) {
                throw new RuntimeException('Only a proposal that is not yet approved can be rejected.');
            }
            if (trim($note) === '') {
                throw new RuntimeException('Give the reason for rejecting it.');
            }
            $r->update(['status' => 'rejected', 'decision_note' => trim($note)]);
            Audit::log('receipt_review.rejected', 'payment '.$r->payment_id, 'by user '.$user->id);
        });
    }

    public static function apply(ReceiptReview $review, User $user): void
    {
        self::locked($review, function (ReceiptReview $r) use ($user) {
            self::expect($r, 'approved');
            if (ReceiptClassification::where('payment_id', $r->payment_id)->exists()) {
                throw new RuntimeException('This receipt was classified in the meantime; nothing was applied.');
            }
            ReceiptClassification::create(['payment_id' => $r->payment_id, 'nature' => $r->proposed_nature, 'notes' => 'Owner-confirmed received; approved review #'.$r->id, 'classified_by' => $user->id]);
            $r->update(['status' => 'applied', 'applied_at' => now()]);
            Audit::log('receipt_review.applied', 'payment '.$r->payment_id, $r->proposed_nature.' (no ledger posting)');
        });
    }

    private static function expect(ReceiptReview $r, string $status): void
    {
        if ($r->status !== $status) {
            throw new RuntimeException('This review is '.$r->status.'; that step is not available.');
        }
    }

    private static function locked(ReceiptReview $review, \Closure $work): void
    {
        DB::transaction(fn () => $work(ReceiptReview::whereKey($review->id)->lockForUpdate()->firstOrFail()));
    }
}
