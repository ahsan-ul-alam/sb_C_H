<?php

use App\Mail\ClientInformationCode;
use App\Models\Client;
use App\Models\ClientExpense;
use App\Models\ClientOrder;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Services\ClientApiIntegrations;
use App\Services\ClientExpenses;
use App\Services\ClientProfitability;
use App\Services\ClientProfitDeclarations;
use App\Services\LotFinancials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** An order for $qty units at 600.00 (no discount), created on $on. */
function accOrder(array $s, string $ref, string $qty, string $on): ClientOrder
{
    Carbon::setTestNow($on.' 10:00:00');
    $s['co']->post('finance', 'product.orders.store', ['project_id' => $s['project']->id, 'reference' => $ref, 'ordered_on' => $on, 'sales_channel' => 'website', 'payment_method' => 'cod',
        'delivery_method' => 'courier', 'items' => [['product_id' => $s['product']->id, 'quantity' => $qty, 'price' => '600', 'discount' => '0']]])->assertSessionHasNoErrors();

    return ClientOrder::where('reference', $ref)->firstOrFail();
}

/** Confirm, dispatch and deliver $qty units on $on (the rest of the order is not delivered). */
function accDeliver(array $s, ClientOrder $o, string $qty, string $on): ClientOrder
{
    Carbon::setTestNow($on.' 10:00:00');
    $s['co']->post('clients', 'product.orders.confirm', [], $o)->assertSessionHasNoErrors();
    $s['co']->post('clients', 'product.orders.dispatch', [], $o)->assertSessionHasNoErrors();
    $s['co']->post('clients', 'product.orders.deliver', ['delivered' => [$o->items()->first()->id => $qty]], $o)->assertSessionHasNoErrors();

    return $o->refresh();
}

function accStock($test): array
{
    $s = integrationStock($test);
    $s['users'] = $s['co']->users;

    return $s;
}

function accExpense(array $s, array $over = []): ClientExpense
{
    $data = $over + ['category' => 'delivery', 'amount' => 100_000, 'spent_on' => '2026-03-10', 'responsible_party' => 'client', 'paid_status' => 'paid', 'reference' => 'REF-'.random_int(1000, 9999)];
    $e = ClientExpenses::record($s['client'], $data + ['allocations' => [['project_id' => $s['project']->id, 'amount' => $data['amount']]]], $s['users']['finance']);
    ClientExpenses::review($e, $s['users']['multi']);
    ClientExpenses::decide($e, true, null, $s['users']['board']);

    return $e->refresh();
}

function accZero(array $except = []): array
{
    return array_values(array_diff(array_keys(ClientExpense::CATEGORIES), $except));
}

it('uses the all-in acquisition price, never Sellbees inventory cost, and deducts only separately charged fees', function () {
    $s = accStock($this);
    $order = accDeliver($s, accOrder($s, 'A1', '100', '2026-03-02'), '100', '2026-03-03');
    $allIn = (int) $s['line']->fresh()->approved_price;                           // supplier cost + freight + Sellbees margin, as billed to the client
    $sellbeesUnitCost = (int) LotFinancials::for($s['lot']->fresh())['lines'][$s['line']->id]['unit_cost'];
    $invoice = $s['project']->invoices()->firstOrFail();

    expect($allIn)->not->toBe($sellbeesUnitCost);                                  // the client's acquisition cost and Sellbees' own inventory cost are different things
    // A fee already inside the all-in price is recorded for information only and needs its source invoice.
    expect(fn () => ClientExpenses::record($s['client'], ['category' => 'handling', 'amount' => 50_000, 'spent_on' => '2026-03-03', 'responsible_party' => 'client', 'paid_status' => 'paid',
        'reference' => 'FREIGHT-IN', 'charge_treatment' => 'included_in_acquisition', 'allocations' => [['project_id' => $s['project']->id, 'amount' => 50_000]]], $s['users']['finance']))->toThrow(RuntimeException::class);
    accExpense($s, ['category' => 'handling', 'amount' => 50_000, 'reference' => 'FREIGHT-IN', 'charge_treatment' => 'included_in_acquisition', 'source_invoice_id' => $invoice->id]);
    accExpense($s, ['category' => 'delivery', 'amount' => 70_000, 'reference' => 'COURIER-EXTRA']);                 // additional, separately charged
    ClientProfitDeclarations::declare($s['client'], null, '2026-03-01', '2026-03-31', accZero(['delivery']), null, $s['users']['finance']);

    $r = ClientProfitability::calculate($s['client'], null, '2026-03-01', '2026-03-31');
    $commission = (int) $order->commission;
    expect($r['status'])->toBe('complete')
        ->and($r['figures']['sold_product_cost_minor'])->toBe(100 * $allIn)
        ->and($r['figures']['fees_included_in_acquisition_minor'])->toBe(50_000)         // shown, not deducted
        ->and($r['figures']['client_borne_expenses_minor'])->toBe(70_000)                // only the additional fee
        ->and($r['figures']['sellbees_commission_minor'])->toBe($commission)             // the separately charged commission
        ->and($r['net_profit_minor'])->toBe(100 * 60_000 - 100 * $allIn - 70_000 - $commission);
});

it('dates a sale by its actual delivery, not by the order date', function () {
    $s = accStock($this);
    $o = accDeliver($s, accOrder($s, 'X1', '100', '2026-02-20'), '100', '2026-03-05');   // created in February, delivered in March

    $feb = ClientProfitability::calculate($s['client'], null, '2026-02-01', '2026-02-28');
    $mar = ClientProfitability::calculate($s['client'], null, '2026-03-01', '2026-03-31');
    expect($feb['status'])->toBe('no_sales')->and($feb['figures']['net_sales_minor'])->toBe(0)->and($feb['figures']['sellbees_commission_minor'])->toBe(0)
        ->and($mar['figures']['net_sales_minor'])->toBe(100 * 60_000)->and($mar['figures']['sellbees_commission_minor'])->toBe((int) $o->commission);
});

it('splits partial deliveries in two months exactly, with commission adding up', function () {
    $s = accStock($this);
    $o1 = accDeliver($s, accOrder($s, 'P1', '100', '2026-02-25'), '60', '2026-03-05');   // 60 of 100 delivered in March, the rest failed
    $o2 = accDeliver($s, accOrder($s, 'P2', '50', '2026-03-20'), '30', '2026-04-02');    // 30 of 50 delivered in April

    $mar = ClientProfitability::calculate($s['client'], null, '2026-03-01', '2026-03-31');
    $apr = ClientProfitability::calculate($s['client'], null, '2026-04-01', '2026-04-30');
    $all = ClientProfitability::calculate($s['client']);
    expect($mar['figures']['net_sales_minor'])->toBe(60 * 60_000)->and($apr['figures']['net_sales_minor'])->toBe(30 * 60_000)->and($all['figures']['net_sales_minor'])->toBe(90 * 60_000)
        ->and($mar['figures']['sellbees_commission_minor'] + $apr['figures']['sellbees_commission_minor'])->toBe($all['figures']['sellbees_commission_minor'])
        ->and($all['figures']['sellbees_commission_minor'])->toBe((int) $o1->commission + (int) $o2->commission)
        ->and($mar['products'][0]['units_sold'])->toBe('60.00')->and($apr['products'][0]['units_sold'])->toBe('30.00');
});

it('counts a return and a refund in the period they happen, not the period of the sale', function () {
    $s = accStock($this);
    $o = accDeliver($s, accOrder($s, 'R1', '100', '2026-03-01'), '100', '2026-03-05');
    Carbon::setTestNow('2026-04-03 10:00:00');
    $s['co']->post('clients', 'product.orders.return', ['returned' => [$o->items()->first()->id => '10']], $o)->assertSessionHasNoErrors();
    OrderRefund::create(['client_order_id' => $o->id, 'amount' => 50_000, 'kind' => 'no_return', 'reason' => 'price reduction', 'status' => 'paid', 'paid_on' => '2026-04-20',
        'classification' => 'price_reduction', 'commission_treatment' => 'reduce', 'requested_by' => $s['users']['finance']->id]);

    $mar = ClientProfitability::calculate($s['client'], null, '2026-03-01', '2026-03-31');
    $apr = ClientProfitability::calculate($s['client'], null, '2026-04-01', '2026-04-30');
    $price = (int) $s['line']->fresh()->approved_price;
    expect($mar['figures']['net_sales_minor'])->toBe(100 * 60_000)->and($mar['figures']['sales_reduced_by_refunds_minor'])->toBe(0)
        ->and($apr['figures']['delivered_sales_minor'])->toBe(-10 * 60_000)->and($apr['figures']['sales_reduced_by_refunds_minor'])->toBe(50_000)
        ->and($apr['figures']['net_sales_minor'])->toBe(-10 * 60_000 - 50_000)->and($apr['figures']['sold_product_cost_minor'])->toBe(-10 * $price);

    OrderRefund::where('client_order_id', $o->id)->update(['commission_treatment' => 'pending']);                      // an unresolved refund blocks the period
    expect(ClientProfitability::calculate($s['client'], null, '2026-04-01', '2026-04-30')['missing_data'])->toContain('Refund #'.OrderRefund::first()->id.' (price_reduction) has no confirmed financial treatment.');
});

it('does not invent a delivery date for a historical delivery without evidence', function () {
    $s = accStock($this);
    accDeliver($s, accOrder($s, 'H1', '100', '2026-03-01'), '100', '2026-03-05');
    DB::table('stock_movements')->where('type', 'deliver')->delete();                    // a historical order whose ledger carries no dated delivery

    $period = ClientProfitability::calculate($s['client'], null, '2026-03-01', '2026-03-31');
    $toDate = ClientProfitability::calculate($s['client']);
    expect($period['status'])->toBe('incomplete')->and($period['net_profit_minor'])->toBeNull()->and(implode(' ', $period['missing_data']))->toContain('delivery date is not evidenced')
        ->and($toDate['figures']['net_sales_minor'])->toBe(100 * 60_000);                  // undated, so only the to-date view counts it
});

it('keeps a non-BDT project in its own currency, never adds currencies and offers no conversion', function () {
    Mail::fake();
    $s = accStock($this);
    $usd = $s['client']->projects()->create(['name' => 'Export', 'status' => 'active', 'currency' => 'USD', 'started_on' => '2026-01-01']);
    $payment = new Payment(['amount' => 5_000, 'paid_on' => '2026-02-01', 'method' => 'cash', 'reference' => 'USD-1']);
    $payment->forceFill(['project_id' => $usd->id, 'idempotency_key' => (string) Str::uuid()])->save();
    $s['client']->update(['email' => 'u@example.test']);
    $s['client']->forceFill(['email_verified_at' => now()])->save();
    $made = ClientApiIntegrations::create('USD site');
    ClientApiIntegrations::grant($made['integration'], $s['client']);

    expect(ClientProfitability::currencies($s['client']))->toBe(['BDT', 'USD'])->and(ClientProfitability::calculate($s['client'], null, null, null, 'USD')['currency'])->toBe('USD');
    $id = $this->withToken($made['credential'])->postJson('/api/v1/client-information/request', ['sb_number' => $s['client']->sb_number])->json('request_id');
    $code = Mail::sent(ClientInformationCode::class)->last()->code;
    $data = $this->withToken($made['credential'])->postJson('/api/v1/client-information/verify', ['sb_number' => $s['client']->sb_number, 'request_id' => $id, 'verification_code' => $code])->assertOk()->json('data');

    expect($data['profitability']['currency'])->toBe('BDT')->and($data['profitability']['other_currencies'][0]['figures']['net_sales_minor'])->toBe(0)
        ->and($data['profitability']['converted_profitability']['status'])->toBe('unavailable')->and(collect($data['financial']['by_currency'])->pluck('currency')->sort()->values()->all())->toBe(['BDT', 'USD'])
        ->and(collect($data['financial']['by_currency'])->firstWhere('currency', 'USD')['total_received_minor'])->toBe(5_000)
        ->and($data['sales']['other_currencies'][0]['currency'])->toBe('USD');
});

it('keeps a declaration valid when other periods change and voids it when its own period changes', function () {
    $s = accStock($this);
    accDeliver($s, accOrder($s, 'D1', '100', '2026-03-01'), '100', '2026-03-05');
    accExpense($s, ['category' => 'delivery', 'amount' => 100_000, 'spent_on' => '2026-03-10', 'paid_status' => 'unpaid']);          // unpaid but accrued: it still counts
    ClientProfitDeclarations::declare($s['client'], null, '2026-03-01', '2026-03-31', accZero(['delivery']), 'March', $s['users']['finance']);
    $march = fn () => ClientProfitability::calculate($s['client'], null, '2026-03-01', '2026-03-31');
    expect($march()['status'])->toBe('complete')->and($march()['figures']['client_borne_expenses_minor'])->toBe(100_000)->and($march()['figures']['unpaid_client_expenses_minor'])->toBe(100_000);

    accDeliver($s, accOrder($s, 'D2', '20', '2026-04-01'), '20', '2026-04-04');                 // a later period's order and delivery
    accExpense($s, ['category' => 'packaging', 'amount' => 5_000, 'spent_on' => '2026-04-05']);
    expect($march()['status'])->toBe('complete')->and(ClientProfitability::calculate($s['client'], null, '2026-04-01', '2026-04-30')['status'])->toBe('incomplete');

    accExpense($s, ['category' => 'advertising', 'amount' => 7_000, 'spent_on' => '2026-03-20']);   // March itself changes
    expect($march()['status'])->toBe('incomplete')->and($march()['declaration']['status'])->toBe('out_of_date')->and($march()['net_profit_minor'])->toBeNull();
});

it('blocks final profit on a missing price, a missing cost or unknown ownership, and an unconfirmed zero category', function () {
    $s = accStock($this);
    $o = accDeliver($s, accOrder($s, 'M1', '100', '2026-03-01'), '100', '2026-03-05');
    expect(fn () => ClientProfitDeclarations::declare($s['client'], null, '2026-03-01', '2026-03-31', [], null, $s['users']['finance']))->toThrow(RuntimeException::class, 'genuinely had no cost');

    $o->items()->update(['unit_price' => 0]);
    expect(implode(' ', ClientProfitability::calculate($s['client'], null, '2026-03-01', '2026-03-31')['missing_data']))->toContain('no verified selling price');
    $o->items()->update(['unit_price' => 60_000]);
    $s['line']->update(['approved_price' => null]);
    expect(implode(' ', ClientProfitability::calculate($s['client'], null, '2026-03-01', '2026-03-31')['missing_data']))->toContain('not approved');
    expect(Client::count())->toBe(1);
});
