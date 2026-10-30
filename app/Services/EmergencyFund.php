<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\EmergencyFundRequest;
use App\Models\Resolution;
use App\Models\SourcingLot;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emergency Reserve coverage for a loss-making lot, on the EXISTING reserve (ledger account fund.emergency).
 *
 * Coverage finances a shortfall; it is not revenue and does not change the lot's loss. Utilisation is an internal
 * reserve movement (Dr fund.emergency / Cr fund.coverage) — it touches neither the income nor the expense account,
 * because the underlying cost was already recorded once as an Expense when paid.
 * Nothing is reserved or deducted before the Board approves AND Finance utilises; the balance never goes negative.
 */
class EmergencyFund
{
    public static function balance(): int
    {
        return Ledger::creditBalance('fund.emergency');
    }

    public static function request(SourcingLot $lot, int $amount, string $reason, User $user): EmergencyFundRequest
    {
        return DB::transaction(function () use ($lot, $amount, $reason, $user) {
            $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lot->status, ['approved', 'in_progress', 'completed'], true)) {
                // Before pricing is approved a lot has no agreed sales value, so its "loss" is just its unsold cost — not a real loss.
                throw new RuntimeException('Emergency coverage can only be requested for a lot with Board-approved pricing that is not yet settled or cancelled.');
            }
            $f = LotFinancials::for($lot);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            if ($amount > $f['fund_suggested']) {
                throw new RuntimeException('Requestable amount is '.Money::format($f['fund_suggested']).': the uncovered loss ('.Money::format($f['uncovered_loss']).') minus requests already open or utilised ('.Money::format($f['fund_live']).').');
            }
            $policy = self::autoPolicy($lot, $amount);
            $request = EmergencyFundRequest::create([
                'sourcing_lot_id' => $lot->id, 'amount' => $amount, 'loss_at_request' => $f['uncovered_loss'], 'reason' => $reason, 'requested_by' => $user->id,
                ...($policy ? ['status' => 'approved', 'decided_at' => now(), 'decision_note' => 'Approved automatically under the Board\'s standing policy', 'auto_policy_id' => $policy->id] : []),
            ]);
            Audit::log('sourcing.fund.requested', 'lot '.$lot->number, (string) $amount.($policy ? ' — approved under standing policy #'.$policy->id : ''));
            if ($policy) {
                self::utilise($request, null);
            }

            return $request->refresh();
        });
    }

    /**
     * The standing policy that lets coverage be approved without a person: the lot's pricing is Board-approved, the
     * amount is within the Board's per-request limit, and the reserve can cover it after other approved requests.
     */
    private static function autoPolicy(SourcingLot $lot, int $amount): ?Resolution
    {
        $policy = StandingPolicies::active(StandingPolicies::EMERGENCY);
        if (! $policy || $policy->amount <= 0 || $amount > $policy->amount || ! in_array($lot->status, ['approved', 'in_progress', 'completed'], true)) {
            return null;
        }
        $earmarked = (int) EmergencyFundRequest::where('status', 'approved')->sum('amount');

        return $amount <= self::balance() - $earmarked ? $policy : null;
    }

    public static function decide(EmergencyFundRequest $request, bool $approve, ?string $note, User $user): void
    {
        DB::transaction(function () use ($request, $approve, $note, $user) {
            $request = EmergencyFundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw new RuntimeException('This request has already been decided.');
            }
            if ($request->requested_by === $user->id) {
                throw new RuntimeException('You cannot decide a request you raised.');
            }
            if ($approve) {
                $earmarked = (int) EmergencyFundRequest::where('status', 'approved')->sum('amount');
                if ($request->amount > self::balance() - $earmarked) {
                    throw new RuntimeException('Insufficient Emergency Fund balance ('.Money::format(max(0, self::balance() - $earmarked)).' available). The shortfall stays an uncovered loss.');
                }
                $f = LotFinancials::for($request->lot);
                if ($f['fund_live'] > $f['uncovered_loss']) {
                    throw new RuntimeException('The lot loss has shrunk below the funding requested; reject this request and file a smaller one if needed.');
                }
            }
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note]);
            Audit::log('sourcing.fund.'.($approve ? 'approved' : 'rejected'), 'lot '.$request->lot->number, (string) $request->amount);
        });
    }

    /** Post the approved coverage to the reserve, once. */
    public static function utilise(EmergencyFundRequest $request, ?User $user): void
    {
        DB::transaction(function () use ($request, $user) {
            // Lock every approved request: concurrent utilisations queue here, so the balance check cannot race.
            EmergencyFundRequest::where('status', 'approved')->lockForUpdate()->get();
            $request = EmergencyFundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'approved') {
                throw new RuntimeException('Only an approved request can be utilised (it may already have been).');
            }
            if ($request->amount > self::balance()) {
                throw new RuntimeException('Insufficient Emergency Fund balance to utilise this request.');
            }
            // The loss may have shrunk since approval (e.g. a client recovery); never cover more than the loss that is left.
            $f = LotFinancials::for($request->lot);
            if ($f['fund_live'] > $f['uncovered_loss']) {
                throw new RuntimeException('The lot loss is now '.Money::format($f['uncovered_loss']).', less than the coverage requested. Cancel this request and file a smaller one.');
            }
            $entry = Ledger::post(now()->toDateString(), 'Emergency Reserve coverage — '.$request->lot->number, 'sourcing_fund', $request->id, [
                ['fund.emergency', null, $request->amount, 0],
                ['fund.coverage', null, 0, $request->amount],
            ], $user?->id);
            $request->update(['status' => 'utilised', 'utilised_by' => $user?->id, 'utilised_at' => now(), 'journal_entry_id' => $entry->id]);
            Audit::log('sourcing.fund.utilised', 'lot '.$request->lot->number, (string) $request->amount);
        });
    }

    /** Reverse a utilised request with an offsetting entry (history is kept). */
    public static function reverse(EmergencyFundRequest $request, string $reason, ?User $user): void
    {
        DB::transaction(function () use ($request, $reason, $user) {
            $request = EmergencyFundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'utilised') {
                throw new RuntimeException('Only a utilised request can be reversed.');
            }
            if (SourcingObligations::heldCoverage() < $request->amount) {
                throw new RuntimeException('This coverage has already been applied to (or claimed by) a partner loss allocation and can no longer be reversed here.');
            }
            Ledger::post(now()->toDateString(), 'Reversal of Emergency Reserve coverage — '.$request->lot->number, 'sourcing_fund_reversal', $request->id, [
                ['fund.coverage', null, $request->amount, 0],
                ['fund.emergency', null, 0, $request->amount],
            ], $user?->id, $request->journal_entry_id);
            $request->update(['status' => 'reversed', 'reversed_by' => $user?->id, 'reversed_at' => now(), 'reverse_reason' => $reason]);
            Audit::log('sourcing.fund.reversed', 'lot '.$request->lot->number, $reason);
        });
    }

    /**
     * Coverage nobody can use: utilised for a sourcing loss, but the month it was utilised in closed profitable, so no
     * partner loss allocation will ever claim it. Under the Board's release policy it returns to the reserve (an
     * offsetting entry; nothing already posted changes). Without the policy it stays held and is listed as an exception.
     *
     * @return list<string> lot numbers released
     */
    public static function releaseStranded(): array
    {
        $policy = StandingPolicies::active(StandingPolicies::RELEASE);
        if (! $policy) {
            return [];
        }
        $released = [];
        foreach (self::strandedCandidates() as $request) {
            try {
                self::reverse($request, 'Automatic release under standing policy #'.$policy->id.': the period closed profitable, so no partner loss can claim this coverage.', null);
                $released[] = $request->lot->number;
            } catch (RuntimeException) {
                // claimed meanwhile by an allocation: leave it alone
            }
        }

        return $released;
    }

    /**
     * Utilised requests whose own month is a verified period with no loss to offset and which are still unclaimed.
     *
     * @return Collection<int, EmergencyFundRequest>
     */
    public static function strandedCandidates(): Collection
    {
        $held = SourcingObligations::heldCoverage();

        return EmergencyFundRequest::with('lot')->where('status', 'utilised')->orderBy('id')->get()->filter(function (EmergencyFundRequest $r) use (&$held) {
            $period = AccountingPeriod::where('month', $r->utilised_at->format('Y-m'))->where('status', 'verified')->first();
            if (! $period || $period->net_profit < 0 || $held < $r->amount) {
                return false;
            }
            $held -= $r->amount;

            return true;
        })->values();
    }

    public static function cancel(EmergencyFundRequest $request, User $user): void
    {
        DB::transaction(function () use ($request, $user) {
            $request = EmergencyFundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! in_array($request->status, ['pending', 'approved'], true)) {
                throw new RuntimeException('Only an open request can be cancelled.');
            }
            if ($request->requested_by !== $user->id && ! $user->can('sourcing.fund.utilize')) {
                throw new RuntimeException('Only the requester or Finance can cancel this request.');
            }
            $request->update(['status' => 'cancelled']);
            Audit::log('sourcing.fund.cancelled', 'lot '.$request->lot->number);
        });
    }
}
