<?php

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\ClientOrder;
use App\Models\EmergencyFundRequest;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payout;
use App\Models\Project;
use App\Models\Resolution;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Services\FinancialStatements;
use App\Services\LotFinancials;
use App\Services\SourcingObligations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/**
 * A February with exactly one loss-making lot: it sells for 10,000, costs 9,500 plus an unexpected 800 (all paid in February),
 * so the company's February result is a 300 loss. Optionally $coverage BDT of Emergency coverage is requested, approved and utilised.
 *
 * @return array{lot: SourcingLot, request: ?EmergencyFundRequest}
 */
function lossFebruary(SellbeesCompany $co, string $coverage = '0', string $extraIncome = '0'): array
{
    Carbon::setTestNow('2026-02-10');
    $co->post('finance', 'sourcing.suppliers.store', ['name' => 'Supplier L']);
    $s = Supplier::firstWhere('name', 'Supplier L');
    $co->invest('A', '40000', '2026-01-05');
    $co->invest('B', '30000', '2026-01-05');
    $co->invest('C', '30000', '2026-01-05');
    $co->seedReserve(10_000_000);
    $project = $co->project($co->client('Client L'));
    $lot = $co->approvedLot('Loss lot', [['supplier_id' => $s->id, 'description' => 'W', 'quantity' => '1', 'unit_price' => '9500']], profit: '500');
    $co->post('finance', 'sourcing.lots.allocations.store', ['kind' => 'sale', 'project_id' => $project->id, 'line_id' => $lot->lines()->first()->id, 'quantity' => '1'], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.allocations.bill', [], $lot->allocations()->first())->assertSessionHasNoErrors();
    $co->payInvoice(Invoice::firstOrFail(), '10000', '2026-02-10')->assertSessionHasNoErrors();
    if ($extraIncome !== '0') {
        $seg = $co->segment($project, 'Extra work', $extraIncome);
        $co->paySegment($seg, $extraIncome, '2026-02-11')->assertSessionHasNoErrors();
    }
    $co->post('finance', 'sourcing.lots.costs.store', ['category' => 'Customs', 'amount' => '800', 'kind' => 'actual', 'incurred_on' => '2026-02-11'], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => '9500', 'paid_on' => '2026-02-12', 'idempotency_key' => 'dddddddd-0000-4000-8000-000000000001'], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.costs.pay', ['paid_on' => '2026-02-12'], $lot->costs()->first())->assertSessionHasNoErrors();
    $co->verifyAllExpenses();

    $request = null;
    if ($coverage !== '0') {
        $co->post('finance', 'sourcing.lots.fund.store', ['amount' => $coverage, 'reason' => 'unexpected customs'], $lot)->assertSessionHasNoErrors();
        $request = EmergencyFundRequest::latest('id')->firstOrFail();
        $co->post('board', 'sourcing.fund.decide', ['decision' => 'approve'], $request)->assertSessionHasNoErrors();
        $co->post('finance', 'sourcing.fund.utilise', [], $request)->assertSessionHasNoErrors();
    }

    return ['lot' => $lot->refresh(), 'request' => $request];
}

function monthEnd(SellbeesCompany $co, string $month, string $today): Allocation
{
    Carbon::setTestNow($today);
    $co->post('finance', 'finance.periods.close', ['month' => $month])->assertSessionHasNoErrors();
    $period = AccountingPeriod::firstWhere('month', $month);
    $co->post('board', 'finance.periods.verify', [], $period)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.periods.prepare', [], $period)->assertSessionHasNoErrors();
    $allocation = Allocation::where('accounting_period_id', $period->id)->firstOrFail();
    $co->post('board', 'finance.allocations.approve', [], $allocation)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.allocations.post', [], $allocation)->assertSessionHasNoErrors();

    return $allocation->refresh();
}

it('charges partners the whole loss when no Board policy lets coverage offset it, and shows the held coverage', function () {
    $co = SellbeesCompany::boot($this);
    ['lot' => $lot] = lossFebruary($co, coverage: '300');
    $allocation = monthEnd($co, '2026-02', '2026-03-10');

    expect(AccountingPeriod::first()->net_profit)->toBe(-30_000)
        ->and($allocation->lines->where('account', 'partner.capital')->sum('amount'))->toBe(30_000)  // BDT 300 split three ways
        ->and($allocation->lines->where('account', 'fund.coverage')->count())->toBe(0)
        ->and(dr('fund.coverage'))->toBe(-30_000)                                                    // coverage stays held, visible
        ->and(dr('partner.capital', $co->partner('A')->id))->toBe(-4_000_000 + 10_000);
    $co->as('board')->get(route('finance.investment'))->assertOk()->assertSee('300.00');
});

it('applies approved coverage to the partner loss only after a Board-approved resolution, leaving the accounting loss intact', function () {
    $co = SellbeesCompany::boot($this);
    $co->standingPolicy('commission'); // unrelated policy: must not enable loss offsetting
    ['lot' => $lot] = lossFebruary($co, coverage: '300');
    // The coverage-offset policy is its own Board resolution.
    $co->post('super', 'finance.resolutions.store', ['type' => 'other', 'title' => SourcingObligations::COVERAGE_RESOLUTION])->assertSessionHasNoErrors();
    $co->post('board', 'finance.resolutions.decide', ['decision' => 'approve'], Resolution::where('title', SourcingObligations::COVERAGE_RESOLUTION)->first())->assertSessionHasNoErrors();
    $allocation = monthEnd($co, '2026-02', '2026-03-10');

    expect($allocation->lines->where('account', 'partner.capital')->sum('amount'))->toBe(0)         // Scenario A: remaining partner burden = 0
        ->and($allocation->lines->where('account', 'fund.coverage')->sum('amount'))->toBe(30_000)
        ->and(AccountingPeriod::first()->net_profit)->toBe(-30_000)                                   // the loss is still BDT 300
        ->and(LotFinancials::for($lot->refresh())['profit'])->toBe(-30_000)
        ->and(dr('fund.coverage'))->toBe(0)->and(dr('fund.emergency'))->toBe(-10_000_000 + 30_000)   // reserve reduced once
        ->and(dr('income'))->toBe(-1_000_000)->and(dr('expense'))->toBe(1_030_000);                   // coverage is neither revenue nor an extra expense
    // Scenario F: coverage already applied cannot be reversed.
    $co->post('finance', 'sourcing.fund.reverse', ['reason' => 'undo'], EmergencyFundRequest::first())->assertSessionHas('error');
    expect(dr('fund.emergency'))->toBe(-10_000_000 + 30_000);
});

it('shares only the uncovered part of a loss equally, with the remainder going to the first partner', function () {
    $co = SellbeesCompany::boot($this);
    lossFebruary($co, coverage: '200'); // BDT 200 of the 300 loss covered
    $co->post('super', 'finance.resolutions.store', ['type' => 'other', 'title' => SourcingObligations::COVERAGE_RESOLUTION]);
    $co->post('board', 'finance.resolutions.decide', ['decision' => 'approve'], Resolution::where('title', SourcingObligations::COVERAGE_RESOLUTION)->first());
    $allocation = monthEnd($co, '2026-02', '2026-03-10');

    $charge = fn (string $code) => $allocation->lines->where('account', 'partner.capital')->firstWhere('partner_id', $co->partner($code)->id)->amount;
    // 100.00 uncovered = 10,000 minor: A 3,334 · B 3,333 · C 3,333. (The brief expected the spare 0.01 to go to C; the approved
    // policy, covered by existing tests, gives the remainder to the earlier partner. Reported as a decision, not changed.)
    expect([$charge('A'), $charge('B'), $charge('C')])->toBe([3_334, 3_333, 3_333])->and($allocation->lines->where('account', 'fund.coverage')->sum('amount'))->toBe(20_000);
});

it('does not use Emergency money that was requested but never approved', function () {
    $co = SellbeesCompany::boot($this);
    ['lot' => $lot] = lossFebruary($co);
    $co->post('finance', 'sourcing.lots.fund.store', ['amount' => '300', 'reason' => 'asking'], $lot)->assertSessionHasNoErrors();
    $allocation = monthEnd($co, '2026-02', '2026-03-10');
    expect($allocation->lines->where('account', 'fund.coverage')->count())->toBe(0)->and(dr('fund.emergency'))->toBe(-10_000_000)
        ->and(EmergencyFundRequest::first()->status)->toBe('pending');
});

it('releases coverage nobody can claim, only under the release policy, without touching recognised profit', function () {
    $co = SellbeesCompany::boot($this);
    // Same loss lot, but other client work makes the company's February profitable → the coverage is stranded.
    ['request' => $request] = lossFebruary($co, coverage: '300', extraIncome: '50000');
    Carbon::setTestNow('2026-03-10');
    $co->post('finance', 'finance.periods.close', ['month' => '2026-02'])->assertSessionHasNoErrors();
    $co->post('board', 'finance.periods.verify', [], AccountingPeriod::first())->assertSessionHasNoErrors();
    expect(AccountingPeriod::first()->net_profit)->toBe(4_970_000); // 10,000 + 50,000 − 10,300 = 49,700

    $income = dr('income');
    $expense = dr('expense');
    Artisan::call('finance:process');                       // no release policy: nothing moves
    expect($request->refresh()->status)->toBe('utilised')->and(dr('fund.coverage'))->toBe(-30_000);
    $co->as('board')->get(route('finance.automation'))->assertOk()->assertSee('Emergency coverage waiting for a decision');

    $co->standingPolicy('release');
    Artisan::call('finance:process');
    expect($request->refresh()->status)->toBe('reversed')->and($request->reversed_by)->toBeNull()
        ->and(dr('fund.coverage'))->toBe(0)->and(dr('fund.emergency'))->toBe(-10_000_000)
        ->and(dr('income'))->toBe($income)->and(dr('expense'))->toBe($expense)->and(AccountingPeriod::first()->net_profit)->toBe(4_970_000);
    $entries = JournalEntry::count();
    Artisan::call('finance:process');
    expect(JournalEntry::count())->toBe($entries); // running again changes nothing
});

it('rejects every invalid payout and reconciles the valid one', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $project = $co->project($co->client('Client Q'));
    $seg = $co->segment($project, 'Retainer', '150000');
    $co->paySegment($seg, '150000', '2026-02-10');
    $co->expense('Operations', 'February operations', '50000', '2026-02-15');
    monthEnd($co, '2026-02', '2026-03-10'); // net 100,000 → Partner A 20,000
    $cash = dr('cash');
    expect(-dr('partner.payable', $co->partner('A')->id))->toBe(2_000_000);

    $request = fn (string $who, string $amount) => $co->as($who)->post(route('finance.payouts.store'), ['amount' => $amount]);
    $request('employee', '100')->assertForbidden();
    $request('finance', '100')->assertForbidden();           // not a partner
    $request('partnerA', '0')->assertSessionHas('error');
    $request('partnerA', '20000.01')->assertSessionHas('error');
    $request('partnerA', '15000')->assertSessionHasNoErrors();
    $request('partnerA', '5000.01')->assertSessionHas('error');  // would exceed what is left after the open request
    expect(Payout::count())->toBe(1);

    $payout = Payout::first();
    $co->as('partnerA')->post(route('finance.payouts.decide', $payout), ['decision' => 'approve'])->assertForbidden();
    $co->post('finance', 'finance.payouts.decide', ['decision' => 'approve'], $payout)->assertForbidden();
    $co->post('finance', 'finance.payouts.pay', [], $payout)->assertSessionHas('error');   // payout before approval
    expect(dr('cash'))->toBe($cash);
    $co->post('board', 'finance.payouts.decide', ['decision' => 'approve'], $payout)->assertSessionHasNoErrors();
    $co->as('partnerA')->post(route('finance.payouts.pay', $payout))->assertForbidden();
    $co->post('finance', 'finance.payouts.pay', [], $payout)->assertSessionHasNoErrors();
    $co->post('finance', 'finance.payouts.pay', [], $payout)->assertSessionHas('error');   // no second payment
    expect(dr('cash'))->toBe($cash - 1_500_000)->and(-dr('partner.payable', $co->partner('A')->id))->toBe(500_000)
        ->and(-dr('partner.payable', $co->partner('B')->id))->toBe(1_500_000)->and(Payout::where('status', 'paid')->count())->toBe(1);
    $co->as('partnerA')->get(route('finance.me'))->assertOk()->assertSee('5,000.00');
    $co->as('partnerB')->get(route('finance.payouts'))->assertOk();
});

it('computes order commission with snapshots, refunds, policy invoicing and safe corrections', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-10');
    $co->as('clients')->post(route('finance.orders.rates'), ['default_percent' => '1'])->assertForbidden(); // only Finance sets rates
    $co->as('auditor')->get(route('finance.orders'))->assertOk();
    $co->post('auditor', 'finance.orders.store', [])->assertForbidden();
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1'])->assertSessionHasNoErrors();

    $client = $co->client('Shop X');
    $project = $co->project($client);
    $order = fn (string $ref, string $net) => tap($co->post('clients', 'finance.orders.store', ['project_id' => $project->id, 'reference' => $ref, 'ordered_on' => '2026-02-10', 'net_value' => $net])
        ->assertSessionHasNoErrors() && true, fn () => null) ? ClientOrder::where('reference', $ref)->firstOrFail() : null;

    $ok = $order('ORD-OK', '350');
    $co->post('clients', 'finance.orders.success', [], $ok);
    expect($ok->refresh()->commission)->toBe(350)->and($ok->rate_bps)->toBe(100);              // BDT 350 × 1% = BDT 3.50

    $pending = $order('ORD-PENDING', '1000');
    $failed = $order('ORD-FAILED', '1000');
    $co->post('clients', 'finance.orders.cancel', [], $failed);
    $refunded = $order('ORD-REFUNDED', '1000');
    $co->post('clients', 'finance.orders.success', [], $refunded);
    $co->post('clients', 'finance.orders.cancel', [], $refunded);                                 // full refund before invoicing
    $partial = $order('ORD-PARTIAL', '1000');
    $co->post('clients', 'finance.orders.success', [], $partial);
    $co->post('clients', 'finance.orders.adjust', ['net_value' => '400'], $partial);              // 600 refunded
    expect($pending->refresh()->commission)->toBe(0)->and($failed->refresh()->commission)->toBe(0)->and($refunded->refresh()->commission)->toBe(0)
        ->and($partial->refresh()->commission)->toBe(400)->and($partial->net_value)->toBe(40_000);
    $co->post('clients', 'finance.orders.success', [], $failed)->assertSessionHas('error');       // a cancelled order cannot become successful

    // A default-rate change and a client override never touch history.
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '2'])->assertSessionHasNoErrors();
    $later = $order('ORD-LATER', '1000');
    $co->post('clients', 'finance.orders.success', [], $later);
    expect($ok->refresh()->commission)->toBe(350)->and($later->refresh()->commission)->toBe(2_000);
    $co->post('finance', 'finance.orders.rates', ['client_id' => $client->id, 'client_percent' => '5'])->assertSessionHasNoErrors();
    $override = $order('ORD-OVERRIDE', '1000');
    $co->post('clients', 'finance.orders.success', [], $override);
    expect($override->refresh()->commission)->toBe(5_000)->and($later->refresh()->commission)->toBe(2_000);

    // Invoice by hand, correct it while unpaid, then freeze it once money arrives.
    $co->post('clients', 'finance.orders.bill', [], $ok)->assertSessionHasNoErrors();
    $co->post('clients', 'finance.orders.bill', [], $ok)->assertSessionHas('error');
    $invoice = $ok->refresh()->invoice;
    expect($invoice->total)->toBe(350);
    $co->post('clients', 'finance.orders.reduce', ['net_value' => '100', 'reason' => 'partial refund'], $ok)->assertSessionHasNoErrors();
    expect($invoice->refresh()->total)->toBe(100)->and($ok->refresh()->commission)->toBe(100);
    $co->payInvoice($invoice, '0.50', '2026-02-12')->assertSessionHasNoErrors();
    $co->post('clients', 'finance.orders.reduce', ['reason' => 'cancel'], $ok)->assertSessionHas('error'); // money received: not rewritten
    expect($invoice->refresh()->total)->toBe(100)->and(AuditLog::where('action', 'order.invoiced_commission_reduced')->count())->toBe(1);

    // Policy invoicing + revenue only on collection (no revenue from an unpaid commission invoice).
    $co->standingPolicy('commission');
    $auto = $order('ORD-AUTO', '10000');
    $co->post('clients', 'finance.orders.success', [], $auto);
    $autoInvoice = $auto->refresh()->invoice;
    expect($autoInvoice)->not->toBeNull()->and($autoInvoice->total)->toBe(50_000)->and(dr('income'))->toBe(0);
    Carbon::setTestNow('2026-03-10');
    expect(FinancialStatements::monthFigures('2026-02')['income'])->toBe(50); // only the 0.50 actually received
});

it('does not turn a client reimbursement plus Emergency coverage into profit or duplicate funding', function () {
    $co = SellbeesCompany::boot($this);
    ['lot' => $lot, 'request' => $request] = lossFebruary($co, coverage: '300');
    $project = Project::first();

    // The client agrees to reimburse the whole 300 overrun; it is billed through the normal invoice.
    $co->post('finance', 'sourcing.lots.allocations.store', ['kind' => 'recovery', 'project_id' => $project->id, 'unit_price' => '300', 'description' => 'Customs reimbursement'], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.allocations.bill', [], $lot->allocations()->where('kind', 'recovery')->first())->assertSessionHasNoErrors();
    $f = LotFinancials::for($lot->refresh());
    expect($f['profit'])->toBe(0)->and($f['uncovered_loss'])->toBe(0)->and($f['fund_excess'])->toBe(30_000)  // coverage now exceeds the loss: flagged, not hidden
        ->and($f['recoveries'])->toBe(30_000)->and(dr('income'))->toBe(0);                                  // a billed reimbursement is not income until it is paid
    $co->post('finance', 'sourcing.lots.fund.store', ['amount' => '1', 'reason' => 'more'], $lot)->assertSessionHas('error'); // no further coverage on a covered lot

    // Finance returns the unneeded coverage; nothing in the lot result moves.
    $co->post('finance', 'sourcing.fund.reverse', ['reason' => 'client reimbursed'], $request)->assertSessionHasNoErrors();
    expect(dr('fund.emergency'))->toBe(-10_000_000)->and(dr('fund.coverage'))->toBe(0)->and(LotFinancials::for($lot->refresh())['profit'])->toBe(0);
});
