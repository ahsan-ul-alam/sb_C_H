<?php

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\ExitSettlement;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Models\Resolution;
use App\Models\User;
use App\Services\Ledger;
use App\Services\ProfitAllocator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function financeUser(string $role): User
{
    $user = User::firstOrCreate(['email' => $role.'@x.test'], ['name' => $role, 'password' => 'password-123']);
    if ($user->wasRecentlyCreated) {
        $user->setRoles([$role]);
    }

    return $user;
}

beforeEach(function () {
    config(['admin.email' => 'admin@x.test', 'admin.password' => 'password-123']);
    $this->seed(DatabaseSeeder::class);
    $this->policy = PolicyVersion::first();
});

function signPolicy(PolicyVersion $policy, bool $profitConfirmed = true): void
{
    $policy->update(['effective_from' => '2020-01-01', 'approved_at' => now(), 'signed_reference' => 'RES-1', 'approved_by' => financeUser('board_member')->id]);
    if ($profitConfirmed) {
        $policy->update(['profit_confirmed_at' => now(), 'profit_signed_reference' => 'RES-2']);
    }
}

it('has exactly three active founders with independent ratios', function () {
    expect(Partner::where('status', 'active')->count())->toBe(3)
        ->and($this->policy->investment_bps)->toBe(['A' => 4000, 'B' => 3000, 'C' => 3000])
        ->and($this->policy->ownership_shares)->toBe(['A' => 1, 'B' => 1, 'C' => 1])
        ->and($this->policy->loss_shares)->toBe(['A' => 1, 'B' => 1, 'C' => 1]);
});

it('splits BDT 1,000,000 into the exact leaf amounts and never writes on preview', function () {
    $lines = collect(ProfitAllocator::preview(100_000_000, $this->policy))
        ->mapWithKeys(fn ($l) => [$l['account'].':'.($l['partner_id'] ?? '') => $l['amount']]);

    expect($lines->values()->all())->toBe([20_000_000, 5_000_000, 25_000_000, 20_000_000, 15_000_000, 15_000_000])
        ->and(JournalEntry::count())->toBe(0)
        ->and(Allocation::count())->toBe(0);
});

it('splits a loss into equal thirds with a deterministic remainder', function () {
    $amounts = array_column(ProfitAllocator::preview(-100, $this->policy), 'amount');

    expect($amounts)->toBe([34, 33, 33]);
});

it('blocks live allocation until the policy and profit ratio are signed', function () {
    $period = AccountingPeriod::create(['month' => '2026-01', 'income' => 100, 'expenses' => 0, 'net_profit' => 100,
        'status' => 'verified', 'closed_by' => financeUser('finance')->id]);

    expect(fn () => ProfitAllocator::prepare($period, financeUser('auditor')))->toThrow(RuntimeException::class);
});

it('prepares, approves and posts once, with separate people and a balanced ledger', function () {
    signPolicy($this->policy);
    $finance = financeUser('finance');
    $board = financeUser('board_member');
    $period = AccountingPeriod::create(['month' => '2026-01', 'income' => 100_000_000, 'expenses' => 0, 'net_profit' => 100_000_000,
        'status' => 'verified', 'closed_by' => $finance->id]);

    $allocation = ProfitAllocator::prepare($period, $finance);
    expect(fn () => ProfitAllocator::approve($allocation, $finance))->toThrow(RuntimeException::class);
    expect(fn () => ProfitAllocator::post($allocation, $finance))->toThrow(RuntimeException::class);

    ProfitAllocator::approve($allocation, $board);
    ProfitAllocator::post($allocation, $finance);

    expect(fn () => ProfitAllocator::post($allocation->fresh(), $finance))->toThrow(RuntimeException::class)
        ->and(fn () => ProfitAllocator::prepare($period, $finance))->toThrow(RuntimeException::class)
        ->and(Ledger::creditBalance('fund.emergency'))->toBe(25_000_000)
        ->and(Ledger::creditBalance('partner.payable', Partner::firstWhere('code', 'B')->id))->toBe(15_000_000)
        ->and(fn () => $allocation->fresh()->update(['net_profit' => 1]))->toThrow(LogicException::class);
});

it('computes the exit incentive from the profit-fund ratio, not ownership', function () {
    signPolicy($this->policy);
    $finance = financeUser('finance');
    $board = financeUser('board_member');
    // Company Fund allocations of 400,000 BDT in the last 12 months (fund lines total 40,000,000 paisa).
    $period = AccountingPeriod::create(['month' => now()->subMonth()->format('Y-m'), 'income' => 80_000_000, 'expenses' => 0,
        'net_profit' => 80_000_000, 'status' => 'verified', 'closed_by' => $finance->id]);
    $allocation = ProfitAllocator::prepare($period, $finance);
    ProfitAllocator::approve($allocation, $board);
    ProfitAllocator::post($allocation, $finance);

    $partner = Partner::firstWhere('code', 'B');
    Resolution::create(['type' => 'exit_request', 'title' => 'Exit', 'partner_id' => $partner->id, 'status' => 'approved', 'requested_by' => $finance->id]);
    $this->actingAs($finance)->post(route('finance.exits.store'), ['partner_id' => $partner->id, 'exit_date' => now()->toDateString()])
        ->assertSessionHasNoErrors();

    expect(ExitSettlement::first()->incentive)->toBe(12_000_000);
});

it('enforces roles on the backend', function () {
    signPolicy($this->policy);
    $period = AccountingPeriod::create(['month' => '2026-01', 'income' => 100, 'expenses' => 0, 'net_profit' => 100, 'status' => 'verified', 'closed_by' => financeUser('finance')->id]);
    $allocation = ProfitAllocator::prepare($period, financeUser('finance'));
    $this->actingAs(financeUser('employee'))->get(route('finance.dashboard'))->assertForbidden();
    $this->actingAs(financeUser('finance'))->post(route('finance.allocations.approve', $allocation))->assertForbidden();
    $this->actingAs(financeUser('board_member'))->post(route('finance.allocations.post', $allocation))->assertForbidden();
    $this->actingAs(User::firstWhere('email', 'admin@x.test'))->post(route('finance.allocations.approve', $allocation))->assertForbidden();
    // Super Admin may try to post (operational access), but the allocation is not Board-approved, so it is refused.
    $this->actingAs(User::firstWhere('email', 'admin@x.test'))->post(route('finance.allocations.post', $allocation))->assertSessionHas('error');
});
