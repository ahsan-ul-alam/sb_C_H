<?php

use App\Models\AccountingPeriod;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\PolicyVersion;
use App\Services\InvestorReporting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** Net 100,000 for February (150,000 earned, 50,000 spent), posted and allocated under the standing approved policy. */
function postedFebruary(SellbeesCompany $co): void
{
    Carbon::setTestNow('2026-02-10');
    $co->paySegment($co->segment($co->project($co->client('Client D')), 'Retainer', '150000'), '150000', '2026-02-10');
    $co->expense('Operations', 'February operations', '50000', '2026-02-15');
    Carbon::setTestNow('2026-03-10');
    $co->post('finance', 'finance.periods.close', ['month' => '2026-02'])->assertSessionHasNoErrors();
    $period = AccountingPeriod::firstWhere('month', '2026-02');
    $co->post('board', 'finance.periods.verify', [], $period)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.periods.prepare', [], $period)->assertSessionHasNoErrors();
    $allocation = $period->allocation()->firstOrFail();
    $co->post('board', 'finance.allocations.approve', [], $allocation)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.allocations.post', [], $allocation)->assertSessionHasNoErrors();
}

it('shows allocated, paid and outstanding separately and moves cash only on a verified payout', function () {
    $co = SellbeesCompany::boot($this);
    postedFebruary($co);
    $a = $co->partner('A');
    $cashBeforePayout = dr('cash');

    $report = InvestorReporting::forPartner($a);
    expect($report['profit']['allocated'])->toBe(2_000_000)->and($report['profit']['paid'])->toBe(0)->and($report['profit']['outstanding'])->toBe(2_000_000)
        ->and($report['profit']['share']['state'])->toBe('Approved')->and($report['loan_recovery']['allocated'])->toBe(0);

    $co->as('partnerA')->post(route('finance.payouts.store'), ['submission_key' => (string) Str::uuid(), 'amount' => '10000'])->assertSessionHasNoErrors();
    $payout = Payout::firstOrFail();
    $co->post('board', 'finance.payouts.decide', ['decision' => 'approve'], $payout)->assertSessionHasNoErrors();
    expect(dr('cash'))->toBe($cashBeforePayout); // an approval moves no money
    $co->post('finance', 'finance.payouts.pay', [], $payout)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.payouts.pay', [], $payout)->assertSessionHas('error');

    $report = InvestorReporting::forPartner($a);
    $row = $report['profit']['history']->first();
    expect(dr('cash'))->toBe($cashBeforePayout - 1_000_000)
        ->and($report['profit'])->toMatchArray(['allocated' => 2_000_000, 'paid' => 1_000_000, 'outstanding' => 1_000_000])
        ->and($row)->toMatchArray(['month' => '2026-02', 'allocated' => 2_000_000, 'paid' => 1_000_000, 'outstanding' => 1_000_000]);

    $all = InvestorReporting::distribution();
    expect($all['total']['allocated'])->toBe(5_000_000)->and($all['total']['paid'])->toBe(1_000_000)->and($all['total']['outstanding'])->toBe(4_000_000)
        ->and($all['periods'][0])->toMatchArray(['net_profit' => 10_000_000, 'income' => 15_000_000, 'expenses' => 5_000_000]);
    expect(InvestorReporting::distribution('2026-03')['periods'])->toBe([]);

    $co->as('finance')->get(route('finance.partners.show', $a))->assertOk()->assertSee('Loan recovery tracking')->assertSee('Outstanding payable')->assertSee('10,000.00');
    $co->as('finance')->get(route('finance.investment'))->assertOk()->assertSee('Profit distribution by period');
});

it('shows Pending Approval and no ratio when the policy ratio is not approved and confirmed', function () {
    $co = SellbeesCompany::boot($this);
    $draft = new PolicyVersion(['name' => 'Draft policy', 'profit_bps' => ['A' => 5000]]);
    expect(InvestorReporting::approvedShare($co->partner('A'), $draft))->toBe(['state' => 'Pending Approval', 'bps' => null, 'policy' => 'Draft policy'])
        ->and(InvestorReporting::approvedShare($co->partner('A'), null)['state'])->toBe('Pending Approval')
        ->and(Partner::count())->toBeGreaterThan(0);
});
