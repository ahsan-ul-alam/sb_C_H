<?php

use App\Models\ClientOrder;
use App\Models\CommissionAdjustment;
use App\Models\JournalEntry;
use App\Models\OrderRefund;
use App\Models\OrderRefundAllocation;
use App\Services\ClientSettlementService;
use App\Services\OrderRefunds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** Request (client management), approve (Finance) and pay (Finance) one refund; returns it. Amounts are in major units (BDT). */
function runRefund(array $s, ClientOrder $o, string $amount, string $class, array $decide = ['note' => 'contract clause 7'], bool $pay = true): OrderRefund
{
    $co = $s['co'];
    $co->post('clients', 'product.refunds.store', ['amount' => $amount, 'classification' => $class, 'reason' => 'test'], $o)->assertSessionHasNoErrors();
    $r = OrderRefund::latest('id')->firstOrFail();
    $co->post('finance', 'product.refunds.decide', ['decision' => 'approve'] + $decide, $r);
    if ($pay) {
        $co->post('finance', 'product.refunds.pay', ['paid_on' => '2026-02-10', 'reference' => 'TX-'.$r->id, 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()], $r);
    }

    return $r->refresh();
}

it('lowers the commission once for a price reduction, with an auditable correction that a later return keeps honouring', function () {
    $s = integrationStock($this);
    $o = refundDelivered($s);                                                   // delivered 5,500,000 · commission 550,000 · collected 4,000,000
    $r = runRefund($s, $o, '10000', 'price_reduction');
    expect($r->status)->toBe('paid')->and($r->commission_treatment)->toBe('reduce');
    $adj = CommissionAdjustment::firstOrFail();
    expect($adj->commission_before)->toBe(550_000)->and($adj->commission_after)->toBe(450_000)->and($o->refresh()->commission)->toBe(450_000)->and($o->net_value)->toBe(4_500_000);

    $pay = ['paid_on' => '2026-02-10', 'reference' => 'TX-again', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()];
    $s['co']->post('finance', 'product.refunds.pay', $pay, $r)->assertSessionHas('error');                 // already paid: no second payout, no second reversal
    expect(CommissionAdjustment::count())->toBe(1)->and($o->refresh()->commission)->toBe(450_000);

    // Ten units are then returned: 90 x 55,000 = 4,950,000 delivered, less the 1,000,000 price reduction = 3,950,000 base -> 395,000 (not 495,000, not 345,000).
    $item = $o->items()->first();
    $s['co']->post('clients', 'product.orders.return', ['returned' => [$item->id => '10']], $o)->assertSessionHasNoErrors();
    expect($o->refresh()->net_value)->toBe(3_950_000)->and($o->commission)->toBe(395_000)->and(journalBalanced())->toBeTrue();
});

it('shows refunds, allocations and commission corrections on the screens and in the exported report', function () {
    $s = integrationStock($this);
    $o = refundDelivered($s);
    runRefund($s, $o, '10000', 'price_reduction');
    $s['co']->as('finance')->get(route('product.refunds'))->assertOk()->assertSee('Price reduction')->assertSee('Collection #');
    $s['co']->as('finance')->get(route('stock.sales'))->assertOk()->assertSee('Refunded to customers');
    foreach (['refunds', 'quarantine', 'location-stock'] as $type) {
        $s['co']->as('finance')->get(route('stock.report', $type))->assertOk();
    }
    $csv = $s['co']->as('finance')->get(route('stock.report', ['type' => 'refunds', 'export' => 'csv']))->streamedContent();
    expect($csv)->toContain('Price reduction')->and($csv)->toContain('1000.00')->and($csv)->toContain('reduce');
});

it('moves cash but not commission for goodwill, delivery-fee and goods-return refunds', function () {
    $s = integrationStock($this);
    $o = refundDelivered($s);
    foreach (['goodwill' => '500', 'delivery_fee' => '300'] as $class => $amount) {
        expect(runRefund($s, $o, $amount, $class)->status)->toBe('paid');
    }
    expect($o->refresh()->commission)->toBe(550_000)->and(CommissionAdjustment::count())->toBe(0)->and(ClientSettlementService::fundsHeld($s['client']))->toBe(4_000_000 - 80_000);
    expect(JournalEntry::where('source_type', 'order_refund')->count())->toBe(2)->and(journalBalanced())->toBeTrue();
});

it('allocates a refund across several collections, exactly, and never lets cumulative refunds exceed them', function () {
    $s = integrationStock($this);
    $o = refundDelivered($s);
    $s['co']->post('clients', 'product.orders.collect', ['amount' => '15000', 'collected_on' => '2026-02-10', 'idempotency_key' => (string) Str::uuid()], $o)->assertSessionHasNoErrors(); // collected 5,500,000 in two
    $r = runRefund($s, $o, '45000', 'sale_cancellation');                     // 4,500,000 across 4,000,000 + 500,000
    expect($r->status)->toBe('paid')->and(OrderRefundAllocation::where('order_refund_id', $r->id)->orderBy('id')->pluck('amount')->all())->toBe([4_000_000, 500_000])
        ->and((int) OrderRefundAllocation::sum('amount'))->toBe(4_500_000);
    $collections = $o->collections()->get();
    expect(OrderRefunds::collectionRoom($collections[0]))->toBe(0)->and(OrderRefunds::collectionRoom($collections[1]))->toBe(1_000_000)->and(OrderRefunds::refundable($o))->toBe(1_000_000);
    $s['co']->post('clients', 'product.refunds.store', ['amount' => '10000.01', 'classification' => 'goodwill', 'reason' => 'x'], $o)->assertSessionHas('error');   // 1 paisa too much
    $s['co']->post('clients', 'product.refunds.store', ['amount' => '10000', 'classification' => 'goodwill', 'reason' => 'x'], $o)->assertSessionHasNoErrors();
    expect(OrderRefunds::refundable($o))->toBe(0);
    $s['co']->post('clients', 'product.refunds.store', ['amount' => '0.01', 'classification' => 'goodwill', 'reason' => 'x'], $o)->assertSessionHas('error');         // pending refunds count too
    $pending = OrderRefund::latest('id')->firstOrFail();
    $s['co']->post('clients', 'product.refunds.cancel', [], $pending)->assertSessionHasNoErrors();                                                                   // cancelling releases the room
    expect(OrderRefunds::refundable($o))->toBe(1_000_000)->and($o->refresh()->commission)->toBe(100_000);                                                         // commission follows the 1,000,000 base left
});

it('blocks a Sellbees-borne service-failure refund and makes Finance decide an "Other" refund', function () {
    $s = integrationStock($this);
    $o = refundDelivered($s);
    $r = runRefund($s, $o, '1000', 'service_failure');
    expect($r->status)->toBe('approved')->and(JournalEntry::where('source_type', 'order_refund')->count())->toBe(0)->and(ClientSettlementService::fundsHeld($s['client']))->toBe(4_000_000);

    $other = runRefund($s, $o, '1000', 'other', ['note' => 'agreed with client'], false);
    expect($other->status)->toBe('requested');                                                                  // approval refused without a stated treatment
    $s['co']->post('finance', 'product.refunds.decide', ['decision' => 'approve', 'note' => 'agreed with client', 'treatment' => 'reduce', 'responsible' => 'client'], $other)->assertSessionHasNoErrors();
    $s['co']->post('finance', 'product.refunds.pay', ['paid_on' => '2026-02-10', 'reference' => 'TX-o', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()], $other)->assertSessionHasNoErrors();
    expect($other->refresh()->status)->toBe('paid')->and($o->refresh()->commission)->toBe(540_000);            // base 5,500,000 - 100,000
});

it('leaves a commission that was already netted untouched and flags it for review instead of reversing it', function () {
    $s = integrationStock($this);
    $o = refundDelivered($s);
    $s['co']->post('finance', 'product.settlements.store', ['pay' => '0', 'paid_on' => '2026-02-10', 'reference' => 'NET', 'financial_account_id' => $s['bank']->id, 'idempotency_key' => (string) Str::uuid()], $s['client'])->assertSessionHasNoErrors();
    expect(ClientSettlementService::fundsHeld($s['client']->refresh()))->toBe(3_450_000);                       // the 550,000 commission was netted from the funds held
    $r = runRefund($s, $o, '10000', 'price_reduction');
    expect($r->status)->toBe('paid')->and($r->commission_treatment)->toBe('review')->and($o->refresh()->commission)->toBe(550_000)->and(CommissionAdjustment::count())->toBe(0)
        ->and(ClientSettlementService::fundsHeld($s['client']))->toBe(2_450_000)->and(journalBalanced())->toBeTrue();
});
