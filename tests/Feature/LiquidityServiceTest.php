<?php

use App\Models\FinancialAccount;
use App\Models\Payout;
use App\Services\AccountingService;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\LiquidityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| The liquidity model (disabled by default). Amounts are integer paisa. Each obligation must appear in exactly one deduction, and reserved
| funds, which are earmarked profit and not liabilities, are kept apart from liabilities.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->co->invest('A', '1000000', '2026-11-02'); // 100,000,000 of cash
});

afterEach(fn () => Carbon::setTestNow());

/** One balanced entry that credits $account and debits an expense (the other side does not matter to liquidity). */
function lqCredit(string $account, int $amount, ?int $partnerId = null): void
{
    static $n = 0;
    Ledger::post('2026-11-05', 'Liquidity test', 'test', ++$n, [['expense', null, $amount, 0], [$account, $partnerId, 0, $amount]], test()->finance->id);
}

it('is off by default: the existing position carries no liquidity figures and free cash is unchanged', function () {
    expect(config('finance.liquidity_model'))->toBeFalse()->and(LiquidityService::enabled())->toBeFalse()
        ->and(FinancialStatements::position()['liquidity'])->toBeNull();
});

it('deducts each obligation once and keeps reserves apart from liabilities', function () {
    $partner = $this->co->partner('A');
    $cash = AccountingService::totalCash();
    lqCredit('liabilities', 3_000_000);          // a supplier / bill owed
    lqCredit('customer.advances', 2_000_000);    // customers' money not yet earned
    lqCredit('partner.payable', 5_000_000, $partner->id);
    lqCredit('fund.salary', 4_000_000);          // earmarked profit, not a debt
    lqCredit('fund.emergency', 1_000_000);
    // An approved payout is a claim on the partner entitlement already deducted: it must not be deducted again.
    Payout::create(['partner_id' => $partner->id, 'amount' => 2_000_000, 'requested_by' => $this->finance->id, 'status' => 'approved', 'approved_by' => $this->co->users['board']->id]);

    $r = LiquidityService::report();

    expect($r['ledger_cash'])->toBe($cash)
        ->and($r['recognised_liabilities_total'])->toBe(5_000_000)->and($r['recognised_liabilities']['liabilities'])->toBe(3_000_000)
        ->and($r['partner_entitlements'])->toBe(5_000_000)->and($r['approved_payouts_unpaid'])->toBe(2_000_000)
        ->and($r['reserved_funds_total'])->toBe(5_000_000)->and($r['commitments_total'])->toBe(0)
        ->and($r['available_operational_cash'])->toBe($cash - 5_000_000 - 5_000_000 - 5_000_000)->and($r['shortfall'])->toBeFalse();
    // Every ledger account sits in exactly one group.
    $groups = [LiquidityService::LIABILITY_ACCOUNTS, LiquidityService::RESERVE_ACCOUNTS, ['partner.payable'], ['cash']];
    expect(collect($groups)->flatten()->duplicates()->all())->toBe([]);
});

it('shows a shortfall when more is promised than the business holds, and adds the flag only when switched on', function () {
    lqCredit('liabilities', AccountingService::totalCash() + 1_000_000);

    expect(LiquidityService::report())->toMatchArray(['shortfall' => true]);
    expect(LiquidityService::report()['available_operational_cash'])->toBe(-1_000_000);

    config(['finance.liquidity_model' => true]);
    $position = FinancialStatements::position();
    expect($position['liquidity']['available_operational_cash'])->toBe(-1_000_000)
        ->and($position['free_cash'])->toBe($position['cash'] - $position['committed']); // the existing figure is untouched
});

it('never counts a sourcing payable the ledger already carries a second time', function () {
    config(['finance.sourcing_basis' => 'accrual', 'finance.sourcing_accrual_from' => '2026-11-01']);
    // A recognised supplier payable of 15,000 sits in `liabilities`; the not-in-ledger commitments must not repeat it.
    lqCredit('liabilities', 1_500_000);

    $r = LiquidityService::report();

    expect($r['recognised_liabilities']['liabilities'])->toBe(1_500_000)->and($r['commitments']['sourcing_not_in_ledger'])->toBe(0)
        ->and($r['available_operational_cash'])->toBe($r['ledger_cash'] - 1_500_000);
});
