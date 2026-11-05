<?php

namespace App\Services;

use App\Models\AccountStatement;
use App\Models\FinancialAccount;
use App\Models\User;
use RuntimeException;

/**
 * A financial account for bKash, a bank or cash in hand is PREPARED here as an inactive record with only a name and a type: no number, no opening balance and no
 * historical entry is ever assumed. It becomes usable only when
 *   1. Finance confirms the real identity (a reference to the actual wallet or account) and the opening date, and
 *   2. a DIFFERENT person activates it, and only once a statement for that account, dated on or after the opening date, has been verified (the existing statements workflow).
 * Historical cash is not assigned by this class; that stays in the existing opening cash allocation, which needs real balances and a Board approval.
 */
class AccountActivation
{
    public static function prepare(string $type, string $name, User $user): FinancialAccount
    {
        $name = trim($name);
        if (! array_key_exists($type, FinancialAccount::TYPES) || $name === '') {
            throw new RuntimeException('Choose the account type and enter its name.');
        }
        if (FinancialAccount::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new RuntimeException('An account called "'.$name.'" already exists; check Cash & Bank Accounts before preparing another.');
        }
        $account = FinancialAccount::create(['name' => $name, 'type' => $type, 'is_active' => false, 'activation_status' => 'pending_confirmation',
            'notes' => 'Prepared; unusable until its identity, opening date and statement are confirmed']);
        Audit::log('account.prepared', 'account '.$account->id, $name.' ('.$type.')');

        return $account;
    }

    public static function confirmIdentity(FinancialAccount $account, string $identityReference, string $openingDate, User $user): void
    {
        if ($account->activation_status !== 'pending_confirmation') {
            throw new RuntimeException('This account is not waiting for identity confirmation.');
        }
        if (trim($identityReference) === '') {
            throw new RuntimeException('Enter the real identity of the account (for example the wallet or bank account reference as it appears on its statement).');
        }
        if ($openingDate > today()->toDateString()) {
            throw new RuntimeException('The opening date cannot be in the future.');
        }
        $account->update(['identity_reference' => trim($identityReference), 'opening_date' => $openingDate, 'activation_status' => 'awaiting_statement', 'confirmed_by' => $user->id, 'confirmed_at' => now()]);
        Audit::log('account.identity_confirmed', 'account '.$account->id, $openingDate);
    }

    public static function activate(FinancialAccount $account, User $user): void
    {
        if ($account->activation_status !== 'awaiting_statement') {
            throw new RuntimeException('Confirm the account\'s identity and opening date first.');
        }
        if ($account->confirmed_by === $user->id) {
            throw new RuntimeException('Someone other than the person who confirmed the identity must activate the account.');
        }
        $verified = AccountStatement::where('financial_account_id', $account->id)->where('status', 'verified')->whereDate('statement_date', '>=', $account->opening_date)->exists();
        if (! $verified) {
            throw new RuntimeException('Record a statement for this account dated on or after '.$account->opening_date->format('j M Y').' and have it verified (Account Statements) before activating it.');
        }
        $account->update(['is_active' => true, 'activation_status' => 'active', 'activated_by' => $user->id, 'activated_at' => now()]);
        Audit::log('account.activated', 'account '.$account->id);
    }
}
