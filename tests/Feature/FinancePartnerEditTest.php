<?php

// Helper financeUser() is defined in FinanceRulesTest.php.

use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['admin.email' => 'admin@x.test', 'admin.password' => 'password-123']);
    $this->seed(DatabaseSeeder::class);
});

it('lets authorised users edit a partner profile, with audit, without touching financial ratios', function () {
    $b = Partner::firstWhere('code', 'B');
    $policyBefore = PolicyVersion::first()->only(['investment_bps', 'ownership_shares', 'loss_shares', 'profit_bps']);

    $this->actingAs(financeUser('board_member'))->get(route('finance.partners.edit', $b))->assertOk()->assertSee('Save Changes')->assertSee($b->name);
    $this->actingAs(User::firstWhere('email', 'admin@x.test'))->put(route('finance.partners.update', $b), ['name' => 'Bilal Rahman', 'joined_on' => '2025-01-15', 'status' => 'exited', 'profit_bps' => 9999])
        ->assertRedirect(route('finance.partners.show', $b));

    expect($b->fresh()->name)->toBe('Bilal Rahman')
        ->and($b->fresh()->status)->toBe('active')                                   // status is not editable here
        ->and(PolicyVersion::first()->only(['investment_bps', 'ownership_shares', 'loss_shares', 'profit_bps']))->toBe($policyBefore)
        ->and(AuditLog::where('action', 'partner.updated')->where('subject', 'B')->count())->toBe(1);

    $this->actingAs(financeUser('board_member'))->get(route('finance.partners'))->assertSee('Edit');
});

it('rejects invalid, duplicate and unauthorised partner edits', function () {
    $b = Partner::firstWhere('code', 'B');
    $board = financeUser('board_member');

    $this->actingAs($board)->from(route('finance.partners.edit', $b))->put(route('finance.partners.update', $b), ['name' => ''])->assertSessionHasErrors('name');
    $this->actingAs($board)->put(route('finance.partners.update', $b), ['name' => Partner::firstWhere('code', 'A')->name])->assertSessionHasErrors('name');

    foreach (['finance', 'auditor', 'employee', 'hr'] as $role) {
        $this->actingAs(financeUser($role))->get(route('finance.partners.edit', $b))->assertForbidden();
        $this->actingAs(financeUser($role))->put(route('finance.partners.update', $b), ['name' => 'Hacked'])->assertForbidden();
    }
    expect($b->fresh()->name)->toBe('Partner B');

    // A fourth active founder is still refused.
    $this->actingAs($board)->post(route('finance.partners.store'), ['code' => 'D', 'name' => 'Partner D'])->assertSessionHas('error');
    expect(Partner::where('status', 'active')->count())->toBe(3);
});

it('serves the themed layout with accessible landmarks to a multi-role user', function () {
    $u = User::create(['name' => 'multi', 'email' => 'multi@x.test', 'password' => 'password-123']);
    $u->setRoles(['finance', 'board_member', 'partner']);
    $u->update(['partner_id' => Partner::firstWhere('code', 'C')->id]);

    $this->actingAs($u)->get(route('finance.dashboard'))->assertOk()
        ->assertSee('Skip to main content')->assertSee('data-theme-set="dark"', false)->assertSee('Finance Dashboard')
        ->assertSee('Partners')->assertSee('Board Resolutions')->assertDontSee('Users &amp; Roles', false);
});
