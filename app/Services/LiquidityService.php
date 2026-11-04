<?php

namespace App\Services;

use App\Models\CapitalEntry;
use App\Models\ExitSettlement;
use App\Models\Expense;
use App\Models\Payout;

/**
 * What the business can actually spend, built so that no obligation is deducted twice. Every ledger account belongs to exactly one of
 * these groups, and a commitment that is not in the ledger yet is counted only while it is not in the ledger:
 *
 *   ledger cash                  all money in cash and bank accounts (the ledger's `cash`)
 * - recognised liabilities       owed to others and in the ledger: liabilities (suppliers, unpaid bills), customer advances, client funds, loans payable
 * - partner entitlements         profit already allocated to partners and not yet paid out (`partner.payable`); a liability to the owners
 * - commitments not in ledger    sourcing owed or paid-out-unverified that the ledger does not carry yet; approved exit settlements beyond the
 *                                entitlements above; payments and capital withdrawals awaiting verification
 * - reserved funds               internal allocations of profit (Salary, Office & Growth, Emergency, coverage held): earmarked, never a liability
 * = available operational cash   what is left to spend; negative means the business has promised more than it holds
 *
 * Approved payouts are NOT a further deduction: a payout is a claim on partner entitlements, which are already deducted. Reserved funds are
 * NOT liabilities: they are a decision about how profit is used, and are shown apart so the two are never confused.
 *
 * Switched on by finance.liquidity_model (off by default); the calculation itself is read-only and posts nothing.
 */
class LiquidityService
{
    /** Ledger accounts that are obligations to third parties. */
    public const LIABILITY_ACCOUNTS = ['liabilities', 'customer.advances', 'client.funds', 'loans.payable'];

    /** Ledger accounts that are internal reserves. */
    public const RESERVE_ACCOUNTS = ['fund.salary', 'fund.office', 'fund.emergency', 'fund.coverage'];

    public static function enabled(): bool
    {
        return (bool) config('finance.liquidity_model');
    }

    /**
     * @return array{ledger_cash: int, recognised_liabilities: array<string, int>, recognised_liabilities_total: int, partner_entitlements: int, approved_payouts_unpaid: int, commitments: array<string, int>, commitments_total: int, reserved_funds: array<string, int>, reserved_funds_total: int, available_operational_cash: int, shortfall: bool, cash_by_account: list<array<string, mixed>>}
     */
    public static function report(): array
    {
        $cash = Ledger::debitBalance('cash');

        $liabilities = [];
        foreach (self::LIABILITY_ACCOUNTS as $account) {
            $liabilities[$account] = Ledger::creditBalance($account);
        }
        $entitlements = Ledger::creditBalance('partner.payable');

        // Sourcing owed that the ledger does not carry (its payables the ledger carries are in `liabilities` above, not here).
        $sourcing = SourcingObligations::notInLedger()['total'];
        // Exit settlements approved and unpaid: the part of the settlement beyond the entitlements (payable and salary) that are deducted elsewhere.
        $exits = (int) ExitSettlement::where('status', 'approved')->where('payment_status', 'unpaid')->get()
            ->sum(fn (ExitSettlement $e) => max(0, (int) $e->net_settlement - (int) $e->unpaid_entitlements));
        // Money that has left (or is promised to) and that the Board has not verified, so the ledger cash does not show it yet.
        $pendingExpenses = (int) Expense::where('status', 'pending')->whereNull('reverses_id')->where('payment_status', '!=', 'unpaid')->where('category', 'not like', 'Sourcing:%')->sum('amount');
        $pendingWithdrawals = (int) CapitalEntry::where('status', 'pending')->where('kind', 'withdrawal')->sum('amount');
        $commitments = ['sourcing_not_in_ledger' => $sourcing, 'approved_exit_settlements' => $exits, 'payments_awaiting_verification' => $pendingExpenses, 'capital_withdrawals_awaiting_verification' => $pendingWithdrawals];

        $reserves = [];
        foreach (self::RESERVE_ACCOUNTS as $account) {
            $reserves[$account] = Ledger::creditBalance($account);
        }

        $liabilitiesTotal = array_sum($liabilities);
        $commitmentsTotal = array_sum($commitments);
        $reservesTotal = array_sum($reserves);
        $available = $cash - $liabilitiesTotal - $entitlements - $commitmentsTotal - $reservesTotal;

        return [
            'ledger_cash' => $cash,
            'recognised_liabilities' => $liabilities, 'recognised_liabilities_total' => $liabilitiesTotal,
            'partner_entitlements' => $entitlements,
            'approved_payouts_unpaid' => (int) Payout::where('status', 'approved')->sum('amount'), // informational: already inside partner_entitlements
            'commitments' => $commitments, 'commitments_total' => $commitmentsTotal,
            'reserved_funds' => $reserves, 'reserved_funds_total' => $reservesTotal,
            'available_operational_cash' => $available, 'shortfall' => $available < 0,
            'cash_by_account' => AccountingService::balances()->map(fn ($r) => ['name' => $r['name'], 'balance' => $r['balance']])->all(),
        ];
    }
}
