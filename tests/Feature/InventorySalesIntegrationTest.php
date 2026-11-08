<?php

use App\Models\AccountingPeriod;
use App\Models\ClientOrder;
use App\Models\InventoryHandover;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OrderRefund;
use App\Services\ClientSettlementService;
use App\Services\Inventory;
use App\Services\InventoryReports;
use App\Services\OrderRefunds;
use App\Services\Receiving;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('runs receive, locate, discount, dispatch from locations, partial delivery, collection, refund, return and reconciliation with exact figures', function () {
    $s = integrationStock($this);
    $line = $s['line'];
    $m = (string) $s['main']->id;
    $d = (string) $s['dhk']->id;

    // Receiving: 190 accepted (110 + 80), 10 rejected, placed exactly once.
    expect(Inventory::balances($line->refresh())['available'])->toBe('190.00')->and((string) $line->received_quantity)->toBe('200.00')->and(loc($s))->toBe([$m => '110.00', $d => '80.00']);
    expect(fn () => Receiving::receive($line, '10', '10', '0', '2026-02-10', $s['main']->id, 'GRN-2', null, null, $s['user']))->toThrow(RuntimeException::class, 'already recorded');

    // 150 sold at BDT 600 less BDT 50: reserved, then dispatched from the locations that hold them (MAIN 110 + DHK 40).
    $co = $s['co'];
    $co->post('finance', 'product.orders.store', ['project_id' => $s['project']->id, 'reference' => 'O1', 'ordered_on' => '2026-02-10', 'sales_channel' => 'website', 'payment_method' => 'cod', 'delivery_method' => 'courier',
        'discount_reason' => 'launch', 'items' => [['product_id' => $s['product']->id, 'quantity' => '150', 'price' => '600', 'discount' => '50']]])->assertSessionHasNoErrors();
    $o = ClientOrder::where('reference', 'O1')->firstOrFail();
    $co->post('clients', 'product.orders.confirm', [], $o)->assertSessionHasNoErrors();
    expect(Inventory::balances($line)['available'])->toBe('40.00')->and(Inventory::balances($line)['reserved'])->toBe('150.00')->and(loc($s))->toBe([$m => '110.00', $d => '80.00']);
    $co->post('clients', 'product.orders.dispatch', [], $o)->assertSessionHasNoErrors();
    $co->post('clients', 'product.orders.dispatch', [], $o);                                       // duplicate dispatch changes nothing
    expect(loc($s))->toBe([$d => '40.00'])->and(Inventory::balances($line)['dispatched'])->toBe('150.00');

    // Deliver 100; the other 50 come back to the places they left, but are not sellable until inspected.
    $item = $o->items()->first();
    $co->post('clients', 'product.orders.deliver', ['delivered' => [$item->id => '100']], $o)->assertSessionHasNoErrors();
    expect(Inventory::balances($line)['available'])->toBe('40.00')->and(Inventory::balances($line)['returned'])->toBe('50.00')->and(loc($s))->toBe([$m => '50.00', $d => '40.00']);
    $co->post('clients', 'product.items.inspect', ['usable' => '50', 'damaged' => '0'], $item)->assertSessionHasNoErrors();
    expect(Inventory::balances($line)['available'])->toBe('90.00')->and(Inventory::balances($line)['delivered'])->toBe('100.00')->and(loc($s))->toBe([$m => '50.00', $d => '40.00']);
    $o->refresh();
    expect($o->net_value)->toBe(5_500_000)->and($o->commission)->toBe(550_000);

    // Collection of BDT 40,000 is the client's money (Dr cash / Cr client funds).
    $co->post('clients', 'product.orders.collect', ['amount' => '40000', 'collected_on' => '2026-02-10', 'idempotency_key' => (string) Str::uuid()], $o)->assertSessionHasNoErrors();
    expect(ClientSettlementService::fundsHeld($s['client']))->toBe(4_000_000);

    // Refund BDT 10,000: requested by one person, approved by another, paid once from a named account.
    $co->post('clients', 'product.refunds.store', ['amount' => '10000', 'classification' => 'return_of_goods', 'reason' => 'customer sent 10 back'], $o)->assertSessionHasNoErrors();
    $refund = OrderRefund::firstOrFail();
    $co->post('finance', 'product.refunds.pay', ['paid_on' => '2026-02-10', 'reference' => 'TX1', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()], $refund)->assertSessionHas('error'); // not approved yet
    $co->post('clients', 'product.refunds.decide', ['decision' => 'approve'], $refund)->assertForbidden();                         // client management cannot approve
    $co->post('finance', 'product.refunds.decide', ['decision' => 'approve'], $refund)->assertSessionHasNoErrors();
    $key = (string) Str::uuid();
    $before = JournalEntry::count();
    $co->post('finance', 'product.refunds.pay', ['paid_on' => '2026-02-10', 'reference' => 'TX1', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => $key], $refund)->assertSessionHasNoErrors();
    $co->post('finance', 'product.refunds.pay', ['paid_on' => '2026-02-10', 'reference' => 'TX1', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => $key], $refund); // duplicate submission
    expect(JournalEntry::count())->toBe($before + 1)->and($refund->refresh()->status)->toBe('paid');
    $entry = JournalEntry::where('source_type', 'order_refund')->firstOrFail();
    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
    expect($lines->firstWhere('account', 'client.funds')->debit)->toBe(1_000_000)->and($lines->firstWhere('account', 'cash')->credit)->toBe(1_000_000);
    expect(ClientSettlementService::fundsHeld($s['client']))->toBe(3_000_000)->and(OrderRefunds::refundable($o->refresh()))->toBe(3_000_000);
    $co->post('finance', 'product.refunds.pay', ['paid_on' => '2026-02-10', 'reference' => 'TX2', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()], $refund)->assertSessionHas('error'); // already paid

    // The return follows: 10 units come back, 8 resalable, 2 damaged. Commission follows the 90 that stay delivered, once.
    $co->post('clients', 'product.orders.return', ['returned' => [$item->id => '10']], $o)->assertSessionHasNoErrors();
    $co->post('clients', 'product.items.inspect', ['usable' => '8', 'damaged' => '2'], $item)->assertSessionHasNoErrors();
    $o->refresh();
    expect($o->commission)->toBe(495_000)->and($o->net_value)->toBe(4_950_000)
        ->and(Inventory::balances($line)['available'])->toBe('98.00')->and(Inventory::balances($line)['damaged'])->toBe('2.00')->and(Inventory::balances($line)['returned'])->toBe('0.00');
    expect(Inventory::reconciles($line))->toBeTrue()->and(loc($s))->toBe([$m => '58.00', $d => '40.00']);   // the 10 returned went back to MAIN (where most of the order left from); the 2 damaged then left MAIN's on-hand

    // Reconciliation: stock versus orders, global versus locations, client money, balanced journal, no Sellbees COGS.
    $rec = InventoryReports::build('reconciliation')['rows'][0];
    $loc = InventoryReports::build('location-reconciliation')['rows'][0];
    expect($rec[8])->toBe('0.00')->and($rec[9])->toBe('Yes')->and($loc[6])->toBe('0.00')->and($loc[8])->toBe('Reconciled')->and($loc[2])->toBe('98.00')
        ->and(InventoryHandover::count())->toBe(0)->and(journalBalanced())->toBeTrue();
    expect(OrderRefund::where('status', 'paid')->sum('amount'))->toBe(1_000_000);
    $cr = InventoryReports::build('collections-refunds')['rows'][0];
    expect($cr[2])->toBe('40000.00')->and($cr[3])->toBe('10000.00')->and($cr[4])->toBe('30000.00');
    expect(InventoryReports::build('stock-aging')['rows'][0][5])->toBe('0-30 days');
});

it('refuses over-refunds, self-approval, unjustified no-return refunds, inactive accounts and closed months', function () {
    $s = integrationStock($this);
    $o = refundDelivered($s);
    $co = $s['co'];
    // Collected 4,000,000: more than that can never be claimed.
    $co->post('clients', 'product.refunds.store', ['amount' => '40001', 'classification' => 'return_of_goods', 'reason' => 'x'], $o)->assertSessionHas('error');
    expect(OrderRefund::count())->toBe(0);
    $co->post('finance', 'product.refunds.store', ['amount' => '1000', 'classification' => 'goodwill', 'reason' => 'goodwill'], $o)->assertSessionHasNoErrors();
    $r = OrderRefund::firstOrFail();
    $co->post('finance', 'product.refunds.decide', ['decision' => 'approve', 'note' => 'ok'], $r)->assertSessionHas('error');          // the requester cannot approve
    $co->post('board', 'product.refunds.decide', ['decision' => 'approve'], $r)->assertSessionHas('error');                           // no-return needs the contractual justification
    expect($r->refresh()->status)->toBe('requested');
    $co->post('board', 'product.refunds.decide', ['decision' => 'approve', 'note' => 'contract clause 7 allows goodwill refunds'], $r)->assertSessionHasNoErrors();

    $pay = fn (array $over = []) => $co->post('finance', 'product.refunds.pay', $over + ['paid_on' => '2026-02-10', 'reference' => 'TX9', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()], $r);
    AccountingPeriod::create(['month' => '2026-01', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $s['co']->users['finance']->id]);
    $pay(['paid_on' => '2026-01-15'])->assertSessionHas('error');                                                                    // closed month
    $s['bank']->update(['is_active' => false]);
    $pay()->assertSessionHas('error');                                                                                               // inactive account
    expect($r->refresh()->status)->toBe('approved')->and(JournalEntry::where('source_type', 'order_refund')->count())->toBe(0);
    $s['bank']->update(['is_active' => true]);
    $pay()->assertSessionHasNoErrors();
    expect($r->refresh()->status)->toBe('paid')->and(journalBalanced())->toBeTrue();
});

it('never uses Sellbees cash when the money held for the client is not enough', function () {
    $s = integrationStock($this);
    $o = refundDelivered($s);
    $co = $s['co'];
    // The client has been paid out everything held, so there is no client money left to refund from.
    $co->post('finance', 'product.settlements.store', ['pay' => '34500', 'paid_on' => '2026-02-10', 'reference' => 'SETTLE', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()], $s['client']);
    expect(ClientSettlementService::fundsHeld($s['client']->refresh()))->toBe(0);
    $co->post('clients', 'product.refunds.store', ['amount' => '10000', 'classification' => 'return_of_goods', 'reason' => 'x'], $o)->assertSessionHasNoErrors();
    $r = OrderRefund::firstOrFail();
    $co->post('finance', 'product.refunds.decide', ['decision' => 'approve'], $r)->assertSessionHasNoErrors();
    $co->post('finance', 'product.refunds.pay', ['paid_on' => '2026-02-10', 'reference' => 'TX', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()], $r)->assertSessionHas('error');
    expect($r->refresh()->status)->toBe('approved')->and(JournalEntry::where('source_type', 'order_refund')->count())->toBe(0);
});
