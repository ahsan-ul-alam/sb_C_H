<?php

namespace App\Services;

use App\Models\LoanReceivableRepayment;
use App\Models\LoanRecoveryAllocation;
use App\Models\Partner;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Optionally associates part of a VERIFIED loan repayment with an investor, for tracking only.
 *
 * The repayment already posted its one and only entry (Dr cash / Cr assets.loans_receivable). An allocation posts NOTHING: it is not a second receipt, not paid-in
 * capital, not income, and no figure used to rebuild company cash reads it. It is kept apart from capital contributions everywhere it is shown.
 *
 * Controls: only a verified repayment can be allocated; pending and verified allocations together never exceed the repayment (the repayment row is locked, so concurrent
 * requests are serialised); Finance proposes, a different Board user verifies; a verified allocation is reversed, never deleted; a repayment with a live allocation cannot be
 * reversed until the allocation is.
 */
class LoanRecoveryAllocationService
{
    /** Allocations that use up part of the repayment (rejected and reversed ones free it). */
    public const LIVE = ['pending', 'verified'];

    public static function allocated(LoanReceivableRepayment $repayment): int
    {
        return (int) LoanRecoveryAllocation::where('loan_receivable_repayment_id', $repayment->id)->whereIn('status', self::LIVE)->sum('amount');
    }

    public static function available(LoanReceivableRepayment $repayment): int
    {
        return $repayment->status === 'verified' ? max(0, (int) $repayment->amount - self::allocated($repayment)) : 0;
    }

    /** Propose that $amount of a verified repayment be tracked against an investor. A repeated submission with the same key returns the first. */
    public static function propose(LoanReceivableRepayment $repayment, Partner $partner, int $amount, string $reason, ?string $reference, ?int $documentId, User $user, ?string $key = null): LoanRecoveryAllocation
    {
        if ($key && ($existing = LoanRecoveryAllocation::where('idempotency_key', $key)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($repayment, $partner, $amount, $reason, $reference, $documentId, $user, $key) {
            $repayment = LoanReceivableRepayment::whereKey($repayment->id)->lockForUpdate()->firstOrFail();
            if ($repayment->status !== 'verified') {
                throw new RuntimeException('Only a verified loan repayment can be allocated to an investor (this one is '.$repayment->status.').');
            }
            if ($amount <= 0 || trim($reason) === '') {
                throw new RuntimeException('Enter an amount above zero and the reason for the allocation.');
            }
            $free = self::available($repayment);
            if ($amount > $free) {
                throw new RuntimeException('Only '.Money::format($free).' of this repayment is still free to allocate ('.Money::format((int) $repayment->amount).' repaid, '.Money::format((int) $repayment->amount - $free).' already allocated or awaiting verification).');
            }
            $allocation = LoanRecoveryAllocation::create(['loan_receivable_repayment_id' => $repayment->id, 'partner_id' => $partner->id, 'amount' => $amount, 'reason' => trim($reason),
                'reference' => $reference ? trim($reference) : null, 'document_id' => $documentId, 'idempotency_key' => $key, 'created_by' => $user->id]);
            Audit::log('loanrecovery.proposed', 'repayment '.$repayment->id, $amount.' to partner '.$partner->id.' (tracking only)');

            return $allocation;
        });
    }

    public static function decide(LoanRecoveryAllocation $allocation, bool $approve, User $user, ?string $note = null): void
    {
        if (! $approve && trim((string) $note) === '') {
            throw new RuntimeException('Give a reason for rejecting.');
        }
        DB::transaction(function () use ($allocation, $approve, $user, $note) {
            $repayment = LoanReceivableRepayment::whereKey($allocation->loan_receivable_repayment_id)->lockForUpdate()->firstOrFail();
            $allocation = LoanRecoveryAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($allocation->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($allocation->created_by === $user->id) {
                throw new RuntimeException('You cannot verify an allocation you proposed.');
            }
            if ($approve && $repayment->status !== 'verified') {
                throw new RuntimeException('The repayment is no longer verified, so it cannot be allocated.');
            }
            $allocation->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now(), 'decision_note' => trim((string) $note) ?: null]);
            Audit::log('loanrecovery.'.($approve ? 'verified' : 'rejected'), 'allocation '.$allocation->id, 'tracking only; nothing posted');
        });
    }

    /** Undo a verified allocation. The record stays, marked reversed with who and why; the amount becomes free again. */
    public static function reverse(LoanRecoveryAllocation $allocation, string $reason, User $user): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the reversal.');
        }
        DB::transaction(function () use ($allocation, $reason, $user) {
            LoanReceivableRepayment::whereKey($allocation->loan_receivable_repayment_id)->lockForUpdate()->firstOrFail();
            $allocation = LoanRecoveryAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($allocation->status !== 'verified') {
                throw new RuntimeException('Only a verified allocation can be reversed.');
            }
            if ($allocation->created_by === $user->id) {
                throw new RuntimeException('You cannot reverse an allocation you proposed.');
            }
            $allocation->update(['status' => 'reversed', 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => trim($reason)]);
            Audit::log('loanrecovery.reversed', 'allocation '.$allocation->id, $reason);
        });
    }

    /**
     * Turning a tracking allocation into paid-in capital is refused. A company's own recovered loan principal is not money an investor put in, there is no external funding
     * source or equity counterpart for it, and the ledger has no equity-transfer posting; booking one would be an artificial balancing entry. Fresh investor money goes through
     * the normal investment-receipt workflow (Finance proposes, the Board verifies), separate from loan recovery.
     */
    public static function refuseCapitalConversion(LoanRecoveryAllocation $allocation): never
    {
        throw new RuntimeException('A recovered loan is the company\'s own money coming back, not investor funding, so it cannot be recorded as paid-in capital. If the investor really put new money in, record it as an investment on the Investment ledger with its bank evidence; it is then verified by the Board separately.');
    }

    /** Verified tracking allocations per investor (never part of any capital figure). */
    public static function verifiedByPartner(): Collection
    {
        return LoanRecoveryAllocation::where('status', 'verified')->selectRaw('partner_id, SUM(amount) a')->groupBy('partner_id')->pluck('a', 'partner_id')->map(fn ($v) => (int) $v);
    }
}
