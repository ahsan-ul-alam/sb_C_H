<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Paying an approved partner payout: Dr partner.payable / Cr cash, from ONE named financial account whose own balance must cover it.
 * Locks are taken in a fixed order (payout, partner, account) so two payouts cannot both pass the same check and overspend an account or
 * a partner's entitlement.
 */
class PayoutService
{
    public static function pay(Payout $payout, User $user, ?int $financialAccountId): void
    {
        DB::transaction(function () use ($payout, $user, $financialAccountId) {
            $payout = Payout::lockForUpdate()->findOrFail($payout->id);
            if ($payout->status !== 'approved') {
                throw new RuntimeException('Only an approved payout can be paid.');
            }
            if ($user->partner_id && $user->partner_id === $payout->partner_id) {
                throw new RuntimeException('You cannot pay out to yourself.');
            }
            $today = today()->toDateString();
            AccountingService::assertOpen($today, 'a payout');
            $accountId = AccountingService::requirePayingAccount($financialAccountId);

            Partner::whereKey($payout->partner_id)->lockForUpdate()->first();
            if (Ledger::creditBalance('partner.payable', $payout->partner_id, lock: true) < $payout->amount) {
                throw new RuntimeException('Partner entitlement is lower than the payout amount.');
            }
            // The chosen account must hold the money itself (not just the company as a whole); this also locks the account row.
            AccountingService::assertCanSpend($accountId, (int) $payout->amount);
            if (Ledger::debitBalance('cash') < $payout->amount) {
                throw new RuntimeException('Not enough cash recorded in the ledger.');
            }
            $entry = Ledger::post($today, 'Partner payout', 'payout', $payout->id, [
                ['partner.payable', $payout->partner_id, $payout->amount, 0],
                ['cash', null, 0, $payout->amount, $accountId],
            ], $user->id);
            $payout->update(['status' => 'paid', 'paid_by' => $user->id, 'paid_at' => now(), 'financial_account_id' => $accountId]);
            Audit::log('payout.paid', 'payout '.$payout->id, 'journal '.$entry->id);
        });
    }
}
