<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\Audit;
use App\Support\Permissions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Targeted password rotation without seeding: php artisan user:set-password admin@example.com
// The password is typed at a hidden prompt, never passed as an argument or printed.
Artisan::command('user:set-password {email}', function (string $email) {
    $user = User::where('email', $email)->first();
    if (! $user) {
        $this->error('No user with that email.');

        return 1;
    }
    $password = $this->secret('New password (min 14 characters)');
    if (strlen((string) $password) < 14 || $password !== $this->secret('Repeat the password')) {
        $this->error('Passwords did not match or are shorter than 14 characters. Nothing changed.');

        return 1;
    }
    $user->update(['password' => $password]);
    DB::table('sessions')->where('user_id', $user->id)->delete();
    Audit::log('user.password_rotated', $user->email);
    $this->info('Password updated and existing sessions for this user were ended.');
})->purpose('Securely change one user\'s password');

// Recalculates amount paid / status of existing invoices from saved payments and segment payments.
// It never creates or edits payments, is idempotent, and --dry-run only reports what would change.
Artisan::command('invoices:reconcile {--dry-run : Report changes without saving}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $changed = 0;

    Invoice::query()->orderBy('id')->each(function (Invoice $invoice) use ($dryRun, &$changed) {
        DB::transaction(function () use ($invoice, $dryRun, &$changed) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $before = [$invoice->amount_paid, $invoice->status];
            $invoice->refreshPaymentStatus();

            if ($before !== [$invoice->amount_paid, $invoice->status]) {
                $changed++;
                $this->line("{$invoice->number}: paid {$before[0]} -> {$invoice->amount_paid}, {$before[1]->value} -> {$invoice->status->value}");
            }

            if ($dryRun) {
                DB::rollBack();
                DB::beginTransaction();
            }
        });
    });

    if (! $dryRun && $changed > 0) {
        Audit::log('invoices.reconciled', 'console', "{$changed} invoice(s) recalculated");
    }

    $this->info(($dryRun ? 'Dry run: ' : '')."{$changed} invoice(s) ".($dryRun ? 'would change.' : 'updated.'));
})->purpose('Recalculate invoice paid amounts and statuses (safe to re-run; no payments are created or changed)');

// One-time, owner-authorised role grant from the server shell (for the account that cannot edit its own roles).
// It adds a single role, needs an explicit "yes", and is written to the audit log as a console action.
Artisan::command('user:grant-role {email} {role}', function (string $email, string $role) {
    $user = User::where('email', $email)->first();
    if (! $user || ! array_key_exists($role, Permissions::ROLES)) {
        $this->error('Unknown user or role. Roles: '.implode(', ', array_keys(Permissions::ROLES)));

        return 1;
    }
    $this->line("Current roles of {$email}: ".(implode(', ', $user->roleKeys()) ?: '(none)'));
    if (! $this->confirm("Grant '{$role}' to {$email}? The business owner must have authorised this.", false)) {
        $this->warn('Cancelled. Nothing changed.');

        return 1;
    }
    $user->addRole($role);
    Audit::log('user.role_granted_console', $user->email, $role.' (granted from the server console)');
    $this->info('Role granted and recorded in the audit log.');
})->purpose('Grant one role to a user from the server console, with confirmation and audit');
