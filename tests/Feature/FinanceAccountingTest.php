<?php

// Helpers financeUser() and signPolicy() are defined in FinanceRulesTest.php.

use App\Models\AccountingPeriod;
use App\Models\CapitalEntry;
use App\Models\Client;
use App\Models\ExitSettlement;
use App\Models\JournalLine;
use App\Models\OpeningBatch;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PolicyVersion;
use App\Models\Project;
use App\Models\Resolution;
use App\Models\RevenueAdjustment;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Increment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['admin.email' => 'admin@x.test', 'admin.password' => 'password-123']);
    $this->seed(DatabaseSeeder::class);
    $this->policy = PolicyVersion::first();
});

function lastMonthPayment(int $amount, string $day = '10'): Payment
{
    $project = Project::first() ?? Project::forceCreate([
        'client_id' => Client::forceCreate(['name' => 'C', 'mobile' => '1'])->id, 'name' => 'P', 'currency' => 'BDT', 'started_on' => '2026-01-01',
    ]);

    return Payment::forceCreate(['project_id' => $project->id, 'amount' => $amount, 'paid_on' => now()->subMonth()->format('Y-m-').$day,
        'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
}

it('keeps advances and refunds out of recognised revenue and books a balanced period entry', function () {
    $finance = financeUser('finance');
    $board = financeUser('board_member');
    $earned = lastMonthPayment(60_000_00);
    $advance = lastMonthPayment(40_000_00, '12');

    $this->actingAs($finance)->post(route('finance.revenue.store', $advance), ['kind' => 'advance'])->assertSessionHasNoErrors();
    $this->actingAs($finance)->post(route('finance.revenue.store', $earned), ['kind' => 'refund', 'against' => 'revenue', 'amount' => '10000', 'effective_on' => today()->toDateString()]);
    // The adjustments are pending; nothing can be closed until a different user verifies them.
    $month = now()->subMonth()->format('Y-m');
    $this->actingAs($finance)->post(route('finance.periods.close'), ['month' => $month])->assertSessionHas('error');

    foreach (RevenueAdjustment::all() as $a) {
        $this->actingAs($finance)->post(route('finance.revenue.decide', $a), ['decision' => 'verify'])->assertForbidden();
        $this->actingAs($board)->post(route('finance.revenue.decide', $a), ['decision' => 'verify']);
    }

    // The refund is dated this month, so last month's revenue is the earned 60,000 only (advance excluded).
    $this->actingAs($finance)->post(route('finance.periods.close'), ['month' => $month])->assertSessionHas('status');
    $period = AccountingPeriod::first();
    expect($period->income)->toBe(60_000_00)
        ->and(Ledger::creditBalance('customer.advances'))->toBe(40_000_00)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));

    // A refund or advance cannot be recorded twice beyond the amount, nor into the closed month.
    $this->actingAs($finance)->post(route('finance.revenue.store', $advance), ['kind' => 'advance'])->assertSessionHas('error');
    $this->actingAs($finance)->post(route('finance.revenue.store', $earned), ['kind' => 'refund', 'against' => 'revenue', 'amount' => '999999', 'effective_on' => today()->toDateString()])->assertSessionHas('error');
    $this->actingAs($finance)->post(route('finance.revenue.store', $earned), ['kind' => 'refund', 'against' => 'revenue', 'amount' => '1', 'effective_on' => now()->subMonth()->format('Y-m-15')])->assertSessionHas('error');
    // The original payment cannot be edited or voided after close.
    expect(fn () => $earned->delete())->toThrow(ValidationException::class);
});

it('posts opening balances once, balanced, after separate approval', function () {
    $finance = financeUser('finance');
    $board = financeUser('board_member');
    $a = Partner::firstWhere('code', 'A');

    $this->actingAs($finance)->post(route('finance.opening.store'), ['type' => 'opening', 'as_of' => '2026-01-01'])->assertSessionHasNoErrors();
    $batch = OpeningBatch::first();
    $this->actingAs($finance)->post(route('finance.opening.lines', $batch), ['kind' => 'cash', 'amount' => '500000']);
    $this->actingAs($finance)->post(route('finance.opening.lines', $batch), ['kind' => 'partner_capital', 'partner_id' => $a->id, 'amount' => '400000']);
    $this->actingAs($finance)->post(route('finance.opening.store'), ['type' => 'opening', 'as_of' => '2026-01-01'])->assertSessionHas('error');

    $this->actingAs($finance)->post(route('finance.opening.approve', $batch))->assertForbidden();
    $this->actingAs($finance)->post(route('finance.opening.post', $batch))->assertSessionHas('error'); // not approved
    $this->actingAs($board)->post(route('finance.opening.approve', $batch));
    $this->actingAs($finance)->post(route('finance.opening.post', $batch))->assertSessionHas('status');
    $this->actingAs($finance)->post(route('finance.opening.post', $batch))->assertSessionHas('error'); // second post

    expect(Ledger::debitBalance('cash'))->toBe(500_000_00)
        ->and(Ledger::creditBalance('partner.capital', $a->id))->toBe(400_000_00)
        ->and(Ledger::creditBalance('equity.opening'))->toBe(100_000_00)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));
});

it('maps each partner to one user and blocks self-escalation', function () {
    $admin = User::firstWhere('email', 'admin@x.test');
    $a = Partner::firstWhere('code', 'A');
    $u1 = User::create(['name' => 'u1', 'email' => 'u1@x.test', 'password' => 'password-123']);
    $u2 = User::create(['name' => 'u2', 'email' => 'u2@x.test', 'password' => 'password-123']);

    $this->actingAs($admin)->put(route('users.update', $u1), ['roles' => ['partner'], 'partner_id' => $a->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->put(route('users.update', $u2), ['roles' => ['partner'], 'partner_id' => $a->id])->assertSessionHasErrors('partner_id');
    $this->actingAs($admin)->put(route('users.update', $u2), ['roles' => ['partner']])->assertSessionHas('error');
    $this->actingAs($admin)->put(route('users.update', $admin), ['roles' => ['board_member']])->assertSessionHas('error');
    $this->actingAs($u1)->put(route('users.update', $u1), ['roles' => ['super_admin']])->assertForbidden();
    expect($u1->fresh()->roleKeys())->toBe(['partner']);
});

it('reports policy status and cancels an unpaid settlement for recalculation', function () {
    expect($this->policy->status())->toBe('DRAFT');
    signPolicy($this->policy, false);
    expect($this->policy->fresh()->status())->toBe('APPROVED');
    $this->policy->update(['profit_confirmed_at' => now(), 'profit_signed_reference' => 'R']);
    expect($this->policy->fresh()->status())->toBe('ACTIVE');

    $finance = financeUser('finance');
    $c = Partner::firstWhere('code', 'C');
    Resolution::create(['type' => 'exit_request', 'title' => 'Exit', 'partner_id' => $c->id, 'status' => 'approved', 'requested_by' => $finance->id]);
    $this->actingAs($finance)->post(route('finance.exits.store'), ['partner_id' => $c->id, 'exit_date' => today()->toDateString()])->assertSessionHasNoErrors();
    $exit = ExitSettlement::first();
    $this->actingAs($finance)->post(route('finance.exits.store'), ['partner_id' => $c->id, 'exit_date' => today()->toDateString()])->assertSessionHas('error');

    $this->actingAs($financeUser = financeUser('hr'))->post(route('finance.exits.cancel', $exit), ['cancel_reason' => 'x'])->assertForbidden();
    $this->actingAs($finance)->post(route('finance.exits.cancel', $exit), ['cancel_reason' => 'figures changed'])->assertSessionHas('status');
    expect($exit->fresh()->status)->toBe('cancelled');
    $this->actingAs($finance)->post(route('finance.exits.store'), ['partner_id' => $c->id, 'exit_date' => today()->toDateString()])->assertSessionHasNoErrors();
    expect(ExitSettlement::count())->toBe(2);
});

it('does not extrapolate the increment above a 300 percent profit rate', function () {
    expect(Increment::theoreticalBps(40000))->toBe(3000)->and(Increment::rateBps(100, 0))->toBe(0);
});

it('blocks counting capital twice and records the effective date at policy approval', function () {
    $finance = financeUser('finance');
    $a = Partner::firstWhere('code', 'A');
    $this->actingAs($finance)->post(route('finance.opening.store'), ['type' => 'opening', 'as_of' => '2026-01-01']);
    $this->actingAs($finance)->post(route('finance.opening.lines', OpeningBatch::first()), ['kind' => 'partner_capital', 'partner_id' => $a->id, 'amount' => '1000']);
    $this->actingAs($finance)->post(route('finance.capital.store'), ['partner_id' => $a->id, 'amount' => '1000', 'paid_on' => '2025-12-01'])->assertSessionHas('error');
    expect(CapitalEntry::count())->toBe(0);

    $this->actingAs(financeUser('board_member'))->post(route('finance.policies.approve', $this->policy), ['signed_reference' => 'BR-9'])->assertSessionHasErrors('effective_from');
    $this->actingAs(financeUser('board_member'))->post(route('finance.policies.approve', $this->policy), ['signed_reference' => 'BR-9', 'effective_from' => '2027-02-01']);
    expect($this->policy->fresh()->effective_from->toDateString())->toBe('2027-02-01')->and($this->policy->fresh()->status())->toBe('APPROVED');
});
