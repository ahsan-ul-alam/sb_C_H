<?php

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Services\ClientPortalData;
use App\Services\IntegrityGate;
use App\Services\Inventory;
use App\Services\LotFinancials;
use App\Services\ProjectEconomics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| A super administrator corrects a client lot at any stage. Reference lot: 100 × BDT 150, delivery BDT 800, budget BDT 20,000 → cost 15,800, profit 4,200.
| Completed with the supplier and the delivery paid, so it is settled. "Today" is 2026-11-10; amounts in paisa.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'cash', 'finance.sourcing_recognition_trigger' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->co->invest('A', '100000', '2026-11-02');
    $this->co->standingPolicy('supplier_payments', '100000'); // supplier and delivery payments are verified and posted to the ledger at once
    $this->supplier = Supplier::create(['name' => 'Dhaka Ceramics']);
    $this->product = SupplierProduct::create(['supplier_id' => $this->supplier->id, 'name' => 'Coffee Mug', 'unit' => 'pcs', 'reference_price' => 15_000]);
    $this->client = $this->co->client('Acme Retail');
    $this->project = $this->co->project($this->client, 'Corporate Gifts');
});

afterEach(fn () => Carbon::setTestNow());

/** The reference lot, completed with supplier and delivery paid (settled). */
function ccSettledLot(): SourcingLot
{
    $co = test()->co;
    $co->post('finance', 'sourcing.client-lots.store', ['supplier_id' => test()->supplier->id, 'supplier_product_id' => test()->product->id, 'project_id' => test()->project->id,
        'quantity' => '100', 'unit_cost' => '150', 'client_budget' => '20000', 'submission_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertSessionMissing('error');
    $lot = SourcingLot::latest('id')->firstOrFail();
    $co->post('finance', 'sourcing.lots.order', ['delivery_charge' => '800'], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');
    $co->post('finance', 'sourcing.lots.complete', ['arrived_on' => '2026-11-09', 'reference' => 'GRN-1', 'supplier_paid' => '1', 'delivery_paid' => '1'], $lot)->assertSessionHasNoErrors()->assertSessionMissing('error');

    return $lot->refresh();
}

/** Post a correction; unchanged fields keep the reference values. */
function ccCorrect(SourcingLot $lot, array $changes, string $who = 'super')
{
    return test()->co->post($who, 'sourcing.lots.correct', $changes + ['client_budget' => '20000', 'quantity' => '100', 'unit_cost' => '150', 'delivery_charge' => '800',
        'stage' => $lot->fresh()->status === 'settled' ? 'settled' : $lot->fresh()->stage(), 'project_id' => $lot->project_id, 'reason' => 'Corrected after supplier statement'], $lot);
}

function ccCash(): int
{
    return (int) JournalLine::where('account', 'cash')->sum('debit') - (int) JournalLine::where('account', 'cash')->sum('credit');
}

/** Every journal entry still balances. */
function ccBalanced(): bool
{
    return JournalEntry::with('lines')->get()->every(fn ($e) => (int) $e->lines->sum('debit') === (int) $e->lines->sum('credit') && $e->lines->sum('debit') > 0);
}

it('changes the budget of a settled lot and every figure follows: lot profit, project margin and the client portal', function () {
    $lot = ccSettledLot();
    expect($lot->status)->toBe('settled')->and(LotFinancials::for($lot)['profit'])->toBe(420_000);
    $cash = ccCash();

    ccCorrect($lot, ['client_budget' => '25000'])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertSessionHas('status');

    $lot->refresh();
    expect($lot->client_budget)->toBe(2_500_000)->and($lot->status)->toBe('settled')->and($lot->approved_revenue)->toBe(2_500_000)
        ->and(LotFinancials::for($lot)['profit'])->toBe(920_000)
        ->and(ProjectEconomics::for($this->project)['sourcing_margin'])->toBe(920_000)
        ->and(collect(ClientPortalData::lots($this->client))->firstWhere('number', $lot->number)['lot_price'])->toBe(2_500_000)
        ->and(ccCash())->toBe($cash) // the budget is not money: nothing posted
        ->and(AuditLog::where('action', 'sourcing.client_lot.corrected')->where('details', 'like', '%client budget%reason: Corrected after supplier statement%')->exists())->toBeTrue();
});

it('rewrites the posted supplier payment when the unit cost drops on a paid lot, and the cash and ledger stay balanced', function () {
    $lot = ccSettledLot();
    $cash = ccCash();

    ccCorrect($lot, ['unit_cost' => '140'])->assertSessionHasNoErrors()->assertSessionMissing('error');

    $lot->refresh();
    $payment = SupplierPayment::where('sourcing_lot_id', $lot->id)->sole();
    $f = LotFinancials::for($lot);
    expect($payment->amount)->toBe(1_400_000)->and(Expense::find($payment->expense_id)->amount)->toBe(1_400_000)->and(Expense::find($payment->expense_id)->paid_amount)->toBe(1_400_000)
        ->and(IntegrityGate::sourceBlockers())->toBe([])
        ->and($f['purchase'])->toBe(1_400_000)->and($f['supplier_outstanding'])->toBe(0)->and($f['profit'])->toBe(520_000)
        ->and(ccCash())->toBe($cash + 100_000) // BDT 1,000 less went out
        ->and(JournalEntry::where('source_type', 'expense')->where('source_id', $payment->expense_id)->first()->lines->sum('debit'))->toBe(1_500_000) // the posting itself is never edited
        ->and(JournalEntry::where('source_type', 'expense_rewrite')->count())->toBe(1)
        ->and(ccBalanced())->toBeTrue();
});

it('raises the cost of a paid lot and keeps it paid in full at the new cost', function () {
    $lot = ccSettledLot();
    $cash = ccCash();

    ccCorrect($lot, ['unit_cost' => '160'])->assertSessionHasNoErrors()->assertSessionMissing('error');

    expect(SupplierPayment::where('sourcing_lot_id', $lot->id)->sole()->amount)->toBe(1_600_000)->and(LotFinancials::for($lot->refresh())['supplier_outstanding'])->toBe(0)
        ->and(ccCash())->toBe($cash - 100_000)->and(ccBalanced())->toBeTrue();
});

it('changes the quantity of a received lot: stock, received units and the paid supplier amount follow', function () {
    $lot = ccSettledLot();

    ccCorrect($lot, ['quantity' => '90'])->assertSessionHasNoErrors()->assertSessionMissing('error');

    $line = $lot->refresh()->lines()->first();
    expect((string) $line->quantity)->toBe('90.00')->and((string) $line->received_quantity)->toBe('90.00')->and((string) $line->usable_quantity)->toBe('90.00')
        ->and(Inventory::balances($line)['available'])->toBe('90.00')->and(Inventory::reconciles($line))->toBeTrue()
        ->and(SupplierPayment::where('sourcing_lot_id', $lot->id)->sole()->amount)->toBe(1_350_000)
        ->and(LotFinancials::for($lot)['profit'])->toBe(2_000_000 - 1_350_000 - 80_000);

    ccCorrect($lot, ['quantity' => '120'])->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(Inventory::balances($lot->lines()->first())['available'])->toBe('120.00')->and(SupplierPayment::where('sourcing_lot_id', $lot->id)->sole()->amount)->toBe(1_800_000);
});

it('rewrites a paid delivery charge, and removing it withdraws the expense and its ledger entry', function () {
    $lot = ccSettledLot();
    $cost = $lot->costs()->where('category', 'Delivery')->sole();
    $expenseId = $cost->expense_id;
    $cash = ccCash();

    ccCorrect($lot, ['delivery_charge' => '500'])->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($cost->fresh()->amount)->toBe(50_000)->and(Expense::find($expenseId)->amount)->toBe(50_000)->and(ccCash())->toBe($cash + 30_000)->and(LotFinancials::for($lot->refresh())['profit'])->toBe(450_000);

    ccCorrect($lot, ['delivery_charge' => '0'])->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($lot->costs()->where('category', 'Delivery')->exists())->toBeFalse()->and(Expense::find($expenseId)->status)->toBe('rejected')
        ->and(ccCash())->toBe($cash + 80_000)->and(ccBalanced())->toBeTrue();

    ccCorrect($lot, ['delivery_charge' => '300'])->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(LotFinancials::for($lot->refresh())['additional'])->toBe(30_000);
});

it('sets any stage freely without moving stock or money, and keeps the history timestamps', function () {
    $lot = ccSettledLot();
    $completedAt = $lot->completed_at;
    $cash = ccCash();

    ccCorrect($lot, ['stage' => 'processing'])->assertSessionHasNoErrors()->assertSessionMissing('error');
    $lot->refresh();
    expect($lot->status)->toBe('approved')->and($lot->stage())->toBe('processing')->and($lot->settled_at)->toBeNull()->and($lot->completed_at?->toDateTimeString())->toBe($completedAt->toDateTimeString())
        ->and(Inventory::balances($lot->lines()->first())['available'])->toBe('100.00')->and(ccCash())->toBe($cash);

    ccCorrect($lot, ['stage' => 'settled'])->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($lot->refresh()->status)->toBe('settled')->and($lot->settled_at)->not->toBeNull();
});

it('lets only a super administrator correct, always with a reason, and changes nothing otherwise', function () {
    $lot = ccSettledLot();

    ccCorrect($lot, ['client_budget' => '1'], 'finance')->assertForbidden();
    ccCorrect($lot, ['client_budget' => '1', 'reason' => ''])->assertSessionHasErrors('reason');
    ccCorrect($lot, [])->assertSessionHas('error', 'Nothing was changed.');

    expect($lot->refresh()->client_budget)->toBe(2_000_000);
});

it('refuses to rewrite a payment dated in a closed month and rolls the whole correction back', function () {
    $lot = ccSettledLot();
    AccountingPeriod::forceCreate(['month' => '2026-11', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => $this->co->users['super']->id]);

    ccCorrect($lot, ['unit_cost' => '140', 'client_budget' => '30000'])->assertSessionHas('error');

    expect($lot->refresh()->client_budget)->toBe(2_000_000)->and(SupplierPayment::where('sourcing_lot_id', $lot->id)->sole()->amount)->toBe(1_500_000);
});
