<?php

namespace App\Services;

use App\Models\CashAllocationBatch;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assigns the legacy "unassigned" cash (posted before financial accounts existed) to Office Cash, Bank, Mobile Banking,
 * Petty Cash and so on. It only MOVES money that the ledger already holds, as transfers: it never touches income, expense,
 * capital or any other account, so it cannot create revenue, profit or new capital, and it cannot count a balance twice.
 *
 * Nothing is invented: the plan must account for the entire verified unassigned balance, to the cent. The person preparing
 * it enters the real balance of each account; any difference is shown as an error to investigate, not silently absorbed.
 * A second person approves it; a plan that was made against a balance that has since moved is refused as stale.
 */
class CashAllocationService
{
    /**
     * @param  array<int, int>  $amountsByAccount  financial_account_id => minor units
     */
    public static function prepare(array $amountsByAccount, ?string $note, User $user): CashAllocationBatch
    {
        return DB::transaction(function () use ($amountsByAccount, $note, $user) {
            $amounts = array_filter($amountsByAccount, fn ($a) => $a > 0);
            $unassigned = AccountingService::balance(null);
            if ($unassigned <= 0) {
                throw new RuntimeException('There is no unassigned cash to allocate.');
            }
            if (CashAllocationBatch::where('status', 'pending')->exists()) {
                throw new RuntimeException('An allocation is already awaiting approval. Approve or cancel it first.');
            }
            if ($amounts === []) {
                throw new RuntimeException('Enter the amount that belongs in at least one account.');
            }
            if (FinancialAccount::whereIn('id', array_keys($amounts))->where('is_active', true)->count() !== count($amounts)) {
                throw new RuntimeException('Every account must exist and be active.');
            }
            $total = array_sum($amounts);
            if ($total !== $unassigned) {
                throw new RuntimeException('The allocation must account for the entire verified cash balance of '.Money::format($unassigned).'. You entered '.Money::format($total).' (difference '.Money::format($total - $unassigned).'). Check the real balance of each account; do not adjust to fit.');
            }

            $batch = CashAllocationBatch::create(['note' => $note, 'unassigned_at_prepare' => $unassigned, 'prepared_by' => $user->id]);
            foreach ($amounts as $accountId => $amount) {
                $batch->lines()->create(['financial_account_id' => $accountId, 'amount' => $amount]);
            }
            Audit::log('cash_allocation.prepared', 'batch '.$batch->id, (string) $unassigned);

            return $batch;
        });
    }

    public static function approve(CashAllocationBatch $batch, User $approver): void
    {
        DB::transaction(function () use ($batch, $approver) {
            $batch = CashAllocationBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'pending') {
                throw new RuntimeException('This allocation was already decided.');
            }
            if ($batch->prepared_by === $approver->id) {
                throw new RuntimeException('You cannot approve an allocation you prepared.');
            }
            FinancialAccount::lockForUpdate()->get(['id']);
            $cashBefore = AccountingService::totalCash();
            if (AccountingService::balance(null) !== (int) $batch->unassigned_at_prepare) {
                throw new RuntimeException('The unassigned cash has changed since this plan was prepared. Cancel it and prepare a new one.');
            }

            foreach ($batch->lines as $line) {
                AccountingService::transfer([
                    'from' => null, 'to' => $line->financial_account_id, 'amount' => (int) $line->amount, 'date' => today()->toDateString(),
                    'reference' => 'Opening allocation #'.$batch->id, 'notes' => $batch->note, 'idempotency_key' => 'alloc-'.$batch->id.'-'.$line->id,
                ], $approver);
            }

            // Proof, inside the same transaction: nothing is left unassigned and total cash did not move.
            if (AccountingService::balance(null) !== 0 || AccountingService::totalCash() !== $cashBefore) {
                throw new RuntimeException('The allocation did not reconcile; nothing was posted.');
            }
            $batch->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
            Audit::log('cash_allocation.approved', 'batch '.$batch->id, (string) $batch->unassigned_at_prepare);
        });
    }

    public static function cancel(CashAllocationBatch $batch, User $user): void
    {
        if ($batch->status !== 'pending') {
            throw new RuntimeException('Only a pending allocation can be cancelled.');
        }
        $batch->update(['status' => 'cancelled']);
        Audit::log('cash_allocation.cancelled', 'batch '.$batch->id);
    }
}
