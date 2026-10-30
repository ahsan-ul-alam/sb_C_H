<?php

use App\Models\AccountingPeriod;
use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\LotFinancials;
use App\Services\OrderCommission;
use App\Services\ProfitAllocator;
use App\Services\Sourcing;
use App\Services\SourcingPayments;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['admin.email' => 'admin@fs.test', 'admin.password' => 'password-123']);
    $this->seed(DatabaseSeeder::class);
    PolicyVersion::first()->update(['effective_from' => '2020-01-01', 'approved_at' => now(), 'signed_reference' => 'R1', 'approved_by' => fsUser('board_member')->id,
        'profit_confirmed_at' => now(), 'profit_signed_reference' => 'R2']);
});

function fsUser(string $role): User
{
    $user = User::firstOrCreate(['email' => $role.'@fs.test'], ['name' => $role, 'password' => 'password-123']);
    if ($user->wasRecentlyCreated) {
        $user->setRoles([$role]);
    }

    return $user;
}

function fsInvest(string $code, int $amount, int $sourceId): void
{
    Ledger::post('2026-01-01', 'capital', 'capital', $sourceId, [['cash', null, $amount, 0], ['partner.capital', Partner::firstWhere('code', $code)->id, 0, $amount]], null);
}

it('shows each partner against the 40/30/30 commitment with the gap to target', function () {
    fsInvest('A', 200000, 1);
    fsInvest('B', 300000, 2);
    fsInvest('C', 300000, 3);

    $rows = FinancialStatements::capital()['rows']->keyBy(fn ($r) => $r['partner']->code);
    expect($rows['A']['actual_bps'])->toBe(2500)->and($rows['A']['target_amount'])->toBe(320000)->and($rows['A']['gap'])->toBe(120000)
        ->and($rows['B']['gap'])->toBe(-60000)->and($rows['C']['gap'])->toBe(-60000);
});

it('splits a profit into 20/5/25 and a 50% partner pool shared 40/30/30, and balances the position', function () {
    $finance = fsUser('finance');
    Carbon::setTestNow('2026-06-15');
    $period = AccountingPeriod::create(['month' => '2026-03', 'income' => 100000, 'expenses' => 0, 'net_profit' => 100000, 'status' => 'verified', 'closed_by' => $finance->id]);
    $allocation = ProfitAllocator::prepare($period, $finance);
    ProfitAllocator::approve($allocation, fsUser('board_member'));
    ProfitAllocator::post($allocation, $finance);

    $row = collect(FinancialStatements::profitAndLoss())->firstWhere('month', '2026-03');
    expect($row['salary'])->toBe(20000)->and($row['office'])->toBe(5000)->and($row['emergency'])->toBe(25000)->and($row['partner_pool'])->toBe(50000)
        ->and($row['partners'])->toBe(['A' => 20000, 'B' => 15000, 'C' => 15000])->and($row['cumulative'])->toBe(100000);

    $position = FinancialStatements::position();
    expect($position['balanced'])->toBeTrue();
    $this->actingAs($finance);
    foreach (['finance.investment', 'finance.position', 'finance.pnl', 'finance.orders'] as $route) {
        $this->get(route($route))->assertOk();
    }
    $this->actingAs(fsUser('employee'))->get(route('finance.pnl'))->assertForbidden();
    Carbon::setTestNow();
});

it('corrects a verified supplier payment through a Board-verified reversing expense, once', function () {
    $finance = fsUser('finance');
    $board = fsUser('board_member');
    $lot = Sourcing::createLot(['title' => 'Correction'], $finance);
    $supplier = Supplier::create(['name' => 'S']);
    Sourcing::addLine($lot, ['supplier_id' => $supplier->id, 'description' => 'W', 'quantity' => '10', 'unit_price' => 500], $finance);
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $supplier->id, 'amount' => 5000, 'paid_on' => today()->toDateString()], $finance);
    $this->actingAs($board)->post(route('finance.expenses.decide', $payment->expense_id), ['decision' => 'verify']);
    expect(Ledger::debitBalance('expense'))->toBe(5000);

    SourcingPayments::voidSupplierPayment($payment, 'wrong supplier', $finance);
    $reversal = Expense::whereNotNull('reverses_id')->first();
    expect($payment->refresh()->isEffective())->toBeTrue() // still counted until the Board verifies the correction
        ->and($reversal->status)->toBe('pending')->and(Ledger::debitBalance('expense'))->toBe(5000);
    expect(fn () => SourcingPayments::voidSupplierPayment($payment, 'again', $finance))->toThrow(RuntimeException::class, 'already awaiting');

    $this->actingAs($board)->post(route('finance.expenses.decide', $reversal), ['decision' => 'verify']);
    expect($payment->refresh()->isEffective())->toBeFalse()->and(Ledger::debitBalance('expense'))->toBe(0)->and(Ledger::debitBalance('cash'))->toBe(0)
        ->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(5000)
        ->and(FinancialStatements::monthFigures(today()->format('Y-m'))['expenses'])->toBe(0)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));
});

it('bills order commission once at a snapshotted rate without posting any revenue itself', function () {
    $finance = fsUser('finance');
    $client = Client::create(['name' => 'Shop', 'mobile' => '0170']);
    $project = $client->projects()->create(['name' => 'Store', 'status' => 'active', 'currency' => 'BDT', 'started_on' => today()]);

    $order = OrderCommission::record($project, ['reference' => 'ORD-1', 'ordered_on' => today()->toDateString(), 'net_value' => 1000000], $finance);
    OrderCommission::markSuccessful($order);
    expect($order->refresh()->commission)->toBe(0); // default rate is 0 until Finance sets it: nothing is charged silently

    OrderCommission::setDefaultRate(100); // 1%
    $second = OrderCommission::record($project, ['reference' => 'ORD-2', 'ordered_on' => today()->toDateString(), 'net_value' => 1000000], $finance);
    OrderCommission::markSuccessful($second);
    expect($second->refresh()->commission)->toBe(10000)->and($second->rate_bps)->toBe(100);

    OrderCommission::setClientRate($client, 250);
    OrderCommission::setDefaultRate(500);
    expect($second->refresh()->commission)->toBe(10000); // earlier order keeps its snapshot
    $third = OrderCommission::record($project, ['reference' => 'ORD-3', 'ordered_on' => today()->toDateString(), 'net_value' => 1000000], $finance);
    OrderCommission::markSuccessful($third);
    expect($third->refresh()->commission)->toBe(25000); // client override wins

    OrderCommission::adjustNet($third, 400000); // partial refund before billing
    expect($third->refresh()->commission)->toBe(10000);

    $invoice = OrderCommission::bill($second, null, null);
    expect($invoice->total)->toBe(10000)->and($invoice->items()->first()->description)->toBe('Commission on order ORD-2')
        ->and(JournalLine::count())->toBe(0); // revenue arrives only when ClientHub records the payment
    expect(fn () => OrderCommission::bill($second->refresh(), null, null))->toThrow(RuntimeException::class);
    expect(fn () => OrderCommission::cancel($second->refresh()))->toThrow(RuntimeException::class, 'already invoiced');

    OrderCommission::cancel($third->refresh());
    expect($third->refresh()->commission)->toBe(0)->and(ClientOrder::where('status', 'cancelled')->count())->toBe(1);
    expect(fn () => OrderCommission::record($project, ['reference' => 'ORD-2', 'ordered_on' => today()->toDateString(), 'net_value' => 5], $finance))->toThrow(RuntimeException::class, 'already recorded');
});

it('limits commission rate changes to Finance while client management can record orders', function () {
    $this->actingAs(fsUser('client_management'))->post(route('finance.orders.rates'), ['default_percent' => '1'])->assertForbidden();
    $this->actingAs(fsUser('auditor'))->get(route('finance.orders'))->assertOk();
    $this->actingAs(fsUser('auditor'))->post(route('finance.orders.store'), [])->assertForbidden();
    $this->actingAs(fsUser('finance'))->post(route('finance.orders.rates'), ['default_percent' => '1'])->assertRedirect();
    expect(BusinessSetting::current()->commission_bps)->toBe(100);
});
