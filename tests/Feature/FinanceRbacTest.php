<?php

// Helpers financeUser() and signPolicy() are defined in FinanceRulesTest.php.

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\ProfitAllocator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['admin.email' => 'admin@x.test', 'admin.password' => 'password-123']);
    $this->seed(DatabaseSeeder::class);
    $this->policy = PolicyVersion::first();
});

function multiUser(string $name, array $roles, ?string $partnerCode = null): User
{
    $user = User::create(['name' => $name, 'email' => $name.'@x.test', 'password' => 'password-123',
        'partner_id' => $partnerCode ? Partner::firstWhere('code', $partnerCode)->id : null]);
    $user->setRoles($roles);

    return $user;
}

it('combines the permissions of every role a user holds', function () {
    $u = multiUser('combo', ['partner', 'finance', 'client_management']);

    expect($u->roleKeys())->toBe(['client_management', 'finance', 'partner'])
        ->and($u->can('capital.record'))->toBeTrue()   // finance
        ->and($u->can('payout.request'))->toBeTrue()   // partner
        ->and($u->can('clienthub'))->toBeTrue()
        ->and($u->can('payout.approve'))->toBeFalse(); // no Board role

    $u->setRoles(['partner']);
    expect($u->can('capital.record'))->toBeFalse();    // removing a role takes effect immediately
});

it('gives Super Admin full operational access but not Board approvals on its own', function () {
    $sa = multiUser('sa', ['super_admin']);

    foreach (['users.manage', 'clienthub', 'finance.view', 'capital.record', 'expense.record', 'period.close', 'allocation.prepare', 'allocation.post', 'payout.pay', 'opening.prepare', 'partner.manage'] as $p) {
        expect($sa->can($p))->toBeTrue($p);
    }
    foreach (['policy.approve', 'allocation.approve', 'payout.approve', 'expense.verify', 'exit.approve', 'opening.approve'] as $p) {
        expect($sa->can($p))->toBeFalse($p);
    }

    $sa->setRoles(['super_admin', 'board_member']);
    expect($sa->can('allocation.approve'))->toBeTrue();
});

it('lets a flexible three-founder team run an expense and an allocation without outsiders', function () {
    $a = multiUser('pa', ['super_admin', 'board_member', 'finance', 'partner'], 'A');
    $b = multiUser('pb', ['board_member', 'partner', 'client_management'], 'B');
    $c = multiUser('pc', ['board_member', 'partner', 'finance'], 'C');

    // A records an expense; A cannot verify it, B (Board) can, and it is posted on verification.
    $this->actingAs($a)->post(route('finance.expenses.store'), ['category' => 'Rent', 'description' => 'Office', 'amount' => '500', 'spent_on' => today()->toDateString()]);
    $expense = Expense::first();
    $this->actingAs($a)->post(route('finance.expenses.decide', $expense), ['decision' => 'verify'])->assertSessionHas('error');
    expect($expense->fresh()->status)->toBe('pending');
    $this->actingAs($b)->post(route('finance.expenses.decide', $expense), ['decision' => 'verify'])->assertSessionHas('status');
    expect($expense->fresh()->verified_by)->toBe($b->id)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));

    // C prepares an allocation; C cannot approve it, A (Super Admin + Board) can; A (Finance) posts it once.
    signPolicy($this->policy);
    $period = AccountingPeriod::create(['month' => '2026-01', 'income' => 1_000_000, 'expenses' => 0, 'net_profit' => 1_000_000, 'status' => 'verified', 'closed_by' => $c->id]);
    $allocation = ProfitAllocator::prepare($period, $c);
    $this->actingAs($c)->post(route('finance.allocations.approve', $allocation))->assertSessionHas('error');
    $this->actingAs($a)->post(route('finance.allocations.approve', $allocation))->assertSessionHas('status');
    $this->actingAs($a)->post(route('finance.allocations.post', $allocation))->assertSessionHas('status');
    $this->actingAs($a)->post(route('finance.allocations.post', $allocation))->assertSessionHas('error');

    // A cannot approve an allocation that A prepared.
    $period2 = AccountingPeriod::create(['month' => '2026-02', 'income' => 100, 'expenses' => 0, 'net_profit' => 100, 'status' => 'verified', 'closed_by' => $a->id]);
    $mine = ProfitAllocator::prepare($period2, $a);
    $this->actingAs($a)->post(route('finance.allocations.approve', $mine))->assertSessionHas('error');
});

it('stops a multi-role partner from approving or paying their own payout', function () {
    $a = multiUser('pa', ['super_admin', 'board_member', 'finance', 'partner'], 'A');
    $b = multiUser('pb', ['board_member', 'partner'], 'B');
    $payout = Payout::create(['partner_id' => $a->partner_id, 'amount' => 100, 'requested_by' => $a->id]);

    $this->actingAs($a)->post(route('finance.payouts.decide', $payout), ['decision' => 'approve'])->assertSessionHas('error');
    $this->actingAs($b)->post(route('finance.payouts.decide', $payout), ['decision' => 'approve'])->assertSessionHas('status');
    $this->actingAs($a)->post(route('finance.payouts.pay', $payout))->assertSessionHas('error');
});

it('keeps ClientHub access tied to the right roles and protects role management', function () {
    $this->actingAs(multiUser('cm', ['client_management']))->get(route('clients.index'))->assertOk();
    $this->actingAs(multiUser('emp', ['employee']))->get(route('clients.index'))->assertForbidden();
    $admin = User::firstWhere('email', 'admin@x.test');
    $this->actingAs(multiUser('fin', ['finance', 'board_member']))->put(route('users.update', $admin), ['roles' => []])->assertForbidden();

    $target = multiUser('t', ['finance']);
    $this->actingAs($admin)->put(route('users.update', $target), ['roles' => ['finance', 'board_member', 'partner'], 'partner_id' => Partner::firstWhere('code', 'C')->id])->assertSessionHasNoErrors();
    expect($target->fresh()->roleKeys())->toBe(['board_member', 'finance', 'partner']);

    $this->actingAs($admin)->put(route('users.update', $admin), ['roles' => ['board_member']])->assertSessionHas('error');
    $second = multiUser('sa2', ['super_admin']);
    $this->actingAs($second)->put(route('users.update', $admin), ['roles' => ['finance']])->assertSessionHasNoErrors();
    $this->actingAs($admin->fresh())->put(route('users.update', $second), ['roles' => []])->assertForbidden();
    expect($second->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('grants a role from the console only after explicit confirmation and logs it', function () {
    $this->artisan('user:grant-role', ['email' => 'admin@x.test', 'role' => 'board_member'])
        ->expectsConfirmation("Grant 'board_member' to admin@x.test? The business owner must have authorised this.", 'no')->assertFailed();
    expect(User::firstWhere('email', 'admin@x.test')->hasRole('board_member'))->toBeFalse();

    $this->artisan('user:grant-role', ['email' => 'admin@x.test', 'role' => 'board_member'])
        ->expectsConfirmation("Grant 'board_member' to admin@x.test? The business owner must have authorised this.", 'yes')->assertSuccessful();
    $admin = User::firstWhere('email', 'admin@x.test');
    expect($admin->roleKeys())->toBe(['board_member', 'super_admin'])
        ->and(AuditLog::where('action', 'user.role_granted_console')->count())->toBe(1);
});
