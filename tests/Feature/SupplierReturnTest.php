<?php

use App\Models\AccountingPeriod;
use App\Models\AdvanceSettlement;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnSettlement;
use App\Services\AdvanceSettlementService;
use App\Services\Inventory;
use App\Services\InventoryEngine;
use App\Services\InventoryHandoverService;
use App\Services\InventoryReconciliation;
use App\Services\InventoryValuation;
use App\Services\LedgerStatements;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingPayments;
use App\Services\SupplierReturnService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Phase 2D-2: supplier returns. Worked lot: 100 units at BDT 500 (BDT 50,000) with BDT 5,000 of capitalised transport: BDT 55,000 of inventory, BDT 550 (55,000 paisa) a unit.
| Twenty units carry 1,100,000 paisa: 1,000,000 of purchase and 100,000 of landed cost. "Today" is 2026-11-10.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'accrual', 'finance.sourcing_accrual_from' => '2026-11-01',
        'finance.sourcing_recognition_trigger' => null, 'finance.inventory_engine_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->board2 = $this->co->users['board2'];
    $this->ops = $this->co->users['clients'];
    FinancialAccount::factory()->asDefault()->create(['name' => 'City Bank']);
    $this->co->invest('A', '1000000', '2026-11-02');
    $this->supplier = Supplier::create(['name' => 'Uttara Buying House']);
    $this->client = $this->co->client('Return Client');
    $this->project = $this->co->project($this->client, 'Warehouse fit-out');
});

afterEach(fn () => Carbon::setTestNow());

function srVerify($expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

/**
 * A lot enrolled as company-owned, accepted and settled. prepay: the supplier was paid in advance (settled from the advance); otherwise the accepted goods are an
 * unpaid supplier payable of 5,000,000. Returns [lot, line].
 */
function srLot(bool $prepay = false, string $received = '100', ?string $accepted = null, ?string $ownership = 'company', int $price = 50_000): array
{
    $finance = test()->finance;
    $accepted ??= $received;
    $lot = Sourcing::createLot(['title' => 'Lot '.Str::random(4)], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => test()->supplier->id, 'description' => 'Zipper Hoodie', 'quantity' => $received, 'unit_price' => $price], $finance);
    $cost = Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => 500_000, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 10_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);
    $lot->refresh();
    InventoryEngine::decide(InventoryEngine::propose($lot, $ownership, 'Board resolution 2026/11 and the signed purchase order', '2026-11-01', $finance), true, test()->board);
    if ($prepay) {
        $payment = SourcingPayments::recordSupplierPayment($lot->refresh(), ['supplier_id' => test()->supplier->id, 'amount' => (int) round($price * (float) $received), 'paid_on' => '2026-11-03', 'method' => 'bank',
            'purchase_order' => 'PO-1', 'reference' => 'R'.Str::random(4), 'evidence_reference' => 'E-1', 'idempotency_key' => (string) Str::uuid()], $finance)->load('expense');
        srVerify($payment->expense);
    }
    SourcingArrival::record($line->refresh(), $received, $accepted, '2026-11-08', $finance, 'DN-'.$line->id);
    SourcingPayments::payCost($cost->refresh(), '2026-11-09', $finance);
    srVerify($cost->refresh()->expense);
    foreach (AdvanceSettlementService::propose($lot->refresh(), test()->supplier->id, $finance) as $s) {
        AdvanceSettlementService::decide($s, true, test()->board);
    }

    return [$lot->refresh(), $line->refresh()];
}

function srPay(SourcingLot $lot, int $amount): void
{
    $payment = SourcingPayments::recordSupplierPayment($lot->refresh(), ['supplier_id' => test()->supplier->id, 'amount' => $amount, 'paid_on' => '2026-11-09', 'method' => 'bank', 'reference' => 'PAY-'.Str::random(4),
        'idempotency_key' => (string) Str::uuid()], test()->finance)->load('expense');
    srVerify($payment->expense);
}

function srPropose($line, string $quantity = '20', array $over = []): SupplierReturn
{
    return SupplierReturnService::propose(test()->ops, $line, $over + ['kind' => 'accepted', 'quantity' => $quantity, 'reason' => 'Wrong size delivered', 'return_reference' => 'RTN-'.Str::random(5),
        'physical_returned_on' => '2026-11-10', 'evidence_reference' => 'Carrier receipt CR-4411', 'claimed_amount' => 1_100_000, 'expected_method' => 'mixed'], (string) Str::uuid());
}

/** Request, accept, verify and approve a return with the given allocation. */
function srApprove($line, string $quantity = '20', int $p = 0, int $k = 0, int $r = 0, ?string $reason = null, ?int $accepted = null, array $over = [], ?string $class = null): SupplierReturn
{
    $return = srPropose($line, $quantity, $over);
    $accepted ??= $p + $k + $r;
    SupplierReturnService::recordAcceptance($return, test()->finance, $accepted >= (int) $return->claimed_amount ? 'accepted' : 'partial', $accepted, 'CN-'.Str::random(5), 'Supplier written acceptance');
    SupplierReturnService::verify($return->refresh(), test()->co->users['multi'], 'Evidence, acceptance and allocation checked', $p, $k, $r, $reason, $class ?? ($reason ? 'recovery_shortfall' : null));
    SupplierReturnService::decide($return->refresh(), true, test()->board);

    return $return->refresh();
}

function srAccounts(SupplierReturn $return): array
{
    return JournalEntry::where('source_type', 'supplier_return')->where('source_id', $return->id)->firstOrFail()->lines->map(fn ($l) => [$l->account, (int) $l->debit, (int) $l->credit])->sortBy(0)->values()->all();
}

it('returns goods before payable settlement: the payable falls by the recovery, the unrecovered freight is its own expense, and 20 units and 1,100,000 leave inventory', function () {
    [$lot, $line] = srLot();
    expect(dr('assets.inventory'))->toBe(5_500_000)->and(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id))->toBe(5_000_000);

    $return = srPropose($line);
    expect($return->status)->toBe('requested')->and(dr('assets.inventory'))->toBe(5_500_000);   // a request posts nothing
    $return = srApprove($line, p: 1_000_000, reason: 'Freight on the returned goods is not refundable', over: ['return_reference' => 'RTN-A1'], class: 'nonrefundable_freight');

    expect(srAccounts($return))->toBe([['assets.inventory', 0, 1_100_000], ['liabilities', 1_000_000, 0], ['supplier_return_difference', 100_000, 0]])
        ->and($return->purchase_value)->toBe(1_000_000)->and($return->landed_value)->toBe(100_000)->and($return->difference_value)->toBe(100_000)
        ->and(dr('assets.inventory'))->toBe(4_400_000)->and(InventoryValuation::lineValue($line->refresh()))->toBe(4_400_000)->and(InventoryValuation::lineFigures($line)['quantity'])->toBe('80.00')
        ->and(Inventory::balances($line)['available'])->toBe('80.00')->and(Inventory::reconciles($line))->toBeTrue()
        ->and(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id))->toBe(4_000_000)
        ->and(dr('supplier_return_difference'))->toBe(100_000)
        ->and(InventoryReconciliation::company()['ok'])->toBeTrue()->and(ledgerAudit()['unbalanced'])->toBe(0);
});

it('has the supplier reimburse the landed cost too: nothing is left unrecovered', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, p: 1_100_000);

    expect(srAccounts($return))->toBe([['assets.inventory', 0, 1_100_000], ['liabilities', 1_100_000, 0]])->and($return->difference_value)->toBe(0)->and(dr('supplier_return_difference'))->toBe(0)
        ->and(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id))->toBe(3_900_000);
});

it('returns goods after the payable was paid: no payable to reduce, so recovery is a credit note, not cash and not income', function () {
    [$lot, $line] = srLot();
    srPay($lot, 5_000_000);
    expect(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id))->toBe(0);

    $return = srPropose($line);
    SupplierReturnService::recordAcceptance($return, $this->finance, 'accepted', 1_100_000, 'CN-7001', 'Credit note received');
    expect(fn () => SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 1_100_000))->toThrow(RuntimeException::class, 'still owed to this supplier');
    SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 0, 1_100_000);
    SupplierReturnService::decide($return->refresh(), true, $this->board);

    expect(srAccounts($return->refresh()))->toBe([['assets.inventory', 0, 1_100_000], ['supplier.credit', 1_100_000, 0]])
        ->and(dr('income.other'))->toBe(0)->and(SupplierReturnService::balances($return)['credit_open'])->toBe(1_100_000);
});

it('accepts a partial answer from the supplier: only the accepted part is recognised and the disputed part is tracked, never booked', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, k: 800_000, reason: 'The supplier disputes the landed cost and a restocking charge', accepted: 800_000);

    $b = SupplierReturnService::balances($return);
    expect($return->acceptance)->toBe('partial')->and($b['claimed'])->toBe(1_100_000)->and($b['accepted'])->toBe(800_000)->and($b['disputed'])->toBe(300_000)->and($b['credit_total'])->toBe(800_000)
        ->and(srAccounts($return))->toBe([['assets.inventory', 0, 1_100_000], ['supplier.credit', 800_000, 0], ['supplier_return_difference', 300_000, 0]]);
    // A claim that is only disputed or unanswered recognises nothing and cannot be approved.
    $open = srPropose($line, '5', ['claimed_amount' => 275_000]);
    SupplierReturnService::recordAcceptance($open, $this->finance, 'disputed', null, null, 'Supplier says goods were used');
    expect(fn () => SupplierReturnService::verify($open->refresh(), $this->co->users['multi'], 'Checked'))->toThrow(RuntimeException::class, 'not accepted any recovery');
});

it('applies a credit note to a payable in parts, never above the credit or the payable, and only with a Board decision', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, k: 1_100_000);
    $apply = fn (int $amount, string $key) => SupplierReturnService::proposeSettlement($return->refresh(), $this->finance, 'credit_applied', $amount, 'Invoice INV-2201', '2026-11-10', null, $key);

    $liabilities = dr('liabilities');
    $first = $apply(300_000, (string) Str::uuid());
    expect(dr('liabilities'))->toBe($liabilities)->and($first->status)->toBe('pending');                       // nothing is posted before the Board decides
    expect(fn () => SupplierReturnService::decideSettlement($first, true, $this->finance))->toThrow(RuntimeException::class, 'cannot approve a settlement you recorded')
        ->and(fn () => SupplierReturnService::decideSettlement($first, true, $this->co->users['multi']))->toThrow(RuntimeException::class, 'Only a Board member');
    SupplierReturnService::decideSettlement($first, true, $this->board);
    $entry = JournalEntry::where('source_type', 'supplier_return_settlement')->where('source_id', $first->id)->firstOrFail();
    expect($entry->lines->map(fn ($l) => [$l->account, (int) $l->debit, (int) $l->credit])->sortBy(0)->values()->all())->toBe([['liabilities', 300_000, 0], ['supplier.credit', 0, 300_000]])
        ->and(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id))->toBe(4_700_000)->and(SupplierReturnService::balances($return->refresh())['credit_open'])->toBe(800_000);

    expect(fn () => $apply(900_000, (string) Str::uuid()))->toThrow(RuntimeException::class, 'still unapplied')       // more than the credit left
        ->and(fn () => SupplierReturnService::decideSettlement($first, true, $this->board))->toThrow(RuntimeException::class, 'Already decided');
    $second = $apply(800_000, (string) Str::uuid());
    SupplierReturnService::decideSettlement($second, true, $this->board);
    expect(SupplierReturnService::balances($return->refresh())['credit_open'])->toBe(0)->and(InventoryReconciliation::supplierReturns()['ok'])->toBeTrue();
});

it('recognises a supplier receivable and collects it in parts: cash settles the receivable and is never income or a second credit', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, r: 1_100_000);
    expect(srAccounts($return))->toBe([['assets.inventory', 0, 1_100_000], ['assets.supplier_receivable', 1_100_000, 0]])->and(dr('cash'))->toBe(dr('cash'));
    $cash = dr('cash');
    $account = FinancialAccount::firstOrFail();

    expect(fn () => SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 400_000, 'Bank statement line 88', '2026-11-10', null, (string) Str::uuid()))->toThrow(RuntimeException::class, 'financial account')
        ->and(fn () => SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 400_000, 'x', '2026-11-10', $account->id, (string) Str::uuid()))->toThrow(RuntimeException::class, 'bank receipt');
    $part = SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 400_000, 'Bank statement line 88', '2026-11-10', $account->id, (string) Str::uuid());
    SupplierReturnService::decideSettlement($part, true, $this->board);
    $entry = JournalEntry::where('source_type', 'supplier_return_settlement')->where('source_id', $part->id)->firstOrFail();
    expect($entry->lines->map(fn ($l) => [$l->account, (int) $l->debit, (int) $l->credit])->sortBy(0)->values()->all())->toBe([['assets.supplier_receivable', 0, 400_000], ['cash', 400_000, 0]])
        ->and(dr('cash'))->toBe($cash + 400_000)->and(dr('assets.supplier_receivable'))->toBe(700_000)->and(dr('income.other'))->toBe(0)->and(SupplierReturnService::balances($return->refresh())['cash_received'])->toBe(400_000);

    // A refund above what is still owed is refused, and two pending requests cannot together collect more than was recognised.
    expect(fn () => SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 800_000, 'Bank statement line 90', '2026-11-10', $account->id, (string) Str::uuid()))->toThrow(RuntimeException::class, 'recognised once');
    $one = SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 500_000, 'Bank statement line 91', '2026-11-10', $account->id, (string) Str::uuid());
    expect(fn () => SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 300_000, 'Bank statement line 92', '2026-11-10', $account->id, (string) Str::uuid()))->toThrow(RuntimeException::class, 'recognised once');
    SupplierReturnService::decideSettlement($one, true, $this->board);
    SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 200_000, 'Bank statement line 93', '2026-11-10', $account->id, ($key = (string) Str::uuid()));
    expect(SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 200_000, 'Bank statement line 93', '2026-11-10', $account->id, $key)->id)->toBe(SupplierReturnSettlement::latest('id')->first()->id)
        ->and(SupplierReturnSettlement::count())->toBe(3);                                       // the same submission twice is one settlement
});

it('settles a mixed recovery: payable 440,000, credit note 385,000 and receivable 275,000 recover the whole 1,100,000, and the later refund settles the receivable only', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, p: 440_000, k: 385_000, r: 275_000);

    expect(srAccounts($return))->toBe([['assets.inventory', 0, 1_100_000], ['assets.supplier_receivable', 275_000, 0], ['liabilities', 440_000, 0], ['supplier.credit', 385_000, 0]])
        ->and($return->difference_value)->toBe(0);
    $account = FinancialAccount::firstOrFail();
    $refund = SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 275_000, 'Bank statement line 101', '2026-11-10', $account->id, (string) Str::uuid());
    SupplierReturnService::decideSettlement($refund, true, $this->board);

    $b = SupplierReturnService::balances($return->refresh());
    expect($b['payable_reduction'])->toBe(440_000)->and($b['credit_open'])->toBe(385_000)->and($b['receivable_open'])->toBe(0)->and($b['cash_received'])->toBe(275_000)
        ->and(dr('assets.supplier_receivable'))->toBe(0)->and(dr('inventory_loss'))->toBe(0)->and(dr('income.other'))->toBe(0);
    $report = InventoryReconciliation::supplierReturns();
    expect($report['ok'])->toBeTrue()->and($report['rows'][0]['cost_removed'])->toBe(1_100_000)->and($report['rows'][0]['credit_open'])->toBe(385_000)->and($report['rows'][0]['remaining_supplier_obligation'])->toBe(4_560_000)
        ->and($report['credit_gl'])->toBe($report['credit_open'])->and($report['receivable_gl'])->toBe($report['receivable_open']);
});

it('refuses a recovery above the carrying value, an allocation above what the supplier accepted, and an unexplained shortfall', function () {
    [$lot, $line] = srLot();
    $return = srPropose($line, '20', ['claimed_amount' => 2_000_000]);
    SupplierReturnService::recordAcceptance($return, $this->finance, 'accepted', 2_000_000, 'CN-9001');
    expect(fn () => SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 0, 0, 1_200_000))->toThrow(RuntimeException::class, 'above the carrying value')
        ->and(fn () => SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 0, 0, 900_000))->toThrow(RuntimeException::class, 'is not fully recovered');
    $partial = srPropose($line, '5', ['claimed_amount' => 100_000]);
    SupplierReturnService::recordAcceptance($partial, $this->finance, 'accepted', 100_000, 'CN-9002');
    expect(fn () => SupplierReturnService::verify($partial->refresh(), $this->co->users['multi'], 'Checked', 0, 0, 200_000, 'x reason'))->toThrow(RuntimeException::class, 'more than the supplier accepted');
});

it('prevents a credit, a return document or the same physical units from being recorded twice', function () {
    [$lot, $line] = srLot();
    $first = srApprove($line, '20', k: 1_100_000, over: ['return_reference' => 'RTN-DUP']);
    $doc = SupplierReturn::firstOrFail()->supplier_document;

    expect(fn () => srPropose($line, '5', ['return_reference' => 'RTN-DUP']))->toThrow(RuntimeException::class, 'one physical return is recorded once');
    $other = srPropose($line, '5', ['claimed_amount' => 275_000]);
    expect(fn () => SupplierReturnService::recordAcceptance($other, $this->finance, 'accepted', 275_000, $doc))->toThrow(RuntimeException::class, 'recognised and collected once');
    // Units already returned are not there to return again, and open requests count against the same units.
    expect(fn () => srPropose($line, '81'))->toThrow(RuntimeException::class, 'Only 75.00')
        ->and(fn () => srPropose($line, '76'))->toThrow(RuntimeException::class, 'Only 75.00');
    $key = (string) Str::uuid();
    $a = SupplierReturnService::propose($this->ops, $line->refresh(), ['kind' => 'accepted', 'quantity' => '10', 'reason' => 'x', 'return_reference' => 'RTN-K1', 'physical_returned_on' => '2026-11-10', 'evidence_reference' => 'CR-1111'], $key);
    expect(SupplierReturnService::propose($this->ops, $line, ['kind' => 'accepted', 'quantity' => '10'], $key)->id)->toBe($a->id)
        ->and(fn () => SupplierReturnService::decide($first, true, $this->board))->toThrow(RuntimeException::class, 'Already decided');
});

it('never consumes client-owned stock, handed-over units, reserved units or units in an open loss case', function () {
    [$clientLot, $clientLine] = srLot(ownership: 'client');
    expect(fn () => srPropose($clientLine))->toThrow(RuntimeException::class, 'Only Sellbees-owned');

    [$lot, $line] = srLot();
    $allocation = Sourcing::allocate($lot->refresh(), ['project_id' => $this->project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '90'], $this->finance);
    $handover = InventoryHandoverService::propose($allocation, '90', '2026-11-09', '2026-11-09', 'Signed receiving note RN-1', null, (string) Str::uuid(), $this->finance);
    InventoryHandoverService::decide($handover, true, $this->board);
    expect(Inventory::sliceBalances($line, null)['available'])->toBe('10.00')
        ->and(fn () => srPropose($line, '20'))->toThrow(RuntimeException::class, 'Only 10.00')       // the 90 handed over are the client's
        ->and(fn () => srPropose($line, '10'))->not->toThrow(RuntimeException::class);

    [$lot2, $line2] = srLot();
    Inventory::move($line2, 'reserve', ['available' => '-30', 'reserved' => '30'], 'res-test');
    expect(fn () => srPropose($line2, '71'))->toThrow(RuntimeException::class, 'Only 70.00');
    // Units pending in a supplier return cannot be handed over either.
    $pending = srPropose($line2, '60');
    expect($pending->status)->toBe('requested');
    $b = Sourcing::allocate($lot2->refresh(), ['project_id' => $this->project->id, 'line_id' => $line2->id, 'kind' => 'sale', 'quantity' => '20'], $this->finance);
    Inventory::move($line2, 'release', ['reserved' => '-30', 'available' => '30'], 'rel-test');
    $h2 = InventoryHandoverService::propose($b, '20', '2026-11-09', '2026-11-09', 'Signed receiving note RN-2', null, (string) Str::uuid(), $this->finance);
    expect(fn () => InventoryHandoverService::decide($h2, true, $this->board))->not->toThrow(RuntimeException::class);   // 100 - 60 pending - 20 = 20 left: this one still fits
    expect(fn () => srPropose($line2, '21'))->toThrow(RuntimeException::class, 'Only 20.00');
});

it('handles goods rejected at arrival as a claim only: they were never inventory, so nothing is capitalised, reversed or removed', function () {
    [$lot, $line] = srLot(prepay: true, received: '100', accepted: '90');
    $inventory = dr('assets.inventory');
    $journals = JournalEntry::count();
    $movements = InventoryValueMovement::count();

    $return = srPropose($line, '10', ['kind' => 'unaccepted', 'claimed_amount' => 500_000, 'expected_method' => 'refund']);
    expect(fn () => srPropose($line, '1', ['kind' => 'unaccepted']))->toThrow(RuntimeException::class, 'Only 0.00')->and($return->status)->toBe('requested');
    SupplierReturnService::recordAcceptance($return, $this->finance, 'accepted', 500_000, 'CN-REJ-1', 'Supplier agrees');
    expect(fn () => SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 500_000))->toThrow(RuntimeException::class, 'never entered inventory');
    SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked, refund pursued on the supplier payment');
    SupplierReturnService::decide($return->refresh(), true, $this->board);

    expect($return->refresh()->status)->toBe('approved')->and(dr('assets.inventory'))->toBe($inventory)->and(JournalEntry::count())->toBe($journals)->and(InventoryValueMovement::count())->toBe($movements)
        ->and(InventoryValuation::lineFigures($line->refresh())['quantity'])->toBe('90.00')->and(Inventory::balances($line)['available'])->toBe('90.00')
        ->and(SupplierReturnService::balances($return)['credit_total'])->toBe(0)->and(InventoryReconciliation::supplierReturns()['ok'])->toBeTrue();
});

it('returns every unit: the line keeps no value, and a residual on a line with no goods is detected', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, '100', p: 5_000_000, reason: 'Transport is not refundable', over: ['claimed_amount' => 5_000_000]);

    expect($return->carrying_value)->toBe(5_500_000)->and(InventoryValuation::lineValue($line->refresh()))->toBe(0)->and(InventoryValuation::lineFigures($line)['quantity'])->toBe('0.00')
        ->and(dr('assets.inventory'))->toBe(0)->and(InventoryReconciliation::company()['ok'])->toBeTrue();
    // A stray value put on the emptied line is a residual and is reported, never absorbed.
    InventoryValueMovement::create(['sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $line->id, 'supplier_id' => $this->supplier->id, 'project_id' => null, 'ownership' => 'company', 'component' => 'landed', 'type' => 'correction',
        'value_delta' => 1_000, 'quantity_delta' => 0, 'valuation_status' => 'valued', 'source_type' => 'test', 'source_id' => 1, 'journal_entry_id' => null, 'effective_on' => '2026-11-10', 'move_key' => 'resid-1']);
    $lotReport = InventoryReconciliation::lot($lot->refresh());
    expect(collect($lotReport['exceptions'])->contains(fn ($e) => str_contains($e, 'zero-quantity residual')))->toBeTrue();
});

it('records a return found after its month closed in the open period, flagged, with the original journals untouched', function () {
    [$lot, $line] = srLot();
    $original = JournalEntry::where('source_type', 'advance_settlement')->firstOrFail();
    $snapshot = $original->lines->map(fn ($l) => [$l->account, $l->debit, $l->credit])->all();
    AccountingPeriod::create(['month' => '2026-10', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    $return = srApprove($line, p: 1_100_000, over: ['physical_returned_on' => '2026-10-30']);

    $entry = JournalEntry::where('source_type', 'supplier_return')->where('source_id', $return->id)->firstOrFail();
    expect($return->prior_period)->toBeTrue()->and($return->physical_returned_on->toDateString())->toBe('2026-10-30')->and($return->posted_on->toDateString())->toBe('2026-11-10')
        ->and($entry->entry_date->toDateString())->toBe('2026-11-10')->and($entry->memo)->toContain('closed period')
        ->and($original->fresh()->lines->map(fn ($l) => [$l->account, $l->debit, $l->credit])->all())->toBe($snapshot);
    // A month closed today as well cannot take the posting at all.
    AccountingPeriod::create(['month' => '2026-11', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);
    $blocked = srPropose($line, '5', ['claimed_amount' => 275_000]);
    SupplierReturnService::recordAcceptance($blocked, $this->finance, 'accepted', 275_000, 'CN-CLOSED');
    SupplierReturnService::verify($blocked->refresh(), $this->co->users['multi'], 'Checked', 275_000);
    expect(fn () => SupplierReturnService::decide($blocked->refresh(), true, $this->board))->toThrow(RuntimeException::class, 'closed')->and($blocked->refresh()->status)->toBe('verified');
});

it('keeps the maker, the checker and the Board apart: nobody verifies or approves their own return, and nobody without the right acts', function () {
    [$lot, $line] = srLot();
    $return = srPropose($line);
    expect(fn () => SupplierReturnService::recordAcceptance($return, $this->ops, 'accepted', 1_100_000, 'CN-MC-1'))->toThrow(RuntimeException::class, 'Only Finance')
        ->and(fn () => SupplierReturnService::decide($return, true, $this->board))->toThrow(RuntimeException::class, 'Finance must verify');
    SupplierReturnService::recordAcceptance($return, $this->finance, 'accepted', 1_100_000, 'CN-MC-1');
    $self = srPropose($line, '5', ['claimed_amount' => 275_000]);
    SupplierReturnService::recordAcceptance($self, $this->finance, 'accepted', 275_000, 'CN-MC-2');
    $own = SupplierReturnService::propose($this->finance, $line, ['kind' => 'accepted', 'quantity' => '3', 'reason' => 'x', 'return_reference' => 'RTN-OWN', 'physical_returned_on' => '2026-11-10', 'evidence_reference' => 'CR-5555', 'claimed_amount' => 165_000], (string) Str::uuid());
    SupplierReturnService::recordAcceptance($own, $this->finance, 'accepted', 165_000, 'CN-MC-3');
    expect(fn () => SupplierReturnService::verify($own->refresh(), $this->finance, 'Checked', 0, 165_000))->toThrow(RuntimeException::class, 'you requested')
        ->and(fn () => SupplierReturnService::verify($return->refresh(), $this->ops, 'Checked', 0, 1_100_000))->toThrow(RuntimeException::class, 'Only Finance');
    SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 0, 1_100_000);
    expect(fn () => SupplierReturnService::decide($return->refresh(), true, $this->co->users['multi']))->toThrow(RuntimeException::class, 'requested or verified')
        ->and(fn () => SupplierReturnService::decide($return, true, $this->finance))->toThrow(RuntimeException::class, 'Only a Board member')
        ->and(fn () => SupplierReturnService::decide($return, false, $this->board))->toThrow(RuntimeException::class, 'reason');
    SupplierReturnService::decide($return, true, $this->board2);
    expect($return->refresh()->status)->toBe('approved')->and($return->approver->id)->toBe($this->board2->id);
});

it('keeps suppliers and lots apart: a return reduces only its own lot\'s payable and reconciliation never nets them', function () {
    [$lotA, $lineA] = srLot();
    [$lotB, $lineB] = srLot();
    expect(AdvanceSettlementService::payableOutstanding($lotB, $this->supplier->id))->toBe(5_000_000);
    $return = srApprove($lineA, p: 1_100_000);

    expect(AdvanceSettlementService::payableOutstanding($lotA, $this->supplier->id))->toBe(3_900_000)->and(AdvanceSettlementService::payableOutstanding($lotB->refresh(), $this->supplier->id))->toBe(5_000_000)
        ->and(InventoryValuation::lineValue($lineB->refresh()))->toBe(5_500_000);
    // Lot A's payable (3,900,000) cannot be reduced by more than it still owes, whatever lot B owes.
    $big = srPropose($lineA, '60', ['claimed_amount' => 3_300_000]);
    SupplierReturnService::recordAcceptance($big, $this->finance, 'accepted', 3_300_000, 'CN-ISO-1');
    SupplierReturnService::verify($big->refresh(), $this->co->users['multi'], 'Checked', 3_300_000);
    SupplierReturnService::decide($big, true, $this->board);
    $more = srPropose($lineA, '20');
    SupplierReturnService::recordAcceptance($more, $this->finance, 'accepted', 1_100_000, 'CN-ISO-2');
    expect(fn () => SupplierReturnService::verify($more->refresh(), $this->co->users['multi'], 'Checked', 700_000, 0, 0, 'Supplier agreed a lower recovery', 'recovery_shortfall'))->toThrow(RuntimeException::class, 'still owed to this supplier')
        ->and(AdvanceSettlementService::payableOutstanding($lotB, $this->supplier->id))->toBe(5_000_000);
    $rows = collect(InventoryReconciliation::supplierReturns()['rows']);
    expect($rows->pluck('lot_id')->unique()->all())->toBe([$lotA->id])->and($rows->every(fn ($r) => $r['remaining_supplier_obligation'] === 600_000))->toBeTrue();
});

it('reconciles inventory, payable, credit, receivable and unrecovered cost to the ledger after mixed returns', function () {
    [$lot, $line] = srLot();
    $a = srApprove($line, '20', p: 440_000, k: 385_000, r: 275_000);
    $b = srApprove($line, '10', k: 500_000, reason: 'Restocking charge of the supplier', over: ['claimed_amount' => 550_000]);
    $account = FinancialAccount::firstOrFail();
    $refund = SupplierReturnService::proposeSettlement($a, $this->finance, 'cash_refund', 100_000, 'Bank statement line 7', '2026-11-10', $account->id, (string) Str::uuid());
    SupplierReturnService::decideSettlement($refund, true, $this->board);
    $apply = SupplierReturnService::proposeSettlement($b, $this->finance, 'credit_applied', 200_000, 'Invoice INV-77', '2026-11-10', null, (string) Str::uuid());
    SupplierReturnService::decideSettlement($apply, true, $this->board);

    $r = InventoryReconciliation::supplierReturns();
    expect($r['ok'])->toBeTrue()->and($r['receivable_gl'])->toBe(175_000)->and($r['credit_gl'])->toBe(385_000 + 500_000 - 200_000)->and($r['difference_gl'])->toBe(50_000)
        ->and(dr('assets.inventory'))->toBe(5_500_000 - 1_100_000 - 550_000)->and(InventoryReconciliation::company()['ok'])->toBeTrue()
        ->and(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0)
        ->and(AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id))->toBe(5_000_000 - 440_000 - 200_000);
    foreach (InventoryReconciliation::quantities() as $row) {
        expect($row['differences'])->toBe([]);
    }
});

it('serves the supplier return screens with the same separation of duties, and keeps recovery categories apart', function () {
    [$lot, $line] = srLot();
    $page = $this->co->as('clients')->get(route('sourcing.supplier-returns'))->assertOk()->getContent();
    expect($page)->toContain('Request a supplier return')->and($page)->toContain('Zipper Hoodie')->and($page)->toContain('Recovery position of approved returns');
    expect($this->co->as('board')->get(route('sourcing.supplier-returns'))->getContent())->not->toContain('Request a supplier return');

    $input = ['sourcing_lot_line_id' => $line->id, 'kind' => 'accepted', 'quantity' => '20', 'reason' => 'Wrong size', 'return_reference' => 'RTN-UI-1', 'physical_returned_on' => '2026-11-10',
        'evidence_reference' => 'Carrier receipt CR-9', 'claimed_amount' => '11000', 'expected_method' => 'mixed', 'idempotency_key' => (string) Str::uuid()];
    $this->co->post('clients', 'sourcing.supplier-returns.store', $input)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $this->co->post('clients', 'sourcing.supplier-returns.store', $input);                       // the same form twice
    $return = SupplierReturn::firstOrFail();
    expect(SupplierReturn::count())->toBe(1);

    $this->co->post('clients', 'sourcing.supplier-returns.accept', ['acceptance' => 'accepted', 'supplier_document' => 'CN-UI-1'], $return)->assertForbidden();   // Operations cannot record the answer
    $this->co->post('finance', 'sourcing.supplier-returns.accept', ['acceptance' => 'accepted', 'supplier_document' => 'CN-UI-1'], $return)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $this->co->post('board', 'sourcing.supplier-returns.verify', ['verify_note' => 'ok'], $return)->assertForbidden();                                          // the Board does not verify
    $this->co->post('multi', 'sourcing.supplier-returns.verify', ['verify_note' => 'Checked', 'payable_reduction' => '4400', 'credit_note_amount' => '3850', 'receivable_amount' => '2750'], $return)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $this->co->post('finance', 'sourcing.supplier-returns.decide', ['decision' => 'approve'], $return)->assertForbidden();                                        // Finance holds no approval right
    $this->co->post('board', 'sourcing.supplier-returns.decide', ['decision' => 'approve'], $return)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($return->refresh()->status)->toBe('approved')->and(dr('assets.supplier_receivable'))->toBe(275_000);

    $account = FinancialAccount::firstOrFail();
    $this->co->post('finance', 'sourcing.supplier-returns.settle', ['method' => 'cash_refund', 'amount' => '1000', 'reference' => 'Bank statement line 5', 'settled_on' => '2026-11-10', 'financial_account_id' => $account->id, 'idempotency_key' => (string) Str::uuid()], $return)
        ->assertSessionHasNoErrors()->assertSessionMissing('error');
    $settlement = SupplierReturnSettlement::firstOrFail();
    expect($settlement->status)->toBe('pending')->and(dr('assets.supplier_receivable'))->toBe(275_000);                          // proposed cash is not collected cash
    $page = $this->co->as('board')->get(route('sourcing.supplier-returns'))->getContent();
    expect($page)->toContain('Cash refund (not yet received in the books)');
    $this->co->post('board', 'sourcing.supplier-returns.settlements.decide', ['decision' => 'approve'], $settlement)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(dr('assets.supplier_receivable'))->toBe(175_000);
    $detail = $this->co->as('board')->get(route('sourcing.supplier-returns.show', $return))->assertOk()->getContent();
    expect($detail)->toContain('Source journal entry')->and($detail)->toContain('Recovery, by category')->and($detail)->toContain('supplier_return_difference' === 'x' ? '' : 'assets.supplier_receivable');
});

/*
| Phase 2D-3A: one supplier recovery is recognised and collected once, whichever workflow it is recorded through.
*/

function srCorrection($lot, string $type, int $amount, string $reference): Expense
{
    $payment = $lot->payments()->firstOrFail();
    SourcingPayments::voidSupplierPayment($payment->refresh(), 'Supplier short-delivered', test()->finance, $type, $amount, $reference);

    return Expense::where('reverses_id', $payment->expense_id)->latest('id')->firstOrFail();
}

/** An approved claim for goods rejected at arrival (never inventory): [return]. */
function srRejectedClaim($line, string $quantity, int $amount, string $document): SupplierReturn
{
    $claim = srPropose($line, $quantity, ['kind' => 'unaccepted', 'claimed_amount' => $amount, 'expected_method' => 'refund']);
    SupplierReturnService::recordAcceptance($claim, test()->finance, 'accepted', $amount, $document, 'Supplier agrees');
    SupplierReturnService::verify($claim->refresh(), test()->co->users['multi'], 'Checked, recovered on the supplier payment');
    SupplierReturnService::decide($claim->refresh(), true, test()->board);

    return $claim->refresh();
}

it('keeps an advance refund and a supplier return apart: the refund recovers only the advance still held, the return only the goods that were settled', function () {
    [$lot, $line] = srLot(prepay: true, received: '100', accepted: '90');          // advance 5,000,000: 4,500,000 settled into the 90 accepted units, 500,000 still held for the 10 rejected
    $payment = $lot->payments()->firstOrFail();
    expect(AdvanceSettlementService::held($payment->load('corrections')))->toBe(500_000);

    // Money for the settled goods can only come back through the goods; the advance refund cannot reach it.
    expect(fn () => SourcingPayments::voidSupplierPayment($payment->refresh(), 'Refund everything', $this->finance, 'refund_received', 5_000_000, 'BANK-ADV-0'))->toThrow(RuntimeException::class, 'must be between');
    $refund = srCorrection($lot, 'refund_received', 500_000, 'BANK-ADV-1');
    srVerify($refund);
    $claim = srRejectedClaim($line, '10', 500_000, 'CN-ADV-1');
    SupplierReturnService::linkPaymentRecovery($claim, $refund, $this->finance);
    SupplierReturnService::linkPaymentRecovery($claim, $refund, $this->finance);                                // the same link twice is one link

    // The claim for the rejected goods was recovered by exactly that refund. The same receipt cannot also be collected as the return's refund.
    $goods = srApprove($line, '20', r: 1_000_000, reason: 'Supplier restocking charge', class: 'unrecoverable_cost');
    expect(fn () => SupplierReturnService::proposeSettlement($goods, $this->finance, 'cash_refund', 1_000_000, 'bank adv 1', '2026-11-10', FinancialAccount::firstOrFail()->id, (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'already registered');
    expect(dr('assets.supplier_receivable'))->toBe(1_000_000)->and($claim->refresh()->status)->toBe('approved')->and(JournalEntry::where('source_type', 'supplier_return')->count())->toBe(1);
    // A refund of the same correction cannot be linked to a second claim, nor above what the supplier accepted.
    expect(fn () => SupplierReturnService::linkPaymentRecovery($goods, $refund, $this->finance))->toThrow(RuntimeException::class, 'goods rejected at arrival');
    $report = InventoryReconciliation::supplierReturns();
    expect($report['ok'])->toBeTrue()->and($report['difference_by_class'])->toBe(['unrecoverable_cost' => $goods->difference_value])->and(InventoryReconciliation::company()['ok'])->toBeTrue();
});

it('registers every credit note and receipt once across workflows: a payment correction and a return cannot both recognise the same one', function () {
    [$lotA, $lineA] = srLot(prepay: true, received: '100', accepted: '90');
    srCorrection($lotA, 'credit_note', 400_000, 'CN-2026-14');                                                   // the credit note is booked through the advance
    $claim = srPropose($lineA, '10', ['kind' => 'unaccepted', 'claimed_amount' => 500_000]);
    expect(fn () => SupplierReturnService::recordAcceptance($claim, $this->finance, 'accepted', 500_000, 'CN 2026 14'))->toThrow(RuntimeException::class, 'already registered');

    [$lotB, $lineB] = srLot(prepay: true, received: '100', accepted: '90');
    $other = srPropose($lineB, '10', ['kind' => 'unaccepted', 'claimed_amount' => 500_000]);
    SupplierReturnService::recordAcceptance($other, $this->finance, 'accepted', 500_000, 'CN-B-1');             // the credit note is booked through the return
    expect(fn () => srCorrection($lotB, 'credit_note', 400_000, 'cn b 1'))->toThrow(RuntimeException::class, 'already registered');
    // Cancelling the return frees the document for the event that really happened.
    SupplierReturnService::cancel($other, $this->ops, 'Raised against the wrong lot');
    $correction = srCorrection($lotB, 'credit_note', 400_000, 'cn b 1');
    expect($correction->correction_type)->toBe('credit_note');
    // A rejected correction frees its receipt as well.
    [$lotC] = srLot(prepay: true, received: '100', accepted: '90');
    $rejected = srCorrection($lotC, 'refund_received', 100_000, 'BANK-REJ-1');
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'reject', 'reason' => 'Not received'], $rejected)->assertSessionHasNoErrors();
    expect(fn () => srCorrection($lotC, 'refund_received', 100_000, 'BANK-REJ-1'))->not->toThrow(RuntimeException::class);
});

it('treats a partly applied advance correctly: the refund is capped at what is held and the returned goods recover separately, never above what was paid', function () {
    [$lot, $line] = srLot(prepay: true, received: '100', accepted: '60');          // 3,000,000 settled, 2,000,000 still held
    $payment = $lot->payments()->firstOrFail();
    expect(fn () => srCorrection($lot, 'refund_received', 2_500_000, 'BANK-PA-1'))->toThrow(RuntimeException::class, 'must be between');
    $refund = srCorrection($lot, 'refund_received', 2_000_000, 'BANK-PA-2');
    srVerify($refund);
    $goods = srApprove($line, '10', r: 500_000, reason: 'Restocking charge', class: 'recovery_shortfall', over: ['claimed_amount' => 500_000]);

    expect(AdvanceSettlementService::held($payment->refresh()->load('corrections')))->toBe(0)
        ->and($goods->carrying_value)->toBe(583_333)->and($goods->difference_value)->toBe(83_333)
        ->and(2_000_000 + $goods->receivable_amount + $goods->difference_value + 3_000_000 - 583_333 + 500_000 - 500_000)->toBeLessThanOrEqual(5_500_000)
        ->and(InventoryReconciliation::supplierReturns()['ok'])->toBeTrue()->and(ledgerAudit()['unbalanced'])->toBe(0);
    // The advance cannot be re-opened to claim it again after the goods went back.
    $settlement = AdvanceSettlement::where('kind', 'advance')->where('status', 'verified')->firstOrFail();
    expect(fn () => AdvanceSettlementService::reverse($settlement, 'Undo', $this->board))->toThrow(RuntimeException::class);
});

it('refuses a second refund request for the same receipt, repeats nothing for the same submission, and frees the receipt when a request is rejected', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, r: 1_100_000);
    $account = FinancialAccount::firstOrFail();
    $key = (string) Str::uuid();
    $first = SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 300_000, 'Bank statement line 50', '2026-11-10', $account->id, $key);

    expect(SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 300_000, 'Bank statement line 50', '2026-11-10', $account->id, $key)->id)->toBe($first->id)
        ->and(fn () => SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 300_000, 'BANK STATEMENT LINE 50', '2026-11-10', $account->id, (string) Str::uuid()))->toThrow(RuntimeException::class, 'already registered')
        ->and(SupplierReturnSettlement::count())->toBe(1);
    SupplierReturnService::decideSettlement($first, false, $this->board, 'Not on the statement');
    $again = SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 300_000, 'Bank statement line 50', '2026-11-10', $account->id, (string) Str::uuid());
    SupplierReturnService::decideSettlement($again, true, $this->board);
    expect(dr('assets.supplier_receivable'))->toBe(800_000)
        ->and(fn () => SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 100_000, 'Bank statement line 50', '2026-11-10', $account->id, (string) Str::uuid()))->toThrow(RuntimeException::class, 'already registered');
});

it('catches a pending credit application that a later posting made impossible: the Board cannot approve it above what is still owed', function () {
    [$lot, $line] = srLot();
    $credit = srApprove($line, '60', k: 3_300_000, over: ['claimed_amount' => 3_300_000]);
    $pending = SupplierReturnService::proposeSettlement($credit, $this->finance, 'credit_applied', 3_300_000, 'Invoice INV-3001', '2026-11-10', null, (string) Str::uuid());
    $payable = srApprove($line->refresh(), '40', p: 2_200_000, over: ['claimed_amount' => 2_200_000]);      // reduces what is owed to 2,800,000 first

    expect(AdvanceSettlementService::payableOutstanding($lot->refresh(), $this->supplier->id))->toBe(2_800_000)
        ->and(fn () => SupplierReturnService::decideSettlement($pending, true, $this->board))->toThrow(RuntimeException::class, 'owed to this supplier')
        ->and($pending->refresh()->status)->toBe('pending');
    SupplierReturnService::decideSettlement($pending, false, $this->board, 'Owed amount changed');
    $fits = SupplierReturnService::proposeSettlement($credit, $this->finance, 'credit_applied', 2_800_000, 'Invoice INV-3001', '2026-11-10', null, (string) Str::uuid());
    SupplierReturnService::decideSettlement($fits, true, $this->board);
    $raw = AdvanceSettlementService::payableOutstanding($lot, $this->supplier->id, clamp: false);
    expect($raw)->toBe(0)->and(InventoryReconciliation::supplierReturns()['ok'])->toBeTrue()->and($payable->payable_reduction)->toBe(2_200_000);
});

it('classifies the cost that is not recovered and keeps freight within the landed cost; the class lands in the reconciliation, not in profit', function () {
    [$lot, $line] = srLot();
    $return = srPropose($line);
    SupplierReturnService::recordAcceptance($return, $this->finance, 'accepted', 400_000, 'CN-CLS-1', null);
    expect(fn () => SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 0, 400_000, 0, 'Supplier recovers less'))->toThrow(RuntimeException::class, 'Classify the part')
        ->and(fn () => SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 0, 400_000, 0, 'Freight', 'nonrefundable_freight'))->toThrow(RuntimeException::class, 'cannot exceed the landed cost')
        ->and(fn () => SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 0, 400_000, 0, 'Supplier recovers less', 'made_up'))->toThrow(RuntimeException::class, 'Classify the part');
    SupplierReturnService::verify($return->refresh(), $this->co->users['multi'], 'Checked', 0, 400_000, 0, 'Restocking fee and freight', 'unrecoverable_cost');
    SupplierReturnService::decide($return, true, $this->board);

    $freight = srApprove($line, '10', k: 500_000, reason: 'Freight on these goods is not refundable', class: 'nonrefundable_freight', over: ['claimed_amount' => 500_000]);
    $report = InventoryReconciliation::supplierReturns();
    expect($freight->difference_value)->toBe(50_000)->and($freight->difference_class)->toBe('nonrefundable_freight')
        ->and($report['difference_by_class'])->toBe(['unrecoverable_cost' => 700_000, 'nonrefundable_freight' => 50_000])->and($report['difference_gl'])->toBe(750_000)->and($report['ok'])->toBeTrue()
        ->and(collect(SupplierReturnService::DIFFERENCE_CLASSES)->every(fn ($c) => $c['proposed_2e'] !== ''))->toBeTrue();
    // Nothing reached a profit line: the unrecovered cost sits in its own account, which no profit report reads.
    expect(dr('expense'))->toBe(0);
});

it('posts a supplier refund received after the month closed in the open period, with the original return journal untouched', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, r: 1_100_000);
    $original = JournalEntry::where('source_type', 'supplier_return')->where('source_id', $return->id)->firstOrFail();
    $snapshot = $original->lines->map(fn ($l) => [$l->account, $l->debit, $l->credit])->all();
    AccountingPeriod::create(['month' => '2026-10', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->finance->id]);

    $refund = SupplierReturnService::proposeSettlement($return, $this->finance, 'cash_refund', 1_100_000, 'Bank statement line 61', '2026-10-30', FinancialAccount::firstOrFail()->id, (string) Str::uuid());
    SupplierReturnService::decideSettlement($refund, true, $this->board);
    $entry = JournalEntry::where('source_type', 'supplier_return_settlement')->where('source_id', $refund->id)->firstOrFail();

    expect($entry->entry_date->toDateString())->toBe('2026-11-10')->and($entry->memo)->toContain('closed period')->and($refund->refresh()->settled_on->toDateString())->toBe('2026-10-30')
        ->and($original->fresh()->lines->map(fn ($l) => [$l->account, $l->debit, $l->credit])->all())->toBe($snapshot)->and(dr('assets.supplier_receivable'))->toBe(0)
        ->and(InventoryReconciliation::supplierReturns()['ok'])->toBeTrue();
});

it('reconciles every approved return across workflows to the ledger: recovery, classification, advance refunds and payable', function () {
    [$lot, $line] = srLot(prepay: true, received: '100', accepted: '90');
    $refund = srCorrection($lot, 'refund_received', 500_000, 'BANK-FIN-1');
    srVerify($refund);
    $claim = srRejectedClaim($line, '10', 500_000, 'CN-FIN-1');
    SupplierReturnService::linkPaymentRecovery($claim, $refund, $this->finance);
    $goods = srApprove($line, '9', k: 300_000, r: 200_000, reason: 'Restocking charge', class: 'recovery_shortfall', over: ['claimed_amount' => 500_000]);
    $cash = SupplierReturnService::proposeSettlement($goods, $this->finance, 'cash_refund', 200_000, 'Bank statement line 99', '2026-11-10', FinancialAccount::firstOrFail()->id, (string) Str::uuid());
    SupplierReturnService::decideSettlement($cash, true, $this->board);

    $r = InventoryReconciliation::supplierReturns();
    expect($r['ok'])->toBeTrue()->and($r['problems'])->toBe([])->and($r['receivable_gl'])->toBe(0)->and($r['credit_gl'])->toBe(300_000)->and($r['difference_gl'])->toBe($goods->difference_value)
        ->and(collect($r['rows'])->firstWhere('kind', 'unaccepted')['issues'])->toBe([])
        ->and(InventoryReconciliation::company()['ok'])->toBeTrue()->and(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('formats negative decimals correctly for inputs and exports', function () {
    expect(Money::toDecimal(150))->toBe('1.50')->and(Money::toDecimal(-150))->toBe('-1.50')->and(Money::toDecimal(-5))->toBe('-0.05')->and(Money::toDecimal(0))->toBe('0.00')
        ->and(Money::toMinor(Money::toDecimal(-123_456)))->toBe(-123_456);
});

it('reads the trial balance, operational profit, cash flow, aging and exceptions from the ledger, with the bridge to the profit allocation reads', function () {
    [$lot, $line] = srLot();
    $return = srApprove($line, p: 1_000_000, reason: 'Freight is not refundable', class: 'nonrefundable_freight');
    $S = LedgerStatements::class;

    $tb = $S::trialBalance();
    expect($tb['balanced'])->toBeTrue()->and($tb['total_debit'])->toBe($tb['total_credit'])->and(collect($tb['rows'])->pluck('account'))->toContain('supplier_return_difference');

    $profit = $S::operationalProfit('2026-11-01', '2026-11-30');
    expect($profit['inventory']['supplier_return_difference'])->toBe(100_000)->and($profit['inventory_total'])->toBe(100_000)->and($profit['operational_profit'])->toBe($profit['ledger_profit'] - 100_000)
        ->and($profit['inventory']['cogs'])->toBe(0);

    $cash = $S::cashFlow('2026-11-01', '2026-11-30');
    expect($cash['agrees'])->toBeTrue()->and($cash['sections']['financing']['in'])->toBe(1_000_000_00)->and($cash['closing'])->toBe($cash['ledger_closing'])
        ->and($cash['sections']['operating']['out'])->toBeGreaterThan(0)->and($cash['opening'])->toBe(0);
    expect($S::cashFlow('2026-11-05', '2026-11-30')['opening'])->toBe(1_000_000_00);          // opening is the ledger's cash before the period

    // Supplier payable by age: 4,000,000 still owed (5,000,000 less the 1,000,000 the return took), recognised on 2026-11-08.
    $aging = $S::supplierAging('2026-11-10');
    expect($aging['total'])->toBe(4_000_000)->and($aging['buckets']['current'])->toBe(4_000_000)->and($aging['rows'][0]['lot_id'])->toBe($lot->id);
    $later = $S::supplierAging('2027-03-01');
    expect($later['buckets']['over_90'])->toBe(4_000_000)->and($later['buckets']['current'])->toBe(0);

    expect($S::exceptions())->toBe([]);
    srPropose($line, '5', ['claimed_amount' => 275_000]);
    expect(collect($S::exceptions())->pluck('message')->implode(' '))->toContain('Supplier returns awaiting a decision: 1');

    $page = $this->co->as('finance')->get(route('finance.ledger-reports'))->assertOk()->getContent();
    expect($page)->toContain('Operational profit (ledger)')->and($page)->toContain('Balanced')->and($page)->toContain('Cost of returned goods not recovered from suppliers');
});

it('shows the frozen distributable profit beside the ledger operational profit with the approved fund split, and flags a landed cost that arrived after handover', function () {
    [$lot, $line] = srLot();
    AccountingPeriod::create(['month' => '2026-11', 'income' => 5_000_000, 'expenses' => 1_000_000, 'net_profit' => 4_000_000, 'closed_by' => $this->finance->id]);
    $bridge = LedgerStatements::distributionBridge()[0];

    expect($bridge['frozen_distributable_profit'])->toBe(4_000_000)->and($bridge['salary_fund'])->toBe(800_000)->and($bridge['office_fund'])->toBe(200_000)->and($bridge['emergency_reserve'])->toBe(1_000_000)->and($bridge['partners'])->toBe(2_000_000)
        ->and($bridge['salary_fund'] + $bridge['office_fund'] + $bridge['emergency_reserve'] + $bridge['partners'])->toBe(4_000_000)
        ->and($bridge['difference'])->toBe($bridge['ledger_operational_profit'] - 4_000_000);
    expect(AccountingPeriod::first()->net_profit)->toBe(4_000_000);                      // the frozen figure is only read
    $page = $this->co->as('finance')->get(route('finance.ledger-reports'))->assertOk()->getContent();
    expect($page)->toContain('Distributable (frozen)')->and($page)->toContain('Partners 50%');
});
