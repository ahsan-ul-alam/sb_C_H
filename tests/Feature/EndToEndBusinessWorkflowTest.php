<?php

use App\Models\CapitalEntry;
use App\Models\ClientOrder;
use App\Models\ClientSettlement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Services\CashReconciliation;
use App\Services\ClientSettlementService;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\LoanRecoveryAllocationService;
use App\Services\LotFinancials;
use App\Services\ProjectEconomics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/*
| One complete business workflow on disposable data, with every figure reconciled: the owner's Mug example from client registration to settlement, then investor money and a
| loan. Cash must equal capital + Sellbees' own earnings (sourcing margin + commission) and nothing else: the client's money, courier charges and loans are never Sellbees' income.
*/
it('runs the whole business end to end and every taka reconciles', function () {
    Carbon::setTestNow('2026-02-10 10:00:00');
    $co = SellbeesCompany::boot($this);
    $co->standingPolicy('quick_lot', '100000000');
    $co->invest('A', '1000', '2026-02-02');                                                         // Sellbees' own capital: BDT 1,000
    $co->post('finance', 'finance.orders.rates', ['default_percent' => '1'])->assertSessionHasNoErrors();
    $cash = fn () => CashReconciliation::expectedCash('BDT');
    expect($cash())->toBe(100_000);

    // ── Client → project → product sourcing (supplier BDT 3, client BDT 5, 10 mugs) → stock receiving
    $co->post('clients', 'sourcing.suppliers.store', ['name' => 'Mug Supplier', 'phone' => '017'])->assertSessionHasNoErrors();
    $supplier = Supplier::latest('id')->firstOrFail();
    $co->post('clients', 'sourcing.products.store', ['supplier_id' => $supplier->id, 'name' => 'Mug', 'reference_price' => '3'])->assertSessionHasNoErrors();
    $product = SupplierProduct::latest('id')->firstOrFail();
    $client = $co->client('Mug Client');
    $project = $co->project($client, 'Mug Project');
    $co->post('clients', 'sourcing.lots.store', ['supplier_id' => $supplier->id, 'delivery_cost' => '0', 'submission_key' => (string) Str::uuid(), 'project_id' => $project->id,
        'items' => [['product_id' => $product->id, 'quantity' => '10', 'profit' => '2']]])->assertSessionHasNoErrors();
    $lot = SourcingLot::latest('id')->firstOrFail();
    $line = $lot->lines()->firstOrFail();
    $co->post('clients', 'sourcing.lots.bill-client', ['project_id' => $project->id], $lot)->assertSessionHasNoErrors();
    expect(Inventory::balances($line)['available'])->toBe('0.00');                                  // ordered is not stock
    $co->post('clients', 'sourcing.lines.arrival', ['received_quantity' => '10', 'arrived_on' => '2026-02-10'], $line)->assertSessionHasNoErrors();
    expect(Inventory::balances($line->refresh())['available'])->toBe('10.00')->and(Inventory::balances($line)['received'])->toBe('10.00');

    // ── The client pays 50.00; Sellbees pays the supplier 30.00
    $co->post('finance', 'sourcing.lots.client-payment', ['paid_on' => '2026-02-10', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()], $lot)->assertSessionHasNoErrors();
    $co->post('finance', 'sourcing.lots.payments.store', ['supplier_id' => $supplier->id, 'amount' => '30', 'paid_on' => '2026-02-10', 'purchase_order' => 'PO-1', 'reference' => 'PAY-1', 'evidence_reference' => 'INV-1', 'idempotency_key' => (string) Str::uuid()], $lot)->assertSessionHasNoErrors();
    $co->verifyAllExpenses();
    $f = LotFinancials::for($lot->refresh());
    expect([$f['purchase'], $f['revenue'], $f['profit']])->toBe([3_000, 5_000, 2_000])->and((int) SupplierPayment::sum('amount'))->toBe(3_000)
        ->and(Invoice::where('project_id', $project->id)->firstOrFail()->balance())->toBe(0)
        ->and($cash())->toBe(100_000 + 5_000 - 3_000);                                              // capital + client's 50 - supplier's 30 (the margin is inside what is left)

    $place = fn (string $ref, string $qty) => $co->post('clients', 'product.orders.store', ['project_id' => $project->id, 'reference' => $ref, 'ordered_on' => '2026-02-10', 'customer_name' => 'Customer', 'items' => [['product_id' => $product->id, 'quantity' => $qty, 'price' => '10']]]);
    $order = fn (string $ref) => ClientOrder::where('reference', $ref)->firstOrFail();
    $step = fn (string $ref, string $step, array $data = []) => $co->post('clients', 'product.orders.'.$step, $data, $order($ref));

    // ── A cancelled order releases its reservation once and earns nothing
    $place('X-CANCEL', '3')->assertSessionMissing('error');
    $step('X-CANCEL', 'confirm')->assertSessionMissing('error');
    expect(Inventory::balances($line)['reserved'])->toBe('3.00');
    $step('X-CANCEL', 'cancel')->assertSessionMissing('error');
    $step('X-CANCEL', 'cancel')->assertSessionHas('error');
    expect(Inventory::balances($line)['available'])->toBe('10.00')->and($order('X-CANCEL')->commission)->toBe(0);

    // ── A failed delivery returns to custody, is inspected, and earns nothing; the charge is Sellbees' own cost under this contract
    $place('Y-FAIL', '2')->assertSessionMissing('error');
    $step('Y-FAIL', 'confirm');
    $step('Y-FAIL', 'dispatch', ['delivery_charge' => '2', 'delivery_charge_bearer' => 'sellbees', 'delivery_charge_status' => 'paid_by_sellbees'])->assertSessionMissing('error');
    $step('Y-FAIL', 'fail')->assertSessionMissing('error');
    expect(Inventory::balances($line)['returned'])->toBe('2.00')->and(Inventory::balances($line)['available'])->toBe('8.00')->and($order('Y-FAIL')->commission)->toBe(0);
    $co->post('clients', 'product.items.inspect', ['usable' => '2', 'damaged' => '0'], $order('Y-FAIL')->items()->firstOrFail())->assertSessionHasNoErrors();
    expect(Inventory::balances($line)['available'])->toBe('10.00')->and(Inventory::balances($line)['returned'])->toBe('0.00');

    // ── All ten mugs delivered: BDT 10 each, BDT 1 courier charge each (the courier keeps it before remitting)
    foreach (['Z-ONE' => ['1', '1'], 'Z-NINE' => ['9', '9']] as $ref => [$qty, $charge]) {
        $place($ref, $qty)->assertSessionMissing('error');
        $step($ref, 'confirm')->assertSessionMissing('error');
        $step($ref, 'dispatch', ['delivery_charge' => $charge, 'delivery_charge_bearer' => 'client', 'delivery_charge_status' => 'courier_deducted'])->assertSessionMissing('error');
        $step($ref, 'dispatch', ['delivery_charge' => '99'])->assertSessionHas('error');         // the charge is recorded once
        $step($ref, 'deliver')->assertSessionMissing('error');
    }
    $one = $order('Z-ONE');
    $nine = $order('Z-NINE');
    expect([$one->net_value, $one->commission, $nine->net_value, $nine->commission])->toBe([900, 9, 8_100, 81]);               // BDT 9 -> 0.09 ; BDT 81 -> 0.81
    $b = Inventory::balances($line);
    expect([$b['available'], $b['reserved'], $b['dispatched'], $b['delivered'], $b['returned'], $b['damaged']])->toBe(['0.00', '0.00', '0.00', '10.00', '0.00', '0.00'])->and(Inventory::reconciles($line))->toBeTrue();
    $co->post('clients', 'product.orders.store', ['project_id' => $project->id, 'reference' => 'OVERSELL', 'ordered_on' => '2026-02-10', 'items' => [['product_id' => $product->id, 'quantity' => '1', 'price' => '10']]])->assertSessionHas('error');   // nothing left to sell
    expect(ClientOrder::where('reference', 'OVERSELL')->exists())->toBeFalse();

    // ── The courier remits 90.00 of the 100.00; Sellbees pays the client what the sales earned
    $collect = fn (string $ref, string $amount) => $co->post('clients', 'product.orders.collect', ['amount' => $amount, 'collected_on' => '2026-02-11', 'idempotency_key' => (string) Str::uuid()], $order($ref));
    $collect('Z-ONE', '9.01')->assertSessionHas('error');                                           // never more than the courier remits
    $collect('Z-ONE', '9')->assertSessionMissing('error');
    $collect('Z-NINE', '81')->assertSessionMissing('error');
    $e = ProjectEconomics::for($project->refresh());
    expect([$e['investment'], $e['sourcing_margin'], $e['gross_sales'], $e['delivery_charges'], $e['net_sales'], $e['commission'], $e['entitlement'], $e['cost_of_sold'], $e['client_profit'], $e['investment_recovered']])
        ->toBe([5_000, 2_000, 10_000, 1_000, 9_000, 90, 8_910, 5_000, 3_910, 5_000])                  // 50 invested, 20 margin, 100 sold, 10 courier, 90 base, 0.90, 89.10, 39.10
        ->and($e['notes'])->toBe([]);
    $co->post('finance', 'product.settlements.store', ['pay' => '89.10', 'paid_on' => '2026-02-12', 'reference' => 'TRX-MUG', 'idempotency_key' => (string) Str::uuid()], $client)->assertSessionMissing('error');
    $co->post('finance', 'product.settlements.store', ['pay' => '0.01', 'paid_on' => '2026-02-12', 'reference' => 'TRX-2', 'idempotency_key' => (string) Str::uuid()], $client)->assertSessionHas('error');   // nothing left to pay
    expect(ClientSettlement::sum('paid'))->toBe(8_910)->and(ClientSettlementService::fundsHeld($client))->toBe(0)->and(Ledger::creditBalance('client.funds'))->toBe(0)
        ->and(ProjectEconomics::for($project)['client']['outstanding'])->toBe(0);

    // ── Cash = capital + Sellbees' own earnings only: margin 20.00 + commission 0.90. Client money, courier charges and the product cost are not Sellbees' income.
    expect($cash())->toBe(100_000 + 2_000 + 90)->and(ClientOrder::whereIn('reference', ['X-CANCEL', 'Y-FAIL'])->sum('commission'))->toEqual(0);

    // ── Investor management: a fresh investment adds cash and capital once; a loan goes out and comes back once; a tracking allocation moves nothing
    $before = [$cash(), (int) CapitalEntry::where('status', 'verified')->sum('amount')];
    $co->invest('B', '500', '2026-02-10');
    expect($cash())->toBe($before[0] + 50_000)->and((int) CapitalEntry::where('status', 'verified')->sum('amount'))->toBe($before[1] + 50_000);
    $loanExpense = $co->expense('Loan', 'Loan for Ahsan', '80', '2026-02-10');
    $co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $loanExpense->id, 'borrower_name' => 'Ahsan'])->assertSessionHasNoErrors();
    $loan = LoanReceivable::firstOrFail();
    $co->post('board', 'finance.loans-receivable.decide', ['decision' => 'verify'], $loan)->assertSessionHasNoErrors();
    $afterInvest = $before[0] + 50_000;
    expect($cash())->toBe($afterInvest - 8_000)->and(Ledger::debitBalance('assets.loans_receivable'))->toBe(8_000);          // the loan moved cash into the receivable
    $co->post('finance', 'finance.loans-receivable.repay', ['amount' => '80', 'repaid_on' => '2026-02-10', 'idempotency_key' => (string) Str::uuid()], $loan)->assertSessionHasNoErrors();
    $repayment = LoanReceivableRepayment::firstOrFail();
    expect($cash())->toBe($afterInvest - 8_000);                                                    // pending: not cash yet
    $co->post('board', 'finance.loans-receivable.repayments.decide', ['decision' => 'verify'], $repayment)->assertSessionHasNoErrors();
    expect($cash())->toBe($afterInvest)->and(Ledger::debitBalance('assets.loans_receivable'))->toBe(0);
    $allocation = LoanRecoveryAllocationService::propose($repayment->refresh(), $co->partner('C'), 8_000, 'tracking', null, null, $co->users['finance']);
    LoanRecoveryAllocationService::decide($allocation, true, $co->users['board']);
    expect($cash())->toBe($afterInvest)->and((int) CapitalEntry::where('status', 'verified')->sum('amount'))->toBe($before[1] + 50_000)->and(Ledger::debitBalance('assets.loans_receivable'))->toBe(0);

    // Every journal entry balances.
    foreach (JournalEntry::with('lines')->get() as $entry) {
        expect((int) $entry->lines->sum('debit'))->toBe((int) $entry->lines->sum('credit'));
    }
});
