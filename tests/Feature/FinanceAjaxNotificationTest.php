<?php

// Helpers financeUser(), signPolicy() (FinanceRulesTest) and multiUser() (FinanceRbacTest) are defined in sibling files.

use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\Notify;
use App\Services\ProfitAllocator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['admin.email' => 'admin@x.test', 'admin.password' => 'password-123']);
    $this->seed(DatabaseSeeder::class);
    $this->policy = PolicyVersion::first();
});

function unreadFor(User $u): int
{
    return $u->unreadNotifications()->count();
}

it('answers AJAX form posts with JSON while enforcing the same rules as a normal post', function () {
    $board = financeUser('board_member');
    $b = Partner::firstWhere('code', 'B');

    // Validation -> 422 with field errors; success -> 200 JSON with a message and the next URL.
    $this->actingAs($board)->putJson(route('finance.partners.update', $b), ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->actingAs($board)->putJson(route('finance.partners.update', $b), ['name' => 'Bilal'])->assertOk()
        ->assertJson(['ok' => true, 'message' => 'Partner B updated.', 'redirect' => route('finance.partners.show', $b)]);

    // Authorisation -> 403 JSON; business-rule violation -> 422 JSON, same as the redirect-with-error of a normal post.
    $this->actingAs(financeUser('finance'))->putJson(route('finance.partners.update', $b), ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($board)->postJson(route('finance.partners.store'), ['code' => 'D', 'name' => 'Partner D'])->assertStatus(422)->assertJsonFragment(['ok' => false]);
    $this->actingAs($board)->post(route('finance.partners.store'), ['code' => 'D', 'name' => 'Partner D'])->assertSessionHas('error');

    // Not signed in -> 401 JSON.
    auth()->logout();
    $this->postJson(route('finance.expenses.store'), [])->assertUnauthorized();
});

it('keeps one-time posting and separation of duties under AJAX', function () {
    signPolicy($this->policy);
    $a = multiUser('pa', ['super_admin', 'board_member', 'finance', 'partner'], 'A');
    $c = multiUser('pc', ['board_member', 'finance', 'partner'], 'C');
    $period = AccountingPeriod::create(['month' => '2026-01', 'income' => 100_000, 'expenses' => 0, 'net_profit' => 100_000, 'status' => 'verified', 'closed_by' => $c->id]);
    $allocation = ProfitAllocator::prepare($period, $c);

    $this->actingAs($c)->postJson(route('finance.allocations.approve', $allocation))->assertStatus(422);   // preparer cannot approve
    $this->actingAs($a)->postJson(route('finance.allocations.approve', $allocation))->assertOk();
    $this->actingAs($a)->postJson(route('finance.allocations.post', $allocation))->assertOk();
    $this->actingAs($a)->postJson(route('finance.allocations.post', $allocation))->assertStatus(422);       // duplicate post refused
    expect($allocation->fresh()->status)->toBe('posted');
});

it('notifies only eligible approvers once, and never the preparer or the beneficiary partner', function () {
    $a = multiUser('pa', ['board_member', 'finance', 'partner'], 'A');
    $b = multiUser('pb', ['board_member', 'partner'], 'B');
    $fin = multiUser('fin', ['finance'], null);
    $aud = multiUser('aud', ['auditor']);

    // A records an expense: B (Board) is asked to verify; A (creator), Finance-only and Auditor are not.
    $this->actingAs($a)->postJson(route('finance.expenses.store'), ['category' => 'Rent', 'description' => 'Office', 'amount' => '500', 'spent_on' => today()->toDateString()])->assertOk();
    $expense = Expense::first();
    expect(unreadFor($b))->toBe(1)->and(unreadFor($a))->toBe(0)->and(unreadFor($fin))->toBe(0)->and(unreadFor($aud))->toBe(0);

    // Re-sending the same event while unread does not duplicate it.
    Notify::send([$b], 'Expense awaiting verification', 'again', 'finance.expenses', 'approval', 'expense:'.$expense->id.':verify');
    expect(unreadFor($b))->toBe(1);

    // B verifies: the creator hears the result; the payload carries no amounts and only a relative path.
    $this->actingAs($b)->postJson(route('finance.expenses.decide', $expense), ['decision' => 'verify'])->assertOk();
    $n = $a->notifications()->first();
    expect($n->data['title'])->toBe('Expense verified')->and($n->data['url'])->toStartWith('/')->and(json_encode($n->data))->not->toContain('500');

    // A partner who is also Board is not asked to approve their own payout; the other Board member is.
    Payout::create(['partner_id' => $a->partner_id, 'amount' => 100, 'requested_by' => $a->id]);
    expect(unreadFor($b))->toBe(2)                                                             // expense item + the new payout request
        ->and($b->unreadNotifications()->where('data', 'like', '%payout:%')->count())->toBe(1)
        ->and($a->unreadNotifications()->where('data', 'like', '%payout:%')->count())->toBe(0);
});

it('lets users read only their own notifications', function () {
    $a = multiUser('pa', ['board_member', 'finance'], null);
    $b = multiUser('pb', ['board_member'], null);
    $this->actingAs($a)->postJson(route('finance.expenses.store'), ['category' => 'Rent', 'description' => 'Office', 'amount' => '5', 'spent_on' => today()->toDateString()]);
    $bn = $b->notifications()->first();

    $this->actingAs($a)->postJson(route('notifications.read', $bn->id))->assertNotFound();           // someone else's notification
    expect($bn->fresh()->read_at)->toBeNull();
    $this->actingAs($a)->getJson(route('notifications.feed'))->assertOk()->assertJson(['unread' => 0, 'items' => []]);

    $this->actingAs($b)->getJson(route('notifications.count'))->assertOk()->assertJson(['unread' => 1]);
    $this->actingAs($b)->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('items.0.read', false)->assertJsonPath('items.0.title', 'Expense awaiting verification');
    $this->actingAs($b)->postJson(route('notifications.read', $bn->id))->assertOk()->assertJson(['unread' => 0]);
    $this->actingAs($b)->get(route('notifications.index'))->assertOk()->assertSee('Notifications');

    // A notification never grants access: its destination re-checks permissions.
    $this->actingAs(multiUser('emp', ['employee']))->get(route('finance.expenses'))->assertForbidden();
    auth()->logout();
    $this->getJson(route('notifications.count'))->assertUnauthorized();
});

it('tells a user when their roles change, without notifying everyone', function () {
    $admin = User::firstWhere('email', 'admin@x.test');
    $target = multiUser('t', ['employee']);
    $other = multiUser('o', ['finance']);

    $this->actingAs($admin)->putJson(route('users.update', $target), ['roles' => ['employee', 'hr']])->assertOk();
    expect(unreadFor($target))->toBe(1)->and(unreadFor($other))->toBe(0)->and(unreadFor($admin))->toBe(0);
});

it('never lets a missing notifications table or a rolled-back save affect the business action', function () {
    $a = multiUser('pa', ['finance'], null);
    $b = multiUser('pb', ['board_member'], null);

    // A save that rolls back produces no notification.
    try {
        DB::transaction(function () use ($a) {
            Expense::create(['category' => 'X', 'description' => 'Y', 'amount' => 1, 'spent_on' => today(), 'created_by' => $a->id]);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(Expense::count())->toBe(0)->and(unreadFor($b))->toBe(0);

    // Notifications table not migrated yet: the expense is still saved and the AJAX call still succeeds.
    Schema::drop('notifications');
    $this->actingAs($a)->postJson(route('finance.expenses.store'), ['category' => 'Rent', 'description' => 'Office', 'amount' => '500', 'spent_on' => today()->toDateString()])->assertOk();
    expect(Expense::count())->toBe(1);
    $this->actingAs($b)->getJson(route('notifications.count'))->assertOk()->assertJson(['unread' => 0]);
    $this->actingAs($b)->get(route('notifications.index'))->assertOk();
});
