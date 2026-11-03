<?php

namespace App\Services;

use App\Models\AccountStatement;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\StatementReconcilingItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A ledger balance is a CALCULATION; only a dated, referenced statement balance verified by a second person makes an account's
 * cash "verified". Whatever separates the two must be explained line by line (a payment not yet cleared, a receipt in transit,
 * a bank charge not yet booked). Anything left over, even 0.01, is an unexplained difference: it is reported, it blocks period
 * close and profit allocation (IntegrityGate), and it is never hidden by a balancing entry.
 *
 *   unexplained = ledger balance − statement balance − Σ reconciling items       (items are signed: statement → ledger)
 */
class StatementReconciliationService
{
    /** Cash-line balance of one account on the books as of a date (entries dated on or before it). */
    public static function ledgerBalanceAsOf(int $accountId, string $date): int
    {
        $row = JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account', 'cash')->where('journal_lines.financial_account_id', $accountId)
            ->whereDate('journal_entries.entry_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) d, COALESCE(SUM(journal_lines.credit),0) c')->first();

        return (int) $row->d - (int) $row->c;
    }

    /**
     * @param  array{statement_date: string, statement_balance: int, reference: string, notes?: ?string, items?: list<array{kind: string, amount: int, description: string}>}  $data
     */
    public static function record(FinancialAccount $account, array $data, User $user): AccountStatement
    {
        if (trim($data['reference']) === '') {
            throw new RuntimeException('Enter the statement reference: a statement balance without a source is not evidence.');
        }
        if ($data['statement_date'] > today()->toDateString()) {
            throw new RuntimeException('A statement cannot be dated in the future.');
        }
        $items = array_values(array_filter($data['items'] ?? [], fn ($i) => (int) $i['amount'] !== 0));
        foreach ($items as $item) {
            if (! array_key_exists($item['kind'], StatementReconcilingItem::KINDS) || trim((string) $item['description']) === '') {
                throw new RuntimeException('Every reconciling item needs a type and a description.');
            }
        }

        return DB::transaction(function () use ($account, $data, $user, $items) {
            $account = FinancialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (AccountStatement::where('financial_account_id', $account->id)->whereDate('statement_date', $data['statement_date'])->whereIn('status', ['pending', 'verified'])->exists()) {
                throw new RuntimeException('A statement for this account and date is already recorded.');
            }
            $statement = AccountStatement::create([
                'financial_account_id' => $account->id, 'statement_date' => $data['statement_date'], 'statement_balance' => $data['statement_balance'],
                'reference' => trim($data['reference']), 'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
            ]);
            foreach ($items as $item) {
                $statement->items()->create(['kind' => $item['kind'], 'amount' => (int) $item['amount'], 'description' => trim($item['description'])]);
            }
            Audit::log('statement.recorded', 'account '.$account->id, $data['statement_date'].' '.$data['statement_balance']);

            return $statement;
        });
    }

    /** A second person confirms the figure really is on the statement. Verification does not claim it reconciles; position() says that. */
    public static function decide(AccountStatement $statement, bool $approve, User $user): void
    {
        DB::transaction(function () use ($statement, $approve, $user) {
            $statement = AccountStatement::whereKey($statement->id)->lockForUpdate()->firstOrFail();
            if ($statement->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($statement->created_by === $user->id) {
                throw new RuntimeException('You cannot verify a statement you recorded.');
            }
            $statement->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now()]);
            Audit::log('statement.'.($approve ? 'verified' : 'rejected'), 'account '.$statement->financial_account_id, $statement->statement_date->toDateString());
        });
    }

    /**
     * @return array{ledger: int, statement: int, items_total: int, unexplained: int, state: string}
     */
    public static function position(AccountStatement $statement): array
    {
        $ledger = self::ledgerBalanceAsOf($statement->financial_account_id, $statement->statement_date->toDateString());
        $items = (int) $statement->items()->sum('amount');
        $unexplained = $ledger - (int) $statement->statement_balance - $items;

        return ['ledger' => $ledger, 'statement' => (int) $statement->statement_balance, 'items_total' => $items, 'unexplained' => $unexplained,
            'state' => $unexplained === 0 ? 'reconciled' : 'unexplained difference'];
    }

    /** The newest verified statement of each account (an older one is superseded by it). */
    public static function latestVerified(): Collection
    {
        return AccountStatement::with('account')->where('status', 'verified')->orderByDesc('statement_date')->orderByDesc('id')->get()
            ->unique('financial_account_id')->values();
    }

    /**
     * Accounts whose newest verified statement does not reconcile to the books.
     *
     * @return list<array{account: string, date: string, unexplained: int}>
     */
    public static function unexplained(): array
    {
        return self::latestVerified()->map(fn (AccountStatement $s) => [$s, self::position($s)])
            ->filter(fn ($pair) => $pair[1]['unexplained'] !== 0)
            ->map(fn ($pair) => ['account' => $pair[0]->account->name, 'date' => $pair[0]->statement_date->toDateString(), 'unexplained' => $pair[1]['unexplained']])
            ->values()->all();
    }

    public static function describe(int $unexplained): string
    {
        return Money::format(abs($unexplained)).($unexplained > 0 ? ' more in the books than the statement explains' : ' less in the books than the statement explains');
    }
}
