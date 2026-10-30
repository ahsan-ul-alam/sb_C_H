<?php

use App\Models\EmergencyFundRequest;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Services\LotFinancials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function supplier(SellbeesCompany $co, string $name): Supplier
{
    $co->post('finance', 'sourcing.suppliers.store', ['name' => $name])->assertSessionHasNoErrors();

    return Supplier::where('name', $name)->firstOrFail();
}

function product(SellbeesCompany $co, Supplier $s, string $name, string $price): SupplierProduct
{
    $co->post('finance', 'sourcing.products.store', ['supplier_id' => $s->id, 'name' => $name, 'unit' => 'pcs', 'reference_price' => $price])->assertSessionHasNoErrors();

    return SupplierProduct::where('supplier_id', $s->id)->where('name', $name)->firstOrFail();
}

it('keeps a lot on the supplier price it was created with, and archives suppliers without losing history', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-02');
    $a = supplier($co, 'Supplier A');
    $b = supplier($co, 'Supplier B');
    $c = supplier($co, 'Supplier C');
    $hairOilA = product($co, $a, 'Hair Oil', '3');
    product($co, $a, 'Face Wash', '4.50');
    product($co, $b, 'Soap', '1.25');
    product($co, $c, 'Hair Oil', '3.40'); // the same product at another supplier, at a different price

    $lot = $co->approvedLot('Lot snapshot', [['supplier_product_id' => $hairOilA->id, 'quantity' => '1000']], profit: '1');
    expect($lot->lines()->first()->unit_price)->toBe(300);

    $co->as('finance')->put(route('sourcing.products.update', $hairOilA), ['supplier_id' => $a->id, 'name' => 'Hair Oil', 'unit' => 'pcs', 'reference_price' => '3.50', 'is_active' => 1])->assertSessionHasNoErrors();
    expect($hairOilA->refresh()->reference_price)->toBe(350)->and($hairOilA->prices()->count())->toBe(2)
        ->and($lot->lines()->first()->unit_price)->toBe(300)->and(LotFinancials::for($lot->refresh())['purchase'])->toBe(300_000); // the old lot is untouched

    $second = $co->approvedLot('Lot later', [['supplier_product_id' => $hairOilA->id, 'quantity' => '10']]);
    expect($second->lines()->first()->unit_price)->toBe(350); // only future lots use the new price

    // Archive instead of delete; the profile still shows the payable summary.
    $co->as('finance')->patch(route('sourcing.suppliers.archive', $a))->assertSessionHasNoErrors();
    expect($a->refresh()->is_active)->toBeFalse()->and($lot->lines()->count())->toBe(1);
    $co->as('finance')->get(route('sourcing.suppliers.show', $a))->assertOk()->assertSee('3,035.00'); // 1,000 x 3.00 + 10 x 3.50
    $co->as('finance')->get(route('sourcing.suppliers.index', ['status' => 'all']))->assertOk()->assertSee('Supplier C');
    $co->as('employee')->get(route('sourcing.suppliers.index'))->assertForbidden();
});

it('prices a lot by fixed profit or markup and splits shared costs exactly, by value and by quantity', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-02');
    $s = supplier($co, 'Supplier M');

    // Fixed profit vs markup: unit cost 5.00 both ways gives 6.00.
    $fixed = $co->approvedLot('Fixed', [['supplier_id' => $s->id, 'description' => 'P', 'quantity' => '1000', 'unit_price' => '3']], profit: '1', method: 'fixed',
        costs: [['category' => 'Transportation', 'amount' => '2000']]);
    $markup = $co->approvedLot('Markup', [['supplier_id' => $s->id, 'description' => 'P', 'quantity' => '1000', 'unit_price' => '3']], profit: '20', method: 'markup',
        costs: [['category' => 'Transportation', 'amount' => '2000']]);
    expect($fixed->lines()->first()->approved_price)->toBe(600)->and($markup->lines()->first()->approved_price)->toBe(600)->and($markup->pricing_value)->toBe(2000);

    // Three products with awkward numbers (purchase 93.31 / 123.51 / 29.81) and shared costs of 100.01 + 33.33 = 133.34.
    $lines = [
        ['supplier_id' => $s->id, 'description' => 'P1', 'quantity' => '7', 'unit_price' => '13.33'],
        ['supplier_id' => $s->id, 'description' => 'P2', 'quantity' => '3', 'unit_price' => '41.17'],
        ['supplier_id' => $s->id, 'description' => 'P3', 'quantity' => '11', 'unit_price' => '2.71'],
    ];
    $shared = [['category' => 'Transportation', 'amount' => '100.01'], ['category' => 'Handling', 'amount' => '33.33']];
    $purchase = [9331, 12351, 2981];
    $qty = [700, 300, 1100]; // hundredths
    foreach (['value' => $purchase, 'quantity' => $qty] as $basis => $weights) {
        $lot = $co->approvedLot('Multi '.$basis, $lines, profit: '1', costs: $shared, basis: $basis);
        $figures = LotFinancials::for($lot->refresh());
        $actual = collect($figures['lines'])->pluck('shared')->values()->all();
        $expected = array_values(largestRemainder(13334, $weights));
        expect($actual)->toBe($expected)->and(array_sum($actual))->toBe(13334) // allocated costs equal the original shared costs exactly
            ->and($figures['landed'])->toBe(array_sum($purchase) + 13334)
            ->and($lot->costs()->count())->toBe(2); // no cost segment was duplicated by the allocation
    }
});

it('keeps total cost and payable separate, posts cash only for verified payments, and obeys standing-policy limits', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-02');
    $s = supplier($co, 'Supplier P');
    $lot = $co->approvedLot('Payables', [['supplier_id' => $s->id, 'description' => 'Stock', 'quantity' => '10000', 'unit_price' => '5']], profit: '1'); // purchase 50,000
    $pay = fn (string $amount, string $key) => $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => $amount, 'paid_on' => '2026-02-03', 'idempotency_key' => $key], $lot);
    $co->invest('A', '100000', '2026-02-01'); // cash to pay from
    $cash = dr('cash');

    $pay('30000', 'bbbbbbbb-0000-4000-8000-000000000001')->assertSessionHasNoErrors();
    $pay('30000', 'bbbbbbbb-0000-4000-8000-000000000001'); // duplicate request
    $f = LotFinancials::for($lot->refresh());
    expect($f['landed'])->toBe(5_000_000)->and($f['supplier_paid'])->toBe(3_000_000)->and($f['supplier_outstanding'])->toBe(2_000_000) // cost stays 50,000
        ->and(SupplierPayment::count())->toBe(1)->and(dr('cash'))->toBe($cash); // no cash leaves the books until the expense is verified
    $pay('20000.01', 'bbbbbbbb-0000-4000-8000-000000000002')->assertSessionHas('error'); // beyond what is owed

    $co->verifyAllExpenses();
    expect(dr('cash'))->toBe($cash - 3_000_000)->and(dr('expense'))->toBe(3_000_000);

    // Board rejects a payment: the money never left, the payable returns.
    $pay('5000', 'bbbbbbbb-0000-4000-8000-000000000003')->assertSessionHasNoErrors();
    $co->post('board', 'finance.expenses.decide', ['decision' => 'reject'], Expense::latest('id')->first());
    expect(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(2_000_000)->and(dr('expense'))->toBe(3_000_000);

    // Standing policy (Super Admin proposes, Board approves): payments up to 8,000 post by themselves.
    $co->standingPolicy('supplier_payments', '8000');
    $pay('8000', 'bbbbbbbb-0000-4000-8000-000000000004')->assertSessionHasNoErrors();
    expect(Expense::latest('id')->first()->status)->toBe('verified')->and(Expense::latest('id')->first()->verified_by)->toBeNull()->and(dr('expense'))->toBe(3_800_000);
    $pay('8000.01', 'bbbbbbbb-0000-4000-8000-000000000005')->assertSessionHasNoErrors();
    expect(Expense::latest('id')->first()->status)->toBe('pending')->and(dr('expense'))->toBe(3_800_000); // outside the limit: needs the Board

    // Board revokes the policy: later payments need approval again; the earlier automatic one stays posted.
    $co->post('board', 'finance.automation.revoke', [], 'supplier_payments')->assertSessionHasNoErrors();
    $pay('1000', 'bbbbbbbb-0000-4000-8000-000000000006')->assertSessionHasNoErrors();
    expect(Expense::latest('id')->first()->status)->toBe('pending')->and(dr('expense'))->toBe(3_800_000);
});

it('corrects a verified supplier payment only by its real economic event', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-02');
    $s = supplier($co, 'Supplier K');
    $lot = $co->approvedLot('Corrections', [['supplier_id' => $s->id, 'description' => 'Stock', 'quantity' => '1000', 'unit_price' => '5']], profit: '1'); // owes 5,000
    $co->invest('A', '100000', '2026-02-01');
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $s->id, 'amount' => '5000', 'paid_on' => '2026-02-03', 'idempotency_key' => 'cccccccc-0000-4000-8000-000000000001'], $lot);
    $co->verifyAllExpenses();
    $payment = SupplierPayment::firstOrFail();
    $cashAfterPayment = dr('cash');
    $void = fn (array $d) => $co->post('finance', 'sourcing.payments.void', $d + ['reason' => 'audit'], $payment);
    $decide = fn () => $co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], Expense::whereNotNull('reverses_id')->where('status', 'pending')->firstOrFail());

    // No one outside Finance can request it.
    $co->post('employee', 'sourcing.payments.void', ['reason' => 'x'], $payment)->assertForbidden();
    $co->post('clients', 'sourcing.payments.void', ['reason' => 'x'], $payment)->assertForbidden();

    $void(['type' => 'credit_note', 'amount' => '2000'])->assertSessionHas('error'); // reference is mandatory
    $void(['type' => 'credit_note', 'amount' => '2000', 'reference' => 'CN-1'])->assertSessionHasNoErrors();
    expect(dr('cash'))->toBe($cashAfterPayment); // nothing posted until the Board verifies the correction
    $decide();
    expect(dr('cash'))->toBe($cashAfterPayment)->and(dr('supplier.credit'))->toBe(200_000)->and(dr('expense'))->toBe(300_000) // no imaginary cash
        ->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(0);

    $void(['type' => 'refund_received', 'amount' => '1000', 'reference' => 'BANK-5'])->assertSessionHasNoErrors();
    $decide();
    expect(dr('cash'))->toBe($cashAfterPayment + 100_000)->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(100_000);

    $void(['type' => 'recording_error', 'amount' => '2000.01'])->assertSessionHas('error'); // more than what is left to correct
    $void(['type' => 'recording_error', 'amount' => '2000'])->assertSessionHasNoErrors();
    $decide();
    expect(dr('cash'))->toBe($cashAfterPayment + 300_000)->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(300_000)
        ->and(Expense::find($payment->expense_id)->status)->toBe('verified') // the original posting is never edited
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));
});

it('works out unexpected-cost outcomes and Emergency Fund coverage for scenarios A to D', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-02');
    $s = supplier($co, 'Supplier U');
    $co->seedReserve(10_000_000); // opening Emergency Reserve of 100,000.00 for this scenario
    // [unexpected cost BDT, expected profit minor, expected coverage minor]
    foreach ([['A', 300, 20_000, 0], ['B', 500, 0, 0], ['C', 800, -30_000, 30_000], ['D', 1500, -100_000, 100_000]] as [$name, $cost, $profit, $coverage]) {
        $lot = $co->approvedLot('Unexpected '.$name, [['supplier_id' => $s->id, 'description' => 'W', 'quantity' => '1', 'unit_price' => '9500']], profit: '500');
        expect($lot->approved_revenue - $lot->approved_cost)->toBe(50_000); // expected profit BDT 500
        $co->post('finance', 'sourcing.lots.costs.store', ['category' => 'Customs', 'amount' => (string) $cost, 'kind' => 'actual', 'incurred_on' => '2026-02-03'], $lot)->assertSessionHasNoErrors();
        $f = LotFinancials::for($lot->refresh());
        expect($f['profit'])->toBe($profit)->and($f['fund_suggested'])->toBe($coverage)->and($f['unexpected'])->toBe($cost * 100)->and($f['cost_variance'])->toBe($cost * 100);
        $co->as('finance')->get(route('sourcing.lots.show', [$lot, 'tab' => 'emergency']))->assertOk();
    }

    // Scenario C in full: request → no deduction → only the Board can approve → Finance utilises exactly once.
    $lot = SourcingLot::where('title', 'Unexpected C')->firstOrFail();
    $co->post('finance', 'sourcing.lots.fund.store', ['amount' => '300.01', 'reason' => 'too much'], $lot)->assertSessionHas('error');
    $co->post('finance', 'sourcing.lots.fund.store', ['amount' => '300', 'reason' => 'customs surprise'], $lot)->assertSessionHasNoErrors();
    $req = EmergencyFundRequest::firstOrFail();
    expect($req->status)->toBe('pending')->and(dr('fund.emergency'))->toBe(-10_000_000); // nothing reserved or deducted
    $co->post('finance', 'sourcing.fund.decide', ['decision' => 'approve'], $req)->assertForbidden();
    $co->post('super', 'sourcing.fund.decide', ['decision' => 'approve'], $req)->assertForbidden(); // Board-only, even for Super Admin
    $co->post('board', 'sourcing.fund.utilise', [], $req)->assertForbidden();                          // utilising is Finance's job
    $co->post('finance', 'sourcing.fund.utilise', [], $req)->assertSessionHas('error');                // not approved yet
    $co->post('board', 'sourcing.fund.decide', ['decision' => 'approve'], $req)->assertSessionHasNoErrors();
    expect(dr('fund.emergency'))->toBe(-10_000_000);
    $co->post('finance', 'sourcing.fund.utilise', [], $req)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.fund.utilise', [], $req)->assertSessionHas('error');
    expect(dr('fund.emergency'))->toBe(-9_970_000)->and(dr('fund.coverage'))->toBe(-30_000)->and(dr('income'))->toBe(0)->and(dr('expense'))->toBe(0)
        ->and(LotFinancials::for($lot->refresh())['profit'])->toBe(-30_000); // the loss is still a loss
    $co->post('finance', 'sourcing.fund.decide', ['decision' => 'approve'], $req)->assertForbidden();
});

it('refuses coverage the reserve cannot pay and never lets the reserve go negative', function () {
    $co = SellbeesCompany::boot($this);
    Carbon::setTestNow('2026-02-02');
    $s = supplier($co, 'Supplier R');
    $co->seedReserve(50_000); // 500.00 only
    $lot = $co->approvedLot('Short reserve', [['supplier_id' => $s->id, 'description' => 'W', 'quantity' => '1', 'unit_price' => '9500']], profit: '500');
    $co->post('finance', 'sourcing.lots.costs.store', ['category' => 'Customs', 'amount' => '1500', 'kind' => 'actual', 'incurred_on' => '2026-02-03'], $lot);
    $co->post('finance', 'sourcing.lots.fund.store', ['amount' => '1000', 'reason' => 'big loss'], $lot)->assertSessionHasNoErrors();
    $req = EmergencyFundRequest::firstOrFail();
    $co->post('board', 'sourcing.fund.decide', ['decision' => 'approve'], $req)->assertSessionHas('error'); // insufficient balance
    expect($req->refresh()->status)->toBe('pending')->and(dr('fund.emergency'))->toBe(-50_000);
    $co->post('board', 'sourcing.fund.decide', ['decision' => 'reject', 'note' => 'no funds'], $req)->assertSessionHasNoErrors();
    expect($req->refresh()->status)->toBe('rejected');
    $co->post('finance', 'sourcing.fund.utilise', [], $req)->assertSessionHas('error'); // rejected cannot be utilised
    expect(dr('fund.emergency'))->toBe(-50_000)->and(LotFinancials::for($lot->refresh())['uncovered_loss'])->toBe(100_000); // the loss is reported, not hidden
});
