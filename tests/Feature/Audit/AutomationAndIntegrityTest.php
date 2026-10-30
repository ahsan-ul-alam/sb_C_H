<?php

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\FinancialStatements;
use App\Services\StandingPolicies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** February with exactly 100,000.00 of net profit: 150,000 collected from a client, 50,000 of operations paid and verified. */
function februaryProfit(SellbeesCompany $co, string $month = '02'): void
{
    Carbon::setTestNow("2026-$month-10");
    $project = $co->project($co->client('Client '.$month), 'P'.$month);
    $seg = $co->segment($project, 'Retainer', '150000');
    $co->paySegment($seg, '150000', "2026-$month-10")->assertSessionHasNoErrors();
    $co->expense('Operations', "Operations $month", '50000', "2026-$month-15");
}

function expectedProfitAllocation(): void
{
    $A = Partner::firstWhere('code', 'A')->id;
    $B = Partner::firstWhere('code', 'B')->id;
    $C = Partner::firstWhere('code', 'C')->id;
    expect(-dr('fund.salary'))->toBe(2_000_000)->and(-dr('fund.office'))->toBe(500_000)->and(-dr('fund.emergency'))->toBe(2_500_000)
        ->and(-dr('partner.payable', $A))->toBe(2_000_000)->and(-dr('partner.payable', $B))->toBe(1_500_000)->and(-dr('partner.payable', $C))->toBe(1_500_000)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));
}

it('allocates BDT 100,000 by hand through the controllers, exactly as the approved policy says', function () {
    $co = SellbeesCompany::boot($this);
    februaryProfit($co);
    Carbon::setTestNow('2026-03-10');
    $co->post('finance', 'finance.periods.close', ['month' => '2026-02'])->assertSessionHasNoErrors();
    $period = AccountingPeriod::first();
    expect([$period->income, $period->expenses, $period->net_profit])->toBe([15_000_000, 5_000_000, 10_000_000]);
    $co->post('board', 'finance.periods.verify', [], $period);
    $co->post('finance', 'finance.periods.prepare', [], $period);
    $allocation = Allocation::first();
    $co->post('board', 'finance.allocations.approve', [], $allocation);
    $co->post('finance', 'finance.allocations.post', [], $allocation);
    expectedProfitAllocation();
    expect($allocation->refresh()->prepared_by)->not->toBeNull()->and($allocation->approved_by)->not->toBeNull();
    $co->as('board')->get(route('finance.pnl'))->assertOk()->assertSee('100,000.00')->assertSee('20,000.00')->assertSee('15,000.00');
    $co->as('board')->get(route('finance.periods.show', $period))->assertOk()->assertSee('50,000.00');
    expect(ledgerAudit())->toMatchArray(['unbalanced' => 0, 'duplicates' => 0, 'orphans' => []]);
});

it('does nothing automatic without a policy, then produces the identical result under a standing policy, once', function () {
    $co = SellbeesCompany::boot($this);
    februaryProfit($co);
    Carbon::setTestNow('2026-03-10');

    // Scenario A: no policy.
    Artisan::call('finance:process');
    expect(Artisan::output())->toContain('No standing Board policy')->and(AccountingPeriod::count())->toBe(0)->and(Allocation::count())->toBe(0);
    $co->post('finance', 'finance.expenses.store', ['category' => 'Internet', 'description' => 'Router', 'amount' => '500', 'spent_on' => '2026-03-02'])->assertSessionHasNoErrors();
    expect(Expense::latest('id')->first()->status)->toBe('pending'); // restricted postings stay restricted

    // Scenario B/F: the Board approves the policy through the real workflow. The cut-off is the month before approval, so
    // January (older) is not swept up even though it is unprocessed.
    Carbon::setTestNow('2026-02-01');
    $oldProject = $co->project($co->client('Old Client'), 'Old');
    $co->paySegment($co->segment($oldProject, 'Old retainer', '1000'), '1000', '2026-01-20');
    Carbon::setTestNow('2026-03-10');
    $co->as('finance')->post(route('finance.resolutions.store'), ['type' => 'other', 'title' => StandingPolicies::title('period')])->assertForbidden(); // Finance cannot raise it
    $policy = $co->standingPolicy('period');
    $co->as('super')->get(route('finance.automation'))->assertOk()->assertSee('Standing authority: automatic monthly close');

    Artisan::call('finance:process');
    $period = AccountingPeriod::firstWhere('month', '2026-02');
    expect(AccountingPeriod::count())->toBe(1)->and(AccountingPeriod::firstWhere('month', '2026-01'))->toBeNull()   // outside the policy's scope
        ->and($period->closed_by)->toBeNull()->and($period->verified_by)->toBeNull()->and($period->auto_policy_id)->toBe($policy->id)
        ->and($period->status)->toBe('verified')->and(Allocation::first()->status)->toBe('posted')->and(Allocation::first()->prepared_by)->toBeNull();
    expectedProfitAllocation(); // identical to the manual path's figures

    // Scenario E: repeated scheduler runs create nothing new.
    $before = [JournalEntry::count(), Allocation::count(), AccountingPeriod::count(), AuditLog::where('action', 'allocation.posted')->count(), Invoice::count()];
    foreach (range(1, 3) as $_) {
        Artisan::call('finance:process');
    }
    expect([JournalEntry::count(), Allocation::count(), AccountingPeriod::count(), AuditLog::where('action', 'allocation.posted')->count(), Invoice::count()])->toBe($before);
    expect(AuditLog::where('action', 'automation.done')->where('details', 'like', '%2026-02 profit allocated%')->count())->toBe(1);
    // Nobody is asked to approve something the system already did, but partners hear about their entitlement.
    expect(DB::table('notifications')->where('data', 'like', '%awaiting approval%')->where('data', 'like', '%allocation%')->count())->toBe(0);
    $co->as('partnerA')->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('items.0.title', 'Profit entitlement recorded');

    // Scenario D: the Board revokes it; a later month is no longer processed, history stays.
    $co->post('board', 'finance.automation.revoke', [], 'period')->assertSessionHasNoErrors();
    februaryProfit($co, '04');
    Carbon::setTestNow('2026-05-10');
    Artisan::call('finance:process');
    expect(AccountingPeriod::where('month', '2026-04')->exists())->toBeFalse()->and(AccountingPeriod::where('month', '2026-02')->exists())->toBeTrue()->and(Allocation::count())->toBe(1);
});

it('holds profit back while sourcing is unpaid, names the exact items, and releases itself when they are settled', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-05');
    $co->post('finance', 'sourcing.suppliers.store', ['name' => 'Supplier H']);
    $s = Supplier::firstWhere('name', 'Supplier H');
    $lot = $co->approvedLot('Held lot', [['supplier_id' => $s->id, 'description' => 'Stock', 'quantity' => '100', 'unit_price' => '50']]); // owes 5,000
    februaryProfit($co);
    Carbon::setTestNow('2026-03-10');
    $co->standingPolicy('period');

    Artisan::call('finance:process');
    expect(AccountingPeriod::first()->status)->toBe('verified')->and(Allocation::count())->toBe(0)->and(Artisan::output())->toContain('sourcing obligations');
    $co->as('board')->get(route('finance.automation'))->assertOk()->assertSee('Supplier H')->assertSee($lot->number)->assertSee('5,000.00')->assertSee('Supplier payable not yet paid');
    $co->as('board')->get(route('finance.periods.show', AccountingPeriod::first()))->assertOk()->assertSee('Profit is held back');
    $co->post('finance', 'finance.periods.prepare', [], AccountingPeriod::first())->assertSessionHas('error'); // the manual route is blocked the same way

    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => '5000', 'paid_on' => '2026-03-10', 'idempotency_key' => 'eeeeeeee-0000-4000-8000-000000000001'], $lot);
    Artisan::call('finance:process');
    expect(Allocation::count())->toBe(0); // paid but not yet verified: still not in the books
    $co->verifyAllExpenses();
    Artisan::call('finance:process');
    expect(Allocation::first()->status)->toBe('posted');
    expectedProfitAllocation();
});

it('verifies routine expenses only inside the policy limit, category and duplicate rules', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-03-02');
    $co->standingPolicy('expenses', '500', 'Internet, Stationery');
    $store = fn (string $cat, string $desc, string $amt, bool $asset = false) => $co->post('finance', 'finance.expenses.store',
        ['category' => $cat, 'description' => $desc, 'amount' => $amt, 'spent_on' => '2026-03-02', 'is_asset' => $asset ? 1 : 0])->assertSessionHasNoErrors();
    $last = fn () => Expense::latest('id')->first();

    $store('Internet', 'Router fee', '500');
    expect($last()->status)->toBe('verified')->and($last()->verified_by)->toBeNull()->and($last()->auto_policy_id)->not->toBeNull()->and(dr('expense'))->toBe(50_000);
    $store('Internet', 'Router fee 2', '500.01');     // above the Board's limit
    expect($last()->status)->toBe('pending');
    $store('Travel', 'Taxi', '100');                   // not an authorised category
    expect($last()->status)->toBe('pending');
    $store('Stationery', 'Printer', '300', asset: true); // capital purchase: always the Board
    expect($last()->status)->toBe('pending')->and(dr('expense'))->toBe(50_000);
    $co->as('finance')->post(route('finance.expenses.decide', $last()), ['decision' => 'verify'])->assertForbidden();
    $co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $last());
    expect(dr('assets.equipment'))->toBe(30_000)->and(dr('expense'))->toBe(50_000);
    $co->post('finance', 'finance.expenses.store', ['category' => 'Internet', 'description' => 'Router fee', 'amount' => '500', 'spent_on' => '2026-03-02'])->assertSessionHas('error'); // duplicate
});

it('rejects invalid amounts, quantities and states everywhere and leaves the books untouched', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-05');
    $co->post('finance', 'sourcing.suppliers.store', ['name' => 'Supplier Z']);
    $s = Supplier::firstWhere('name', 'Supplier Z');
    $lot = $co->approvedLot('Edge lot', [['supplier_id' => $s->id, 'description' => 'Stock', 'quantity' => '10', 'unit_price' => '5']]);
    $draft = null;
    $co->post('finance', 'sourcing.lots.store', ['title' => 'Draft lot', 'allocation_basis' => 'value']);
    $draft = SourcingLot::firstWhere('title', 'Draft lot');
    $entries = JournalEntry::count();

    foreach (['0', '-1', 'abc', '1.234'] as $bad) {
        $co->post('finance', 'sourcing.lots.lines.store', ['supplier_id' => $s->id, 'description' => 'X', 'quantity' => $bad, 'unit_price' => '5'], $draft);
        $co->post('finance', 'finance.capital.store', ['partner_id' => $co->partner('A')->id, 'amount' => $bad, 'paid_on' => '2026-02-01']);
    }
    $co->post('finance', 'sourcing.lots.costs.store', ['category' => 'x', 'amount' => '0', 'kind' => 'actual', 'incurred_on' => '2026-02-01'], $draft)->assertSessionHas('error');
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => '0', 'paid_on' => '2026-02-01'], $lot)->assertSessionHas('error');
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => '50.01', 'paid_on' => '2026-02-01'], $lot)->assertSessionHas('error');
    $co->post('finance', 'sourcing.lots.allocations.store', ['kind' => 'sale', 'project_id' => 1, 'line_id' => 1, 'quantity' => '1'], $draft)->assertStatus(302); // draft lot is not billable
    $co->post('board', 'sourcing.lots.approve', [], $draft)->assertSessionHas('error');                                                // wrong status
    $co->post('finance', 'sourcing.lots.advance', [], $draft)->assertSessionHas('error');                                              // no lines yet
    expect(JournalEntry::count())->toBe($entries)->and(CapitalEntry::count())->toBe(0)->and(SupplierPayment::count())->toBe(0)->and($draft->lines()->count())->toBe(0)->and($draft->costs()->count())->toBe(0);

    // A lot with money against it cannot be cancelled; a clean one can, and then accepts nothing.
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => '50', 'paid_on' => '2026-02-01', 'idempotency_key' => 'ffffffff-0000-4000-8000-000000000001'], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.lots.cancel', ['reason' => 'x'], $lot)->assertSessionHas('error');
    $co->post('finance', 'sourcing.lots.cancel', ['reason' => 'not needed'], $draft)->assertSessionHasNoErrors();
    expect($draft->refresh()->status)->toBe('cancelled');
    $co->post('finance', 'sourcing.lots.lines.store', ['supplier_id' => $s->id, 'description' => 'X', 'quantity' => '1', 'unit_price' => '1'], $draft)->assertSessionHas('error');

    // Month boundaries: the last and first day of a month land in the right periods; closed months are frozen.
    $co->post('finance', 'finance.expenses.store', ['category' => 'Misc', 'description' => 'end of Feb', 'amount' => '10', 'spent_on' => '2026-02-28']);
    $co->post('finance', 'finance.expenses.store', ['category' => 'Misc', 'description' => 'start of Mar', 'amount' => '20', 'spent_on' => '2026-03-01']);
    $co->verifyAllExpenses();
    expect(FinancialStatements::monthFigures('2026-02')['expenses'])->toBe(1_000 + 5_000 /* supplier payment 50.00 */)
        ->and(FinancialStatements::monthFigures('2026-03')['expenses'])->toBe(2_000);
    Carbon::setTestNow('2026-03-10');
    $co->post('finance', 'finance.periods.close', ['month' => '2026-02'])->assertSessionHasNoErrors();
    $co->post('finance', 'finance.expenses.store', ['category' => 'Misc', 'description' => 'late Feb', 'amount' => '5', 'spent_on' => '2026-02-27'])->assertSessionHas('error');
    $payment = Payment::first();
    expect($payment)->toBeNull(); // (no client payments in this scenario)
});

it('serves notifications, search, filters and pagination as the AJAX layer expects', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-05');
    $co->post('finance', 'finance.capital.store', ['partner_id' => $co->partner('A')->id, 'amount' => '1000', 'paid_on' => '2026-02-01', 'reference' => 'R1'])->assertSessionHasNoErrors();

    $feed = $co->as('board')->getJson(route('notifications.feed'))->assertOk()->assertJsonStructure(['unread', 'items' => [['id', 'title', 'read']]]);
    $id = $feed->json('items.0.id');
    expect($feed->json('unread'))->toBeGreaterThanOrEqual(1);
    $co->as('board')->postJson(route('notifications.read', $id))->assertOk()->assertJson(['ok' => true]);
    $co->as('board')->postJson(route('notifications.read-all'))->assertOk()->assertJson(['unread' => 0]);
    $co->as('board')->getJson(route('notifications.count'))->assertOk()->assertJson(['unread' => 0]);
    $co->as('auditor')->postJson(route('notifications.read', $id))->assertNotFound(); // not theirs

    foreach (range(1, 30) as $i) {
        $co->client('Search Client '.$i);
    }
    $co->as('clients')->get(route('clients.index', ['q' => 'Search Client 17']))->assertOk()->assertSee('Search Client 17')->assertDontSee('Search Client 18');
    $co->as('clients')->get(route('clients.index', ['page' => 2]))->assertOk();
    $co->as('super')->get(route('invoices.index', ['status' => 'unpaid']))->assertOk();
    $co->as('super')->get(route('payments.index', ['q' => 'none']))->assertOk();
    $co->as('super')->get(route('sourcing.lots.index', ['status' => 'draft']))->assertOk();
});
