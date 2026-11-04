<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AccountTransfer;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Financial accounts (cash, bank, mobile, petty): balances, the no-overdraft rule and transfers between them.
 * An account balance is Σ(debit − credit) of the journal's cash lines that carry the account, so it can never disagree
 * with the ledger. Cash posted before accounts existed has no account and is shown as "Unassigned (legacy)".
 */
class AccountingService
{
    public static function defaultAccountId(): ?int
    {
        return FinancialAccount::where('is_default', true)->where('is_active', true)->value('id');
    }

    public static function resolveAccountId(?int $accountId): ?int
    {
        return $accountId ?: self::defaultAccountId();
    }

    /**
     * A NEW transaction may only move money in or out of an active account. (null means the default account, or the legacy unassigned cash
     * when no accounts exist, and is always accepted.) Posted history is never judged by this: reversals of old entries keep their accounts.
     */
    public static function assertActive(?int $accountId): void
    {
        if ($accountId === null) {
            return;
        }
        $account = FinancialAccount::find($accountId);
        if (! $account || ! $account->is_active) {
            throw new RuntimeException('The financial account "'.($account?->name ?? '#'.$accountId).'" is inactive; choose an active account.');
        }
    }

    /** resolveAccountId() for a new transaction: the account must be active. */
    public static function activeAccountId(?int $accountId): ?int
    {
        self::assertActive($accountId);

        return self::resolveAccountId($accountId);
    }

    /**
     * Balance of one account, or of the unassigned legacy cash when null. With $lock the read is a locking read, which always
     * sees the latest committed data (a plain read inside a MySQL transaction can see an older snapshot).
     */
    public static function balance(?int $accountId, bool $lock = false): int
    {
        $row = JournalLine::query()->where('account', 'cash')
            ->when($accountId, fn ($q) => $q->where('financial_account_id', $accountId), fn ($q) => $q->whereNull('financial_account_id'))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return (int) $row->d - (int) $row->c;
    }

    /**
     * Every account with its inflows, outflows and balance, plus the unassigned legacy cash. One grouped query.
     *
     * @return Collection<int, array{account: ?FinancialAccount, name: string, type: string, inflow: int, outflow: int, balance: int}>
     */
    public static function balances(): Collection
    {
        $sums = JournalLine::query()->where('account', 'cash')->selectRaw('financial_account_id id, SUM(debit) d, SUM(credit) c')
            ->groupBy('financial_account_id')->get()->keyBy(fn ($r) => $r->id ?? 0);

        $row = fn (?FinancialAccount $a, string $name, string $type) => [
            'account' => $a, 'name' => $name, 'type' => $type,
            'inflow' => (int) ($sums[$a?->id ?? 0]->d ?? 0), 'outflow' => (int) ($sums[$a?->id ?? 0]->c ?? 0),
            'balance' => (int) ($sums[$a?->id ?? 0]->d ?? 0) - (int) ($sums[$a?->id ?? 0]->c ?? 0),
        ];

        $rows = FinancialAccount::orderByDesc('is_default')->orderBy('name')->get()->map(fn ($a) => $row($a, $a->name, $a->typeLabel()));
        $legacy = $row(null, 'Unassigned (legacy cash)', 'Posted before accounts existed');
        if ($legacy['inflow'] !== 0 || $legacy['outflow'] !== 0) {
            $rows->push($legacy);
        }

        return $rows->values();
    }

    /** Total company cash. Transfers never change it. */
    public static function totalCash(): int
    {
        return Ledger::debitBalance('cash');
    }

    /**
     * The no-overdraft rule for money leaving an account. Accounts flagged allow_negative are exempt, and money leaving
     * "unassigned" legacy cash is not checked (it predates accounts).
     */
    public static function assertCanSpend(?int $accountId, int $amount): void
    {
        // Serialise concurrent spenders: the account row is locked (inside the caller's transaction) before its balance is
        // read, so two simultaneous withdrawals cannot both pass the check and overdraw it.
        $account = $accountId ? FinancialAccount::whereKey($accountId)->lockForUpdate()->first() : null;
        if (! $account || $account->allow_negative) {
            return;
        }
        $available = self::balance($account->id, lock: true);
        if ($amount > $available) {
            throw new RuntimeException('Insufficient balance in "'.$account->name.'": '.Money::format(max(0, $available)).' available, '.Money::format($amount).' needed.');
        }
    }

    /**
     * Move money between two accounts (a null side is the unassigned legacy cash). Idempotent on the key.
     *
     * @param  array{from?: ?int, to?: ?int, amount: int, date: string, reference?: ?string, notes?: ?string, idempotency_key?: ?string}  $data
     * @return AccountTransfer|null null when this submission was already recorded
     */
    public static function transfer(array $data, User $user): ?AccountTransfer
    {
        $key = ($data['idempotency_key'] ?? null) ?: (string) str()->uuid();
        if (AccountTransfer::where('idempotency_key', $key)->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($data, $user, $key) {
                $from = $data['from'] ?? null;
                $to = $data['to'] ?? null;
                $amount = (int) $data['amount'];
                if ($amount <= 0) {
                    throw new RuntimeException('Amount must be greater than zero.');
                }
                if ($from === $to) {
                    throw new RuntimeException('Choose two different accounts.');
                }
                foreach (array_filter([$from, $to]) as $id) {
                    if (! FinancialAccount::whereKey($id)->where('is_active', true)->exists()) {
                        throw new RuntimeException('That account is not active.');
                    }
                }
                self::assertCanSpend($from, $amount);
                if ($from === null) {
                    FinancialAccount::lockForUpdate()->get(['id']); // legacy cash has no row of its own: lock them all
                }
                if ($from === null && self::balance(null, lock: true) < $amount) {
                    throw new RuntimeException('The unassigned legacy cash holds only '.Money::format(max(0, self::balance(null))).'.');
                }

                $transfer = AccountTransfer::create([
                    'from_account_id' => $from, 'to_account_id' => $to, 'amount' => $amount, 'transferred_on' => $data['date'],
                    'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'idempotency_key' => $key, 'created_by' => $user->id,
                ]);
                Ledger::post($data['date'], 'Transfer #'.$transfer->id, 'transfer', $transfer->id, [
                    ['cash', null, $amount, 0, $to],
                    ['cash', null, 0, $amount, $from],
                ], $user->id);
                Audit::log('account.transfer', 'transfer '.$transfer->id, $amount.' from '.($from ?? 'unassigned').' to '.($to ?? 'unassigned'));

                return $transfer;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** A transfer is never edited or deleted: it is voided by a reversing entry, provided the receiving account can give the money back. */
    public static function voidTransfer(AccountTransfer $transfer, User $user): void
    {
        DB::transaction(function () use ($transfer, $user) {
            $transfer = AccountTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->isVoided()) {
                throw new RuntimeException('This transfer is already voided.');
            }
            self::assertCanSpend($transfer->to_account_id, $transfer->amount);
            $original = JournalEntry::where('source_type', 'transfer')->where('source_id', $transfer->id)->firstOrFail();
            Ledger::reverse($original, $original->entry_date->toDateString(), 'Void of transfer #'.$transfer->id, 'transfer_reversal', $transfer->id, $user->id);
            $transfer->update(['voided_at' => now(), 'voided_by' => $user->id]);
            Audit::log('account.transfer_voided', 'transfer '.$transfer->id);
        });
    }

    /** True when the month containing this date has been closed and frozen. */
    public static function isClosed(string $date): bool
    {
        return AccountingPeriod::where('month', substr($date, 0, 7))->exists();
    }
}
