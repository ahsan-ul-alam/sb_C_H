<?php

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/**
 * THE SELLBEES COMPANY, JANUARY–FEBRUARY 2026, run through the real routes. Every expected figure below is worked out by
 * hand in minor units (1 BDT = 100) and never taken from the application's own calculators.
 */
it('runs a two-month company through the controllers and reconciles every taka', function () {
    $co = SellbeesCompany::boot($this);
    $A = $co->partner('A')->id;
    $B = $co->partner('B')->id;
    $C = $co->partner('C')->id;

    // ───────────────────────────── January: capital, equipment, rent ─────────────────────────────
    Carbon::setTestNow('2026-01-12 10:00:00');
    $co->invest('A', '40000', '2026-01-05');
    $co->invest('B', '30000', '2026-01-05');
    $co->invest('C', '30000', '2026-01-05');

    expect(dr('partner.capital', $A))->toBe(-4_000_000)->and(dr('partner.capital', $B))->toBe(-3_000_000)->and(dr('partner.capital', $C))->toBe(-3_000_000)
        ->and(dr('cash'))->toBe(10_000_000)->and(dr('income'))->toBe(0)->and(dr('expense'))->toBe(0); // investment is not revenue or profit

    $equipment = $co->expense('Office equipment', 'Laptops and furniture', '30000', '2026-01-10', asset: true);
    $co->expense('Office rent', 'January rent', '10000', '2026-01-15');
    expect($equipment->is_asset)->toBeTrue()
        ->and(dr('cash'))->toBe(6_000_000)->and(dr('assets.equipment'))->toBe(3_000_000)->and(dr('expense'))->toBe(1_000_000);

    // Spec position after these three events: cash 60,000 · equipment 30,000 · operating loss 10,000 · equity 90,000.
    $co->as('finance')->get(route('finance.position'))->assertOk()->assertSee('60,000.00')->assertSee('30,000.00')->assertSee('90,000.00');

    // ───────────────────────────── February: clients, sourcing, spending ─────────────────────────────
    Carbon::setTestNow('2026-02-10 10:00:00');

    // Client A: four segments, every one paid directly BEFORE any invoice exists; the invoice then adds no second payment.
    $a = $co->client('Client A - New Brand');
    $pa = $co->project($a);
    $segs = [
        'Onboarding' => $co->segment($pa, 'Onboarding', '5000'), 'Branding' => $co->segment($pa, 'Branding', '8000'),
        'Product Sourcing' => $co->segment($pa, 'Product Sourcing', '12000'), 'Website Development' => $co->segment($pa, 'Website Development', '25000'),
    ];
    $co->paySegment($segs['Onboarding'], '2000', '2026-02-02')->assertSessionHasNoErrors();   // partial
    $co->paySegment($segs['Onboarding'], '3000', '2026-02-03')->assertSessionHasNoErrors();
    foreach (['Branding' => '8000', 'Product Sourcing' => '12000', 'Website Development' => '25000'] as $name => $amt) {
        $co->paySegment($segs[$name], $amt, '2026-02-04')->assertSessionHasNoErrors();
    }
    $invA = $co->invoice($pa, collect($segs)->map(fn ($s) => ['segment_id' => $s->id, 'description' => $s->name, 'quantity' => '1', 'unit_price' => number_format($s->amount / 100, 2, '.', '')])->values()->all(), '2026-02-05', 'AUD-A-1');
    expect($invA->refresh()->total)->toBe(5_000_000)->and($invA->amount_paid)->toBe(5_000_000)->and($invA->status->value)->toBe('paid')->and($invA->balance())->toBe(0)
        ->and(Payment::where('project_id', $pa->id)->sum('amount'))->toBe(5_000_000); // the invoice created no payment

    // Client B: invoice first, then payment.
    $b = $co->client('Client B - Existing Business');
    $pb = $co->project($b);
    $retainer = $co->segment($pb, 'Marketing retainer', '70000');
    $invB = $co->invoice($pb, [['segment_id' => $retainer->id, 'description' => 'Marketing retainer', 'quantity' => '1', 'unit_price' => '70000']], '2026-02-06', 'AUD-B-1');
    expect($invB->status->value)->toBe('unpaid');
    $co->payInvoice($invB, '30000', '2026-02-08')->assertSessionHasNoErrors();
    expect($invB->refresh()->amount_paid)->toBe(3_000_000)->and($invB->status->value)->toBe('partial')->and($invB->balance())->toBe(4_000_000);
    $co->payInvoice($invB, '40000.01', '2026-02-09')->assertSessionHasErrors('amount'); // overpayment refused
    $co->payInvoice($invB, '40000', '2026-02-09')->assertSessionHasNoErrors();
    expect($invB->refresh()->status->value)->toBe('paid');

    // Client C: product sourcing, Lot 1 exactly as in the brief.
    $co->post('finance', 'sourcing.suppliers.store', ['name' => 'Supplier A']);
    $supplierA = Supplier::firstWhere('name', 'Supplier A');
    $c = $co->client('Client C - Product Sourcing');
    $pc = $co->project($c);
    $lot = $co->approvedLot('Lot 1', [['supplier_id' => $supplierA->id, 'description' => 'Product A', 'quantity' => '1000', 'unit_price' => '3']], profit: '1', costs: [
        ['category' => 'Transportation', 'amount' => '500'], ['category' => 'Delivery', 'amount' => '300'], ['category' => 'Packaging', 'amount' => '700'],
        ['category' => 'Handling', 'amount' => '300'], ['category' => 'Other direct cost', 'amount' => '200'],
    ]);
    $line = $lot->lines()->first();
    expect($lot->status)->toBe('approved')->and($line->approved_price)->toBe(600) // BDT 6.00 per unit
        ->and($lot->approved_cost)->toBe(500_000)->and($lot->approved_revenue)->toBe(600_000); // cost 5,000.00, billing 6,000.00

    $co->post('finance', 'sourcing.lots.allocations.store', ['kind' => 'sale', 'project_id' => $pc->id, 'line_id' => $line->id, 'quantity' => '1000'], $lot)->assertSessionHasNoErrors();
    $alloc = $lot->allocations()->first();
    $co->post('finance', 'sourcing.allocations.bill', [], $alloc)->assertSessionHasNoErrors();
    $invC = Invoice::where('project_id', $pc->id)->firstOrFail();
    expect($invC->total)->toBe(600_000);
    $co->post('finance', 'sourcing.allocations.bill', [], $alloc)->assertSessionHas('error'); // never billed twice
    $co->payInvoice($invC, '6000', '2026-02-12')->assertSessionHasNoErrors();

    // Pay the supplier and the five lot costs for real, then run the lot to settlement.
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $supplierA->id, 'amount' => '3000', 'paid_on' => '2026-02-13', 'idempotency_key' => 'aaaaaaaa-0000-4000-8000-000000000001'], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $supplierA->id, 'amount' => '3000', 'paid_on' => '2026-02-13', 'idempotency_key' => 'aaaaaaaa-0000-4000-8000-000000000001'], $lot); // double click
    foreach ($lot->costs as $cost) {
        $co->post('finance', 'sourcing.costs.pay', ['paid_on' => '2026-02-13'], $cost)->assertSessionHasNoErrors();
    }
    expect(SupplierPayment::count())->toBe(1)->and(Expense::where('category', 'like', 'Sourcing:%')->count())->toBe(6);
    $co->post('finance', 'sourcing.lots.advance', [], $lot);
    $co->post('finance', 'sourcing.lots.advance', [], $lot);

    // Ordinary February spending: 21,000 of operating costs.
    $co->expense('Utilities & marketing', 'February operating costs', '21000', '2026-02-20');
    expect($co->verifyAllExpenses())->toBe(6); // the Board verifies the six sourcing expenses
    $co->post('finance', 'sourcing.lots.settle', [], $lot->refresh())->assertSessionHasNoErrors();
    expect($lot->refresh()->status)->toBe('settled');

    // ───────────────────────────── Month-end (March 10): close, verify, allocate, post ─────────────────────────────
    Carbon::setTestNow('2026-03-10 09:00:00');
    foreach (['2026-01', '2026-02'] as $month) {
        $co->post('finance', 'finance.periods.close', ['month' => $month])->assertSessionHasNoErrors();
        $period = AccountingPeriod::firstWhere('month', $month);
        $co->post('finance', 'finance.periods.verify', [], $period)->assertForbidden(); // verification is Board-only
        $co->post('board', 'finance.periods.verify', [], $period)->assertSessionHasNoErrors();
        $co->post('finance', 'finance.periods.prepare', [], $period)->assertSessionHasNoErrors();
        $allocation = Allocation::where('accounting_period_id', $period->id)->firstOrFail();
        $co->post('finance', 'finance.allocations.approve', [], $allocation)->assertForbidden(); // finance lacks the Board permission
        $co->post('board', 'finance.allocations.approve', [], $allocation)->assertSessionHasNoErrors();
        $co->post('finance', 'finance.allocations.post', [], $allocation)->assertSessionHasNoErrors();
        $co->post('finance', 'finance.allocations.post', [], $allocation)->assertSessionHas('error'); // posted once
    }

    $jan = AccountingPeriod::firstWhere('month', '2026-01');
    $feb = AccountingPeriod::firstWhere('month', '2026-02');
    // Independent expectations: January income 0, expenses 10,000 (equipment is an asset); February income 126,000, expenses 36... see below.
    expect([$jan->income, $jan->expenses, $jan->net_profit])->toBe([0, 1_000_000, -1_000_000]);
    // February: collected 50,000 (A) + 70,000 (B) + 6,000 (C) = 126,000; paid 3,000 + 2,000 (lot) + 21,000 = 26,000 → net 100,000.
    expect([$feb->income, $feb->expenses, $feb->net_profit])->toBe([12_600_000, 2_600_000, 10_000_000]);

    // Loss: 10,000.00 in equal thirds, remainder to the earlier partner (A 3,333.34 · B 3,333.33 · C 3,333.33).
    expect(dr('partner.capital', $A))->toBe(-4_000_000 + 333_334)->and(dr('partner.capital', $B))->toBe(-3_000_000 + 333_333)->and(dr('partner.capital', $C))->toBe(-3_000_000 + 333_333);
    // Profit 100,000.00: Salary 20% · Office 5% · Emergency 25% · Partners 50% shared 40/30/30.
    expect(-dr('fund.salary'))->toBe(2_000_000)->and(-dr('fund.office'))->toBe(500_000)->and(-dr('fund.emergency'))->toBe(2_500_000)
        ->and(-dr('partner.payable', $A))->toBe(2_000_000)->and(-dr('partner.payable', $B))->toBe(1_500_000)->and(-dr('partner.payable', $C))->toBe(1_500_000);

    // ───────────────────────────── Partner A's payout ─────────────────────────────
    $co->as('partnerA')->post(route('finance.payouts.store'), ['amount' => '20000.01'])->assertSessionHas('error'); // more than the entitlement
    $co->as('partnerA')->post(route('finance.payouts.store'), ['amount' => '10000'])->assertSessionHasNoErrors();
    $payout = Payout::firstOrFail();
    $co->as('partnerA')->post(route('finance.payouts.decide', $payout), ['decision' => 'approve'])->assertForbidden();   // no partner permission
    $co->post('finance', 'finance.payouts.pay', [], $payout)->assertSessionHas('error');                                    // not approved yet
    $co->post('board', 'finance.payouts.decide', ['decision' => 'approve'], $payout)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.payouts.pay', [], $payout)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.payouts.pay', [], $payout)->assertSessionHas('error');                                    // never paid twice
    expect(-dr('partner.payable', $A))->toBe(1_000_000);

    // ───────────────────────────── Independent reconciliation ─────────────────────────────
    $cash = 10_000_000 - 3_000_000 - 1_000_000 + 12_600_000 - 2_600_000 - 1_000_000; // capital − equipment − rent + collected − Feb spend − payout
    expect($cash)->toBe(15_000_000)->and(dr('cash'))->toBe($cash);
    $equity = 9_000_000 /* capital net of the loss */ + 5_000_000 /* funds */;
    $liabilities = 5_000_000 - 1_000_000; // partner entitlement 50,000 − payout 10,000
    $assets = $cash + 3_000_000;
    expect($assets)->toBe($liabilities + $equity + 0)->and(dr('income') + dr('expense') + dr('profit.pool') + dr('loss.pool'))->toBe(0);

    // Every journal balances, no source is posted twice, nothing is posted twice in the audit trail sense.
    $unbalanced = JournalEntry::all()->filter(fn ($e) => $e->lines()->sum('debit') !== $e->lines()->sum('credit'))->count();
    $duplicateSources = JournalEntry::selectRaw('source_type, source_id, COUNT(*) n')->groupBy('source_type', 'source_id')->get()->where('n', '>', 1)->count();
    expect($unbalanced)->toBe(0)->and($duplicateSources)->toBe(0)->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));

    // Client receivables: A, B and C are all fully paid; the app agrees.
    expect(Invoice::sum('total') - Invoice::sum('amount_paid'))->toBe(0);

    // The pages the owner reads show these same numbers.
    $co->as('board')->get(route('finance.position'))->assertOk()->assertSee('150,000.00')->assertSee('30,000.00');
    $co->as('board')->get(route('finance.pnl'))->assertOk()->assertSee('126,000.00')->assertSee('100,000.00')->assertSee('2026-02');
    $co->as('board')->get(route('finance.investment'))->assertOk()->assertSee('100,000.00')->assertSee('10,000.00');
    $co->as('board')->get(route('finance.dashboard'))->assertOk()->assertSee('150,000.00');
    expect(AuditLog::where('action', 'allocation.posted')->count())->toBe(2)->and(AuditLog::where('action', 'payout.paid')->count())->toBe(1);
    expect(ledgerAudit())->toMatchArray(['unbalanced' => 0, 'duplicates' => 0, 'orphans' => []])->and(ledgerAudit()['entries'])->toBe(16); // 3 capital + 9 expenses + 1 income posting + 2 allocations + 1 payout
});
