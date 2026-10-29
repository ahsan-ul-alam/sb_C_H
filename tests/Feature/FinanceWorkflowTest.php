<?php

// Helpers financeUser() and signPolicy() are defined in FinanceRulesTest.php.

use App\Models\AccountingPeriod;
use App\Models\CapitalEntry;
use App\Models\Client;
use App\Models\Document;
use App\Models\ExitSettlement;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PolicyVersion;
use App\Models\Project;
use App\Models\Resolution;
use App\Models\User;
use App\Services\Ledger;
use App\Services\ProfitAllocator;
use App\Support\Increment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['admin.email' => 'admin@x.test', 'admin.password' => 'password-123']);
    $this->seed(DatabaseSeeder::class);
    $this->policy = PolicyVersion::first();
});

it('has no public migration route and new accounts have no role', function () {
    $this->get('/cc')->assertNotFound();
    $this->get(route('finance.dashboard'))->assertRedirect(route('login'));

    $noRole = User::create(['name' => 'n', 'email' => 'n@x.test', 'password' => 'password-123']);
    expect($noRole->roleKeys())->toBe([]);
    $this->actingAs($noRole)->get(route('finance.dashboard'))->assertForbidden();
    $this->actingAs($noRole)->get(route('clients.index'))->assertForbidden();
});

it('computes the theoretical increment at the documented points', function () {
    $bps = fn (int $ratePercent) => Increment::theoreticalBps($ratePercent * 100);

    expect($bps(79))->toBe(0)
        ->and($bps(80))->toBe(1000)->and($bps(90))->toBe(1500)->and($bps(100))->toBe(2000)
        ->and($bps(150))->toBe(2250)->and($bps(200))->toBe(2500)->and($bps(300))->toBe(3000)
        ->and(Increment::rateBps(90_000, 100_000))->toBe(9000)
        ->and(Increment::apply(5_000_000, 1500))->toBe(5_750_000);
});

it('records a Board-approved actual increment separate from the theoretical one', function () {
    $hr = financeUser('hr');
    $board = financeUser('board_member');
    $this->actingAs($hr)->post(route('finance.resolutions.store'), ['type' => 'employee_increment', 'title' => 'Yearly', 'employee_name' => 'Employee D', 'previous_amount' => '50000'])
        ->assertSessionHasNoErrors();
    $r = Resolution::first();
    expect($r->theoretical_bps)->toBe(0)->and($r->approved_bps)->toBeNull()->and($r->amount)->toBe(0);

    $this->actingAs($hr)->post(route('finance.resolutions.decide', $r), ['decision' => 'approve'])->assertForbidden();
    Ledger::post(today()->toDateString(), 'seed fund', 'test', 1, [['cash', null, 10_000_000, 0], ['fund.salary', null, 0, 10_000_000]], $board->id);
    $this->actingAs($board)->post(route('finance.resolutions.decide', $r), ['decision' => 'approve', 'approved_bps' => 1000, 'effective_on' => '2027-01-01', 'approval_reference' => 'BR-1']);

    expect($r->fresh()->approved_bps)->toBe(1000)->and($r->fresh()->amount)->toBe(5_500_000);
});

it('prevents self-approval of a partner payout', function () {
    $partnerA = Partner::firstWhere('code', 'A');
    $userA = User::create(['name' => 'a', 'email' => 'a@x.test', 'password' => 'password-123', 'partner_id' => $partnerA->id]);
    $userA->setRoles(['partner']);
    $payout = Payout::create(['partner_id' => $partnerA->id, 'amount' => 100, 'requested_by' => $userA->id]);

    $this->actingAs($userA)->post(route('finance.payouts.decide', $payout), ['decision' => 'approve'])->assertForbidden();
    $boardA = User::create(['name' => 'ba', 'email' => 'ba@x.test', 'password' => 'password-123', 'partner_id' => $partnerA->id]);
    $boardA->setRoles(['board_member', 'partner', 'finance']);
    $this->actingAs($boardA)->post(route('finance.payouts.decide', $payout), ['decision' => 'approve'])->assertSessionHas('error');
    expect($payout->fresh()->status)->toBe('requested');

    $this->actingAs($userA)->get(route('finance.me'))->assertOk();
    $this->actingAs($userA)->get(route('finance.journal'))->assertForbidden();
});

it('freezes ClientHub payments inside a closed period', function () {
    $client = Client::forceCreate(['name' => 'C', 'mobile' => '1']);
    $project = Project::forceCreate(['client_id' => $client->id, 'name' => 'P', 'currency' => 'BDT', 'started_on' => '2026-01-01']);
    $make = fn (string $date) => Payment::forceCreate(['project_id' => $project->id, 'amount' => 1000, 'paid_on' => $date, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
    $payment = $make('2026-01-10');
    AccountingPeriod::create(['month' => '2026-01', 'income' => 1000, 'expenses' => 0, 'net_profit' => 1000, 'closed_by' => financeUser('finance')->id]);

    expect(fn () => $payment->update(['amount' => 5]))->toThrow(ValidationException::class)
        ->and(fn () => $payment->delete())->toThrow(ValidationException::class)
        ->and(fn () => $make('2026-01-20'))->toThrow(ValidationException::class);

    $make('2026-02-20');
    expect(Payment::count())->toBe(2);
});

it('allocates a loss in equal thirds, once, to the partner capital accounts', function () {
    signPolicy($this->policy);
    $finance = financeUser('finance');
    $period = AccountingPeriod::create(['month' => '2026-01', 'income' => 0, 'expenses' => 100, 'net_profit' => -100, 'status' => 'verified', 'closed_by' => $finance->id]);
    $allocation = ProfitAllocator::prepare($period, $finance);
    ProfitAllocator::approve($allocation, financeUser('board_member'));
    ProfitAllocator::post($allocation, $finance);

    $ids = Partner::orderBy('sort')->pluck('id', 'code');
    expect(Ledger::creditBalance('partner.capital', $ids['A']))->toBe(-34)
        ->and(Ledger::creditBalance('partner.capital', $ids['B']))->toBe(-33)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));
});

it('settles a partner exit once and keeps the journal balanced', function () {
    signPolicy($this->policy);
    $finance = financeUser('finance');
    $board = financeUser('board_member');
    $b = Partner::firstWhere('code', 'B');

    $capital = CapitalEntry::create(['partner_id' => $b->id, 'amount' => 30_000_000, 'paid_on' => '2026-01-01', 'created_by' => $finance->id]);
    $this->actingAs($board)->post(route('finance.capital.decide', $capital), ['decision' => 'verify']);

    $period = AccountingPeriod::create(['month' => now()->subMonth()->format('Y-m'), 'income' => 80_000_000, 'expenses' => 0,
        'net_profit' => 80_000_000, 'status' => 'verified', 'closed_by' => $finance->id]);
    Ledger::post(today()->toDateString(), 'Income', 'period', $period->id, [['cash', null, 80_000_000, 0], ['income', null, 0, 80_000_000]], $finance->id);
    $allocation = ProfitAllocator::prepare($period, $finance);
    ProfitAllocator::approve($allocation, $board);
    ProfitAllocator::post($allocation, $finance);

    Resolution::create(['type' => 'exit_request', 'title' => 'Exit', 'partner_id' => $b->id, 'status' => 'approved', 'requested_by' => $finance->id]);
    Storage::fake();

    $this->actingAs($finance)->post(route('finance.exits.store'), ['partner_id' => $b->id, 'exit_date' => now()->toDateString(),
        'previous_withdrawals' => '1000', 'other_liabilities' => '500', 'other_receivables' => '200'])->assertSessionHasNoErrors();
    $exit = ExitSettlement::first();

    // adjusted = 300,000 - 1,000; incentive = 400,000 x 30%; unpaid profit = B 15% of 800,000; +200 receivables -500 liabilities.
    expect($exit->incentive)->toBe(12_000_000)
        ->and($exit->capital_balance)->toBe(29_900_000)
        ->and($exit->net_settlement)->toBe(29_900_000 + 12_000_000 + 12_000_000 + 20_000 - 50_000);

    $this->actingAs($finance)->post(route('finance.exits.approve', $exit))->assertForbidden();
    $this->actingAs($finance)->post(route('finance.exits.pay', $exit), ['paid_on' => today()->toDateString(), 'payment_reference' => 'X'])->assertSessionHas('error');
    $this->actingAs($board)->post(route('finance.exits.approve', $exit))->assertSessionHas('error'); // no documents yet
    $this->actingAs($finance)->post(route('finance.documents.store'), ['subject_type' => 'exit', 'subject_id' => $exit->id, 'name' => 'Deed', 'file' => UploadedFile::fake()->create('deed.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
    $doc = Document::first();
    $this->actingAs($board)->post(route('finance.exits.approve', $exit));
    expect($b->fresh()->status)->toBe('exited');
    $this->actingAs($finance)->post(route('finance.exits.pay', $exit), ['paid_on' => today()->toDateString(), 'payment_reference' => 'EARLY'])->assertSessionHas('error'); // document unreviewed
    $this->actingAs($finance)->post(route('finance.documents.review', $doc), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($board)->post(route('finance.documents.review', $doc), ['decision' => 'approve']);

    $this->actingAs($finance)->post(route('finance.exits.pay', $exit), ['paid_on' => today()->toDateString(), 'payment_reference' => 'TRX-1'])->assertSessionHas('status');
    $this->actingAs($finance)->post(route('finance.exits.pay', $exit), ['paid_on' => today()->toDateString(), 'payment_reference' => 'TRX-2'])->assertSessionHas('error');

    expect($exit->fresh()->payment_status)->toBe('paid')
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'))
        ->and(Ledger::creditBalance('partner.capital', $b->id))->toBe(0)
        ->and(Ledger::creditBalance('partner.payable', $b->id))->toBe(0);
});
