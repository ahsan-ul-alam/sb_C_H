<?php

use App\Models\Client;
use App\Models\ClientExpense;
use App\Models\ClientProfitDeclaration;
use App\Models\JournalLine;
use App\Services\ClientProfitability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function expenseForm(array $s, array $over = []): array
{
    return $over + ['category' => 'delivery', 'amount' => '1000', 'spent_on' => '2026-02-12', 'responsible_party' => 'client', 'paid_status' => 'paid', 'reference' => 'COURIER-1',
        'allocations' => [['project_id' => $s['project']->id, 'amount' => (string) ($over['amount'] ?? '1000')]]];
}

/** Enter, review (a different Finance user) and approve (Board) an expense. */
function approvedExpense(array $s, array $over = []): ClientExpense
{
    $s['co']->post('finance', 'clients.expenses.store', expenseForm($s, $over), $s['client'])->assertSessionHasNoErrors()->assertSessionHas('status');
    $expense = ClientExpense::latest('id')->firstOrFail();
    $s['co']->post('multi', 'clients.expenses.review', [], $expense)->assertSessionHas('status');
    $s['co']->post('board', 'clients.expenses.decide', ['decision' => 'approve'], $expense)->assertSessionHas('status');

    return $expense->refresh();
}

function declareAll(array $s, array $zero)
{
    return $s['co']->post('finance', 'clients.profit.declare', ['zero' => $zero], $s['client']);
}

it('moves a client expense through entry, review and independent approval without touching the ledger', function () {
    $s = integrationStock($this);
    $co = $s['co'];
    $ledgerBefore = [JournalLine::count(), DB::table('expenses')->count()];

    $co->post('clients', 'clients.expenses.store', expenseForm($s), $s['client'])->assertForbidden();                              // client management may not enter
    $co->post('finance', 'clients.expenses.store', expenseForm($s, ['allocations' => [['project_id' => $s['project']->id, 'amount' => '999']]]), $s['client'])->assertSessionHas('error'); // split must add up
    $co->post('finance', 'clients.expenses.store', expenseForm($s), $s['client'])->assertSessionHas('status');
    $expense = ClientExpense::firstOrFail();
    $co->post('finance', 'clients.expenses.store', expenseForm($s), $s['client'])->assertSessionHas('error');                      // exact duplicate refused
    expect($expense->status)->toBe('entered')->and($expense->amount)->toBe(100_000);

    $co->post('finance', 'clients.expenses.review', [], $expense)->assertSessionHas('error');                                       // the enterer cannot review
    $co->post('board', 'clients.expenses.decide', ['decision' => 'approve'], $expense)->assertSessionHas('error');                  // not reviewed yet
    $co->post('multi', 'clients.expenses.review', [], $expense)->assertSessionHas('status');
    $co->post('multi', 'clients.expenses.decide', ['decision' => 'approve'], $expense)->assertForbidden();                          // Finance cannot approve
    $co->post('board', 'clients.expenses.decide', ['decision' => 'approve'], $expense)->assertSessionHas('status');
    expect($expense->fresh()->status)->toBe('approved')->and($expense->events()->pluck('event')->all())->toBe(['entered', 'reviewed', 'approved']);

    $co->post('finance', 'clients.expenses.reverse', ['reason' => 'wrong courier'], $expense)->assertSessionHas('status');
    expect($expense->fresh()->status)->toBe('reversed');
    $co->post('finance', 'clients.expenses.store', expenseForm($s), $s['client'])->assertSessionHas('status');                      // the corrected entry is accepted

    expect([JournalLine::count(), DB::table('expenses')->count()])->toBe($ledgerBefore);                                             // attribution only: no ledger posting
});

it('gives no profit until Finance declares, then the exact verified profit, and withdraws it when anything changes', function () {
    $s = integrationStock($this);
    $order = refundDelivered($s);                                    // 100 units delivered at 550.00 net (discounted), none returned
    $client = $s['client'];
    $price = (int) $s['line']->fresh()->approved_price;
    $sales = 100 * 55_000;
    $cost = 100 * $price;

    $first = ClientProfitability::calculate($client);
    expect($first['status'])->toBe('incomplete')->and($first['net_profit_minor'])->toBeNull()->and($first['figures']['net_sales_minor'])->toBe($sales)->and($first['figures']['sold_product_cost_minor'])->toBe($cost)
        ->and($first['missing_data'])->toContain('Finance has not declared that all client-borne expenses are recorded for this period.');

    approvedExpense($s, ['amount' => '1000']);
    declareAll($s, [])->assertSessionHas('error');                                                  // zero-cost categories were not confirmed
    expect(ClientProfitDeclaration::count())->toBe(0);
    $zero = array_values(array_diff(array_keys(ClientExpense::CATEGORIES), ['delivery']));
    declareAll($s, $zero)->assertSessionHas('status');

    $done = ClientProfitability::calculate($client);
    $commission = (int) $order->fresh()->commission;
    expect($done['status'])->toBe('complete')->and($done['figures']['client_borne_expenses_minor'])->toBe(100_000)->and($done['figures']['sellbees_commission_minor'])->toBe($commission)
        ->and($done['net_profit_minor'])->toBe($sales - $cost - 100_000 - $commission);
    // Unsold stock keeps its cost as an asset; it is not in the profit above.
    expect($done['unsold_inventory']['cost_minor'])->toBeGreaterThan(0)->and($done['unsold_inventory']['cost_minor'] % $price)->toBe(0);

    approvedExpense($s, ['amount' => '200', 'category' => 'packaging', 'reference' => 'BOX-1']);   // a change after the declaration
    $stale = ClientProfitability::calculate($client);
    expect($stale['status'])->toBe('incomplete')->and($stale['net_profit_minor'])->toBeNull()->and($stale['declaration']['status'])->toBe('out_of_date');
});

it('refuses to declare while sales, ownership, cost or refund treatment is unresolved', function () {
    $s = integrationStock($this);
    refundDelivered($s);
    $zero = array_keys(ClientExpense::CATEGORIES);
    $s['line']->lot->update(['ownership' => null]);                                                 // ownership not verified

    $result = ClientProfitability::calculate($s['client']);
    expect($result['status'])->toBe('incomplete')->and(implode(' ', $result['missing_data']))->toContain('ownership');
    declareAll($s, $zero)->assertSessionHas('error');
    expect(ClientProfitDeclaration::count())->toBe(0);
});

it('reports no_sales when the period has no eligible sales', function () {
    $s = integrationStock($this);
    $result = ClientProfitability::calculate($s['client'], null, '2026-01-01', '2026-01-31');
    expect($result['status'])->toBe('no_sales')->and($result['net_profit_minor'])->toBeNull();
});

it('handles returns, refunds and discounts without double counting', function () {
    $s = integrationStock($this);
    $order = refundDelivered($s);
    $item = $order->items()->first();
    $s['co']->post('clients', 'product.orders.return', ['returned' => [$item->id => '10']], $order)->assertSessionHasNoErrors();
    $result = ClientProfitability::calculate($s['client']);

    // 100 delivered - 10 returned = 90 units at the discounted 550.00; cost only for the 90 kept by the customer.
    expect($result['figures']['net_sales_minor'])->toBe(90 * 55_000)->and($result['figures']['sold_product_cost_minor'])->toBe(90 * (int) $s['line']->fresh()->approved_price)
        ->and($result['products'][0]['units_sold'])->toBe('90.00');
});

it('lets the auditor and Board view but not change, and keeps client expenses out of Sellbees reports', function () {
    $s = integrationStock($this);
    $co = $s['co'];
    $co->as('auditor')->get(route('clients.profitability', $s['client']))->assertOk()->assertSee('Client profitability');
    $co->as('auditor')->get(route('clients.expenses', $s['client']))->assertOk();
    $co->post('auditor', 'clients.profit.declare', [], $s['client'])->assertForbidden();
    $co->as('employee')->get(route('clients.profitability', $s['client']))->assertForbidden();
    expect(Client::count())->toBe(1);
});
