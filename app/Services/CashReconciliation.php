<?php

namespace App\Services;

use App\Models\AccountStatement;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Unreconciled Cash Difference (অশনাক্ত নগদ পার্থক্য / হিসাবের অমিল): what the money really is, less what the ERP expects it to be.
 *
 *   difference = actual cash balance (verified statements at ONE cutoff) − ERP expected cash balance (to the end of that cutoff date)
 *
 * It is an unresolved reconciliation difference, never a transaction: nothing here posts a journal entry or classifies it as an expense, loss, revenue, profit,
 * loan, supplier payment or capital contribution, so it can never reach the P&L.
 *
 * The actual balance is the sum of each active account's balance from its verified account statement. Every active account must have a verified statement at the
 * SAME cutoff date (statements of different dates are never mixed); otherwise the result is "not verified", never a zero. Because each account's balance is counted
 * once and expected cash is the total of every cash line, a transfer between Cash, Bank and bKash cannot be counted twice. A statement that rests only on the
 * owner's confirmation is labelled as such and is never presented as independently verified.
 */
class CashReconciliation
{
    public const BANGLA = 'অশনাক্ত নগদ পার্থক্য / হিসাবের অমিল';

    /** Customer money recorded as received (active payments only) but not yet in the ledger, to the end of a date (or to date when null). */
    public static function collectionsNotJournaled(string $currency, ?string $asOf = null): int
    {
        $ids = Payment::whereHas('project', fn ($q) => $q->where('currency', $currency))->when($asOf, fn ($q) => $q->whereDate('paid_on', '<=', $asOf))->pluck('id');
        $posted = DB::table('journal_entries')->where(fn ($q) => $q->where('source_type', 'payment')->orWhere('source_type', 'like', 'payment_v%'))->whereIn('source_id', $ids)->pluck('source_id');

        return (int) Payment::whereIn('id', $ids)->whereNotIn('id', $posted)->sum('amount');
    }

    /** Total expected cash: every cash line in the ledger (all accounts and unassigned) plus collections not yet journaled, to the end of a date. */
    public static function expectedCash(string $currency, ?string $asOf = null): int
    {
        $row = JournalLine::query()->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', 'cash')
            ->when($asOf, fn ($q) => $q->whereDate('e.entry_date', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) d, COALESCE(SUM(journal_lines.credit),0) c')->first();

        return (int) $row->d - (int) $row->c + self::collectionsNotJournaled($currency, $asOf);
    }

    /**
     * The owner-authorized opening cash baseline, if one was recorded (a permanent audit record, never overwritten): the reconstructed historical expected cash, the owner-confirmed
     * actual cash at an explicit cutoff, and the one-time Legacy Opening Balance Adjustment that bridges them. Read from the database, never hardcoded.
     *
     * @return array<string, mixed>|null
     */
    public static function baseline(): ?array
    {
        $row = DB::table('audit_logs')->where('action', 'cash.baseline_set')->orderByDesc('id')->first();
        $data = $row ? json_decode((string) $row->details, true) : null;

        return is_array($data) && isset($data['cutoff_date'], $data['owner_cash'], $data['reconstructed_expected'], $data['adjustment']) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $snapshot = self::statementSnapshot();
        $baseline = self::baseline();
        $snapshot['baseline'] = $baseline;
        // With no complete set of verified statements (or only older ones) the owner-authorized baseline is the actual balance, labelled as owner-confirmed.
        if ($baseline !== null && ($snapshot['cutoff'] === null || $baseline['cutoff_date'] > $snapshot['cutoff'])) {
            $expected = self::expectedCash((string) config('finance.currency'), $baseline['cutoff_date']);
            $difference = (int) $baseline['owner_cash'] - $expected;

            return array_merge($snapshot, [
                'status' => $difference === 0 ? 'reconciled' : 'unreconciled', 'reason' => null, 'cutoff' => $baseline['cutoff_date'], 'cutoff_at' => Carbon::parse($baseline['cutoff_at'] ?? $baseline['cutoff_date']),
                'expected' => $expected, 'expected_as_of' => 'end of '.$baseline['cutoff_date'], 'actual' => (int) $baseline['owner_cash'], 'difference' => $difference,
                'sign' => $difference > 0 ? '+' : ($difference < 0 ? '−' : ''),
                'direction' => $difference > 0 ? 'Actual money is greater than the ERP expects' : ($difference < 0 ? 'Actual money is less than the ERP expects' : 'Balances match'),
                'evidence' => 'owner_confirmed', 'statements' => [], 'missing' => [], 'source' => 'owner_baseline',
            ]);
        }

        return $snapshot + ['source' => 'statements'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function statementSnapshot(): array
    {
        $currency = (string) config('finance.currency');
        $accounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();
        $verified = AccountStatement::with(['account', 'verifier'])->where('status', 'verified')->whereIn('financial_account_id', $accounts->pluck('id'))->get();

        $cutoff = null;
        $missing = [];
        if ($accounts->isEmpty()) {
            $reason = 'No cash, bank or bKash account is set up, so there is no actual balance to compare.';
        } elseif ($verified->isEmpty()) {
            $reason = 'No verified statement or owner confirmation has been recorded for any account.';
        } else {
            // The newest date at which EVERY active account has a verified statement: balances of different dates are never added together.
            foreach ($verified->pluck('statement_date')->map(fn ($d) => $d->toDateString())->unique()->sortDesc() as $date) {
                $have = $verified->filter(fn ($s) => $s->statement_date->toDateString() === $date)->pluck('financial_account_id');
                if ($accounts->pluck('id')->diff($have)->isEmpty()) {
                    $cutoff = $date;

                    break;
                }
            }
            if ($cutoff === null) {
                $newest = $verified->max(fn ($s) => $s->statement_date->toDateString());
                $have = $verified->filter(fn ($s) => $s->statement_date->toDateString() === $newest)->pluck('financial_account_id');
                $missing = $accounts->whereNotIn('id', $have)->pluck('name')->all();
                $reason = 'No single date has a verified statement for every account (missing at '.$newest.': '.implode(', ', $missing).'). Statements of different dates are not mixed.';
            }
        }

        if ($cutoff === null) {
            return ['status' => 'not_verified', 'reason' => $reason, 'cutoff' => null, 'cutoff_at' => null, 'expected' => self::expectedCash($currency), 'expected_as_of' => 'to date',
                'actual' => null, 'difference' => null, 'sign' => '', 'direction' => 'Not verified', 'evidence' => null, 'statements' => [], 'missing' => $missing, 'bangla' => self::BANGLA];
        }

        $rows = $verified->filter(fn ($s) => $s->statement_date->toDateString() === $cutoff)->sortBy(fn ($s) => $s->account->name)->values();
        $actual = (int) $rows->sum('statement_balance');
        $expected = self::expectedCash($currency, $cutoff);
        $difference = $actual - $expected;
        $evidence = $rows->contains(fn ($s) => $s->evidence === 'owner_confirmed') ? 'owner_confirmed' : 'statement_verified';

        return [
            'status' => $difference === 0 ? 'reconciled' : 'unreconciled', 'reason' => null, 'cutoff' => $cutoff,
            'cutoff_at' => $rows->map(fn ($s) => $s->cutoff_at)->filter()->max(), 'expected' => $expected, 'expected_as_of' => 'end of '.$cutoff, 'actual' => $actual, 'difference' => $difference,
            'sign' => $difference > 0 ? '+' : ($difference < 0 ? '−' : ''),
            'direction' => $difference > 0 ? 'Actual money is greater than the ERP expects' : ($difference < 0 ? 'Actual money is less than the ERP expects' : 'Balances match'),
            'evidence' => $evidence, 'missing' => [], 'bangla' => self::BANGLA,
            'statements' => $rows->map(fn ($s) => ['account' => $s->account->name, 'balance' => (int) $s->statement_balance, 'reference' => $s->reference, 'evidence' => $s->evidence,
                'cutoff_at' => $s->cutoff_at?->toDateTimeString(), 'verified_by' => $s->verifier?->name, 'verified_at' => $s->verified_at?->toDateTimeString()])->all(),
        ];
    }
}
