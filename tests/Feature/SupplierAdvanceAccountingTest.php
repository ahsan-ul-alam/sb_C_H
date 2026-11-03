<?php

use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\AccountingService;
use App\Services\AdvanceSettlementService;
use App\Services\CostRecognitionService;
use App\Services\FinancialCompleteness;
use App\Services\FinancialReportingService;
use App\Services\FinancialStatements;
use App\Services\Ledger;
use App\Services\LotFinancials;
use App\Services\PeriodProcessor;
use App\Services\ProjectSourcingReport;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingBasis;
use App\Services\SourcingPayments;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Phase 6: the confirmed workflow is 100% supplier payment in advance, goods received later. Under finance.sourcing_basis=accrual
| (forward-only, off by default) the advance is an asset, accepted goods settle it into product acquisition cost with no cash movement,
| acquisition costs are capitalised, and cost reaches profit only on the Board-approved release. All amounts are integer paisa
| (BDT 40,000 = 4,000,000); every expectation is computed independently of the code under test. "Today" is 2026-11-10.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'accrual',
        'finance.sourcing_accrual_from' => '2026-11-01', 'finance.sourcing_recognition_trigger' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->board2 = $this->co->users['board2'];
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $this->bkash = FinancialAccount::factory()->create(['name' => 'bKash', 'type' => 'mobile']);
    $this->co->invest('A', '1000000', '2026-11-02');
    foreach ([[$this->bank, '300000'], [$this->bkash, '100000']] as [$to, $amount]) {
        $this->co->post('finance', 'finance.transfers.store', ['from' => (string) $this->cash->id, 'to' => (string) $to->id, 'amount' => $amount, 'date' => '2026-11-02', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }
    $this->supplier = Supplier::create(['name' => 'Uttara Buying House']);
});

afterEach(fn () => Carbon::setTestNow());

/** A lot with one product line (10 pieces at BDT 4,000 = BDT 40,000), costed, priced at cost + BDT 750 per piece and approved. */
function saLot(int $quantity = 10, int $unitPrice = 400_000, array $costs = [], int $profit = 75_000, ?Supplier $supplier = null): array
{
    $finance = test()->finance;
    $lot = Sourcing::createLot(['title' => 'Lot '.Str::random(4)], $finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => ($supplier ?? test()->supplier)->id, 'description' => 'Zipper Hoodie', 'quantity' => (string) $quantity, 'unit_price' => $unitPrice], $finance);
    $made = [];
    foreach ($costs as [$category, $amount]) {
        $made[] = Sourcing::addCost($lot, ['category' => $category, 'amount' => $amount, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance);
    }
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', $profit, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);

    return [$lot->refresh(), $line->refresh(), $made];
}

function saVerify(Expense $expense): void
{
    test()->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $expense)->assertSessionHasNoErrors()->assertSessionMissing('error');
}

/** Full supplier payment before receipt, verified by the Board. */
function saPay(SourcingLot $lot, int $amount, ?FinancialAccount $account = null, string $date = '2026-11-03', ?Supplier $supplier = null): SupplierPayment
{
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => ($supplier ?? test()->supplier)->id, 'amount' => $amount, 'paid_on' => $date, 'method' => 'bank', 'purchase_order' => 'PO-'.$lot->id,
        'reference' => 'PAY-'.Str::random(5), 'evidence_reference' => 'INV-'.$lot->id, 'financial_account_id' => $account?->id, 'idempotency_key' => (string) Str::uuid()], test()->finance);
    saVerify($payment->expense);

    return $payment->refresh()->load('expense');
}

function saArrive($line, string $received, ?string $accepted = null, string $reference = 'DN-1', string $date = '2026-11-08'): void
{
    SourcingArrival::record($line->refresh(), $received, $accepted, $date, test()->finance, $reference);
}

/** Finance proposes, the Board verifies. */
function saSettle(SourcingLot $lot, ?Supplier $supplier = null): Collection
{
    $settlements = AdvanceSettlementService::propose($lot, ($supplier ?? test()->supplier)->id, test()->finance);
    foreach ($settlements as $s) {
        AdvanceSettlementService::decide($s, true, test()->board);
    }

    return $settlements;
}

function saProfit(string $month = '2026-11'): array
{
    $from = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

    return FinancialReportingService::profitAndLoss($from, $from->endOfMonth()->startOfDay());
}

function saGate(string $key): array
{
    return FinancialCompleteness::report()['layers'][$key];
}

it('pays a supplier in full in advance from cash, bank or mobile banking: cash leaves exactly once and the advance is an asset', function () {
    $profit = saProfit()['net_profit'];
    $total = AccountingService::totalCash();
    $paid = 0;
    foreach ([$this->cash, $this->bank, $this->bkash] as $account) {
        [$lot] = saLot();
        $before = AccountingService::balance($account->id);

        $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $this->supplier->id, 'amount' => 4_000_000, 'paid_on' => '2026-11-03', 'purchase_order' => 'PO-'.$lot->id, 'reference' => 'PAY-'.$account->id,
            'evidence_reference' => 'INV-'.$lot->id, 'method' => 'transfer', 'financial_account_id' => $account->id, 'idempotency_key' => (string) Str::uuid()], $this->finance);
        expect(AccountingService::balance($account->id))->toBe($before)->and($payment->expense->classification)->toBe('supplier_advance'); // recorded, awaiting the checker: nothing posted
        saVerify($payment->expense);
        $paid += 4_000_000;

        expect(AccountingService::balance($account->id))->toBe($before - 4_000_000) // the right account, exactly once
            ->and(AccountingService::totalCash())->toBe($total - $paid)
            ->and(dr('assets.supplier_advances'))->toBe($paid)->and(dr('expense'))->toBe(0)->and(saProfit()['net_profit'])->toBe($profit)
            ->and(JournalEntry::where('source_type', 'expense')->where('source_id', $payment->expense_id)->count())->toBe(1);
    }
    // The advance is an asset on the balance sheet and does not touch operating expenses.
    expect(FinancialStatements::position()['balanced'])->toBeTrue()
        ->and(collect(FinancialStatements::position()['groups']['assets'])->firstWhere('label', 'Supplier advances (paid, goods not yet accepted)')['amount'])->toBe($paid);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('demands the purchase order, payment reference and evidence, and refuses duplicates and overpayment', function () {
    [$lot] = saLot();
    $base = ['supplier_id' => $this->supplier->id, 'amount' => 4_000_000, 'paid_on' => '2026-11-03', 'idempotency_key' => (string) Str::uuid()];

    expect(fn () => SourcingPayments::recordSupplierPayment($lot, [...$base, 'reference' => 'R', 'evidence_reference' => 'E'], $this->finance))->toThrow(RuntimeException::class, 'purchase order')
        ->and(fn () => SourcingPayments::recordSupplierPayment($lot, [...$base, 'purchase_order' => 'PO', 'evidence_reference' => 'E'], $this->finance))->toThrow(RuntimeException::class, 'payment reference')
        ->and(fn () => SourcingPayments::recordSupplierPayment($lot, [...$base, 'purchase_order' => 'PO', 'reference' => 'R'], $this->finance))->toThrow(RuntimeException::class, 'supporting evidence')
        ->and(SupplierPayment::count())->toBe(0);

    $full = [...$base, 'purchase_order' => 'PO-1', 'reference' => 'R-1', 'evidence_reference' => 'INV-1'];
    expect(SourcingPayments::recordSupplierPayment($lot, $full, $this->finance))->not->toBeNull()
        ->and(SourcingPayments::recordSupplierPayment($lot, $full, $this->finance))->toBeNull() // the same submission twice
        ->and(fn () => SourcingPayments::recordSupplierPayment($lot, [...$full, 'amount' => 1, 'idempotency_key' => (string) Str::uuid()], $this->finance))->toThrow(RuntimeException::class, 'exceeds what this lot owes')
        ->and(SupplierPayment::count())->toBe(1)->and(Expense::where('classification', 'supplier_advance')->count())->toBe(1);
});

it('settles the advance into acquisition cost on accepted arrival with no second cash outflow, exactly once', function () {
    [$lot, $line] = saLot();
    saPay($lot, 4_000_000, $this->bank);
    $cash = AccountingService::totalCash();
    $bank = AccountingService::balance($this->bank->id);

    saArrive($line, '10', '10');
    // Evidence is mandatory: arrival without the supplier's documentation cannot settle.
    DB::table('sourcing_lot_lines')->where('id', $line->id)->update(['acceptance_reference' => null]);
    expect(fn () => AdvanceSettlementService::propose($lot, $this->supplier->id, $this->finance))->toThrow(RuntimeException::class, 'delivery or inspection reference');
    saArrive($line, '10', '10', 'DN-77');

    $proposed = AdvanceSettlementService::propose($lot, $this->supplier->id, $this->finance);
    expect($proposed)->toHaveCount(1)->and($proposed[0]->amount)->toBe(4_000_000)->and(dr('assets.inventory'))->toBe(0); // proposal posts nothing
    expect(fn () => AdvanceSettlementService::decide($proposed[0], true, $this->finance))->toThrow(RuntimeException::class, 'you proposed');
    AdvanceSettlementService::decide($proposed[0], true, $this->board);

    $entry = JournalEntry::where('source_type', 'advance_settlement')->where('source_id', $proposed[0]->id)->firstOrFail();
    expect($entry->lines->pluck('account')->sort()->values()->all())->toBe(['assets.inventory', 'assets.supplier_advances'])->and($entry->lines->sum('debit'))->toBe(4_000_000)
        ->and(AccountingService::totalCash())->toBe($cash)->and(AccountingService::balance($this->bank->id))->toBe($bank) // arrival moved no money
        ->and(dr('assets.supplier_advances'))->toBe(0)->and(dr('assets.inventory'))->toBe(4_000_000)->and(dr('expense'))->toBe(0);
    // Repeating the settlement changes nothing.
    expect(AdvanceSettlementService::propose($lot, $this->supplier->id, $this->finance))->toBeEmpty()
        ->and(fn () => AdvanceSettlementService::decide($proposed[0], true, $this->board))->toThrow(RuntimeException::class, 'Already decided')
        ->and(AdvanceSettlement::count())->toBe(1)->and(JournalEntry::where('source_type', 'advance_settlement')->count())->toBe(1);
    expect(ledgerAudit()['unbalanced'])->toBe(0);
});

it('settles only accepted goods: a shortage or damage leaves an identifiable advance, not a loss', function () {
    [$lot, $line] = saLot();
    saPay($lot, 4_000_000);
    saArrive($line, '9', '8'); // 10 ordered, 9 arrived, 8 accepted: 1 missing, 1 rejected

    $q = ProjectSourcingReport::quantities(LotFinancials::for($lot->refresh()))[0];
    expect($q['purchased'])->toBe('10.00')->and($q['received'])->toBe('9.00')->and($q['accepted'])->toBe('8.00')->and($q['rejected'])->toBe('1.00')->and($q['missing'])->toBe('1.00');

    $settled = saSettle($lot);
    expect($settled->sum('amount'))->toBe(3_200_000)  // 8 × 4,000.00
        ->and(dr('assets.inventory'))->toBe(3_200_000)->and(dr('assets.supplier_advances'))->toBe(800_000)->and(dr('expense'))->toBe(0)
        ->and(AdvanceSettlementService::unsettled($lot))->toBe(800_000);
    expect(collect(saGate('sourcing')['findings'])->pluck('severity')->all())->not->toContain('blocker'); // a live lot's advance is an asset, not an error
});

it('returns an unsettled advance as a refund once, restoring the same account, and never the settled part', function () {
    [$lot, $line] = saLot();
    $payment = saPay($lot, 4_000_000, $this->bank);
    saArrive($line, '9', '8');
    saSettle($lot);
    $bank = AccountingService::balance($this->bank->id);

    // Only the 8,000.00 still held can come back; the settled 32,000.00 is cost now.
    expect(fn () => SourcingPayments::voidSupplierPayment($payment, 'Supplier short-delivered', $this->finance, 'refund_received', 4_000_000, 'BANK-RCPT-1'))->toThrow(RuntimeException::class, 'must be between');
    SourcingPayments::voidSupplierPayment($payment, 'Supplier short-delivered', $this->finance, 'refund_received', 800_000, 'BANK-RCPT-1');
    expect(fn () => SourcingPayments::voidSupplierPayment($payment->refresh(), 'Again', $this->finance, 'refund_received', 800_000, 'BANK-RCPT-1'))->toThrow(RuntimeException::class, 'already awaiting');
    expect(AccountingService::balance($this->bank->id))->toBe($bank); // a requested correction moves nothing

    saVerify(Expense::findOrFail($payment->refresh()->reversal_expense_id));

    expect(AccountingService::balance($this->bank->id))->toBe($bank + 800_000)->and(dr('assets.supplier_advances'))->toBe(0)->and(dr('assets.inventory'))->toBe(3_200_000)
        ->and(AdvanceSettlementService::unsettled($lot->refresh()))->toBe(0)->and(dr('expense'))->toBe(0);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('records a supplier credit note as a recoverable credit with no cash movement', function () {
    [$lot, $line] = saLot();
    $payment = saPay($lot, 4_000_000);
    saArrive($line, '9', '8');
    saSettle($lot);
    $cash = AccountingService::totalCash();

    SourcingPayments::voidSupplierPayment($payment, 'Supplier will credit the shortage', $this->finance, 'credit_note', 800_000, 'CN-2026-14');
    saVerify(Expense::findOrFail($payment->refresh()->reversal_expense_id));

    expect(AccountingService::totalCash())->toBe($cash)->and(dr('supplier.credit'))->toBe(800_000)->and(dr('assets.supplier_advances'))->toBe(0)
        ->and(AdvanceSettlementService::unsettled($lot->refresh()))->toBe(0)->and(dr('expense'))->toBe(0);
});

it('does not let a purchase order be cancelled while an advance is held, and allows it after a full refund', function () {
    [$lot] = saLot();
    $payment = saPay($lot, 4_000_000, $this->bkash);
    $bkash = AccountingService::balance($this->bkash->id);

    expect(fn () => Sourcing::cancel($lot, 'Supplier cannot supply'))->toThrow(RuntimeException::class, 'cannot be cancelled');
    SourcingPayments::voidSupplierPayment($payment, 'Cancelled order', $this->finance, 'refund_received', 4_000_000, 'BKASH-REFUND-9');
    saVerify(Expense::findOrFail($payment->refresh()->reversal_expense_id));

    expect(AccountingService::balance($this->bkash->id))->toBe($bkash + 4_000_000)->and(dr('assets.supplier_advances'))->toBe(0);
    Sourcing::cancel($lot->refresh(), 'Supplier cannot supply');
    expect($lot->refresh()->status)->toBe('cancelled');
});

it('capitalises transport and loading, keeps them out of expenses and splits a shared cost exactly across projects', function () {
    $co = $this->co;
    $finance = $this->finance;
    $lot = Sourcing::createLot(['title' => 'Two products'], $finance);
    $p1 = Sourcing::addLine($lot, ['supplier_id' => $this->supplier->id, 'description' => 'Product one', 'quantity' => '10', 'unit_price' => 400_000], $finance);
    $p2 = Sourcing::addLine($lot, ['supplier_id' => $this->supplier->id, 'description' => 'Product two', 'quantity' => '5', 'unit_price' => 333_333], $finance);
    $transport = Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => 200_000, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance);
    $loading = Sourcing::addCost($lot, ['category' => 'Loading/unloading', 'amount' => 50_001, 'kind' => 'actual', 'incurred_on' => '2026-11-04'], $finance); // an odd paisa
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 100_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, $this->board);
    $a = $co->project($co->client('Client A'), 'Project A');
    $b = $co->project($co->client('Client B'), 'Project B');
    foreach ([[$a, $p1, '6'], [$b, $p1, '3'], [$a, $p2, '5']] as [$project, $line, $qty]) {
        Sourcing::allocate($lot->refresh(), ['project_id' => $project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => $qty], $finance);
    }

    $cash = AccountingService::totalCash();
    foreach ([$transport, $loading] as $cost) {
        SourcingPayments::payCost($cost->refresh(), '2026-11-05', $finance, $this->bank->id);
        saVerify($cost->refresh()->expense);
    }
    // Paid freight is product cost held in inventory: cash left once, no operating expense was created.
    expect(AccountingService::totalCash())->toBe($cash - 250_001)->and(dr('assets.inventory'))->toBe(250_001)->and(dr('expense'))->toBe(0)
        ->and(saProfit()['total_expenses'])->toBe(0)->and(CostRecognitionService::held($lot->refresh()))->toBe(250_001);

    $f = LotFinancials::for($lot);
    $buckets = ProjectSourcingReport::buckets($lot, $f);
    expect($f['landed'])->toBe(4_000_000 + 1_666_665 + 250_001)->and(array_sum(array_column($buckets, 'cost')))->toBe($f['landed']) // not one paisa lost or created
        ->and(array_sum(array_column($buckets, 'costs_paid')))->toBe(250_001)->and($buckets['unallocated']['agreed'])->toBe(0);
});

it('reports the owner\'s example: agreed margin, actual margin, cash surplus and recognised margin stay apart, provisional until final', function () {
    $co = $this->co;
    // Client agrees BDT 50,000; supplier BDT 40,000 paid in advance; transport 2,000 and loading 500 paid.
    [$lot, $line, $costs] = saLot(costs: [['Transportation', 200_000], ['Loading/unloading', 50_000]], profit: 75_000);
    $project = $co->project($co->client('Client'), 'Project');
    $allocation = Sourcing::allocate($lot, ['project_id' => $project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '10'], $this->finance);
    $invoice = Sourcing::bill($allocation, null, null, $this->finance);
    $co->payInvoice($invoice->refresh(), '50000.00', '2026-11-10')->assertSessionHasNoErrors();
    saPay($lot, 4_000_000, $this->bank);
    foreach ($costs as $cost) {
        SourcingPayments::payCost($cost, '2026-11-05', $this->finance, $this->cash->id);
        saVerify($cost->refresh()->expense);
    }

    $p = ProjectSourcingReport::build()['projects'][$project->id];
    expect($p['agreed'])->toBe(5_000_000)->and($p['actual_cost'])->toBe(4_250_000)->and($p['actual_margin'])->toBe(750_000)->and($p['estimated_margin'])->toBe(750_000)
        ->and($p['received'])->toBe(5_000_000)->and($p['cash_surplus'])->toBe(750_000)->and($p['margin_status'])->toBe('provisional')->and($p['recognised_margin'])->toBe(0);
    // Receiving cash and paying the supplier is not profit: nothing is recognised before acceptance and the approved release.
    expect(saProfit()['total_expenses'])->toBe(0);

    saArrive($line, '10', '10');
    saSettle($lot);
    Sourcing::advance($lot, $this->finance);
    Sourcing::advance($lot, $this->finance); // completed
    $p = ProjectSourcingReport::build()['projects'][$project->id];
    expect($p['margin_status'])->toBe('final')->and($p['recognised_margin'])->toBe(0); // final cost, still not recognised: that needs the Board's trigger

    config(['finance.sourcing_recognition_trigger' => 'lot_completed']);
    $release = CostRecognitionService::propose($lot->refresh(), '2026-11-10', $this->finance);
    CostRecognitionService::decide($release, true, $this->board);
    $p = ProjectSourcingReport::build()['projects'][$project->id];
    expect($release->amount)->toBe(4_250_000)->and($p['recognised_margin'])->toBe(750_000)->and($p['recognised_margin'])->toBe($p['actual_margin'])
        ->and(saProfit()['total_expenses'])->toBe(4_250_000)->and(dr('assets.inventory'))->toBe(0)->and(dr('expense'))->toBe(4_250_000);
});

it('keeps cost recognition off until the Board approves a trigger and then releases the held cost exactly once', function () {
    [$lot, $line] = saLot(costs: [['Transportation', 200_000]]);
    saPay($lot, 4_000_000);
    SourcingPayments::payCost(SourcingLotCost::firstOrFail(), '2026-11-05', $this->finance);
    saVerify(SourcingLotCost::firstOrFail()->expense);
    saArrive($line, '10', '10');
    saSettle($lot);
    $cash = AccountingService::totalCash();

    expect(fn () => CostRecognitionService::propose($lot, '2026-11-10', $this->finance))->toThrow(RuntimeException::class, 'switched off');
    config(['finance.sourcing_recognition_trigger' => 'lot_completed']);
    expect(fn () => CostRecognitionService::propose($lot->refresh(), '2026-11-10', $this->finance))->toThrow(RuntimeException::class, 'not completed'); // arrival is not a trigger

    Sourcing::advance($lot, $this->finance);
    Sourcing::advance($lot, $this->finance);
    $release = CostRecognitionService::propose($lot->refresh(), '2026-11-10', $this->finance);
    expect($release->amount)->toBe(4_200_000)->and(dr('expense'))->toBe(0); // proposal posts nothing
    expect(fn () => CostRecognitionService::propose($lot, '2026-11-10', $this->finance))->toThrow(RuntimeException::class, 'already been released');
    expect(fn () => CostRecognitionService::decide($release, true, $this->finance))->toThrow(RuntimeException::class, 'you proposed'); // the maker cannot verify
    CostRecognitionService::decide($release, true, $this->board);

    expect(dr('expense'))->toBe(4_200_000)->and(dr('assets.inventory'))->toBe(0)->and(AccountingService::totalCash())->toBe($cash)
        ->and(saProfit()['total_expenses'])->toBe(4_200_000)->and(CostRecognitionService::unreleased($lot->refresh()))->toBe(0);
    // Two simultaneous releases cannot both exist: the database itself refuses the second.
    expect(fn () => DB::table('cost_recognitions')->insert(['sourcing_lot_id' => $lot->id, 'amount' => 1, 'recognised_on' => '2026-11-10', 'trigger' => 'lot_completed', 'created_by' => $this->finance->id,
        'active_lot_id' => $lot->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]))->toThrow(UniqueConstraintViolationException::class);

    // Undone by a reversing entry, by someone other than the maker; the cost returns to inventory.
    CostRecognitionService::reverse($release->refresh(), 'Recognised in error', $this->board2);
    expect(dr('expense'))->toBe(0)->and(dr('assets.inventory'))->toBe(4_200_000)->and(saProfit()['total_expenses'])->toBe(0)->and(CostRecognition::where('status', 'reversed')->count())->toBe(1);
    expect(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('blocks financial completion and the month of an unsettled advance on a finished lot, but never a live lot', function () {
    [$lot, $line] = saLot();
    saPay($lot, 4_000_000);

    // In progress: a valid outstanding advance is an asset, not an error.
    expect(FinancialCompleteness::blockersForMonth('2026-11'))->toBe([])->and(saGate('sourcing')['findings'])->toBe([])->and(saGate('sourcing')['status'])->toBe('clear');

    Sourcing::advance($lot, $this->finance);
    Sourcing::advance($lot, $this->finance); // completed, goods not received, advance still held
    $blockers = FinancialCompleteness::blockersForMonth('2026-11');
    expect($blockers)->toHaveCount(1)->and($blockers[0])->toContain('unsettled supplier advance')->and(saGate('sourcing')['status'])->toBe('blocking');
    expect(fn () => Sourcing::settle($lot->refresh(), $this->finance))->toThrow(RuntimeException::class, 'financially finalised');
    Carbon::setTestNow('2026-12-05');
    expect(fn () => PeriodProcessor::close('2026-11', $this->finance))->toThrow(RuntimeException::class, 'unsettled supplier advance');

    saArrive($line, '10', '10');
    saSettle($lot);
    expect(FinancialCompleteness::blockersForMonth('2026-11'))->toBe([]);
    Sourcing::settle($lot->refresh(), $this->finance);
    expect($lot->refresh()->status)->toBe('settled');
    PeriodProcessor::close('2026-11', $this->finance);
});

it('detects the supplier-advance discrepancies the owner listed', function () {
    [$lot, $line] = saLot(costs: [['Transportation', 200_000]]);
    $payment = saPay($lot, 4_000_000);
    $advance = fn (array $extra = []) => Expense::create(['category' => 'Sourcing: supplier payment', 'description' => 'Injected', 'amount' => 1_000, 'spent_on' => '2026-11-04', 'status' => 'verified',
        'created_by' => $this->finance->id, 'classification' => 'supplier_advance', 'payment_status' => 'paid', 'paid_amount' => 1_000, ...$extra]);
    $insert = fn (Expense $e, array $extra = []) => SupplierPayment::create(['supplier_id' => $this->supplier->id, 'sourcing_lot_id' => $lot->id, 'amount' => 1_000, 'paid_on' => '2026-11-04',
        'idempotency_key' => (string) Str::uuid(), 'expense_id' => $e->id, 'created_by' => $this->finance->id, ...$extra]);

    $unnamed = $advance();                                                    // an advance whose cash line names no financial account
    Ledger::post('2026-11-04', 'Injected advance', 'expense', $unnamed->id, [['assets.supplier_advances', null, 1_000, 0], ['cash', null, 0, 1_000, null]], null);
    $insert($advance(), ['sourcing_lot_id' => null]);                       // an advance attached to no lot
    $insert($advance());                                                      // pushes the lot over what it authorised
    $copy = $payment->replicate(['idempotency_key', 'expense_id']);          // the same payment again
    $copy->fill(['idempotency_key' => (string) Str::uuid(), 'expense_id' => $advance()->id])->save();
    $cost = SourcingLotCost::firstOrFail();
    $twin = $cost->replicate(['expense_id']);
    $twin->fill(['expense_id' => $advance(['classification' => 'acquisition'])->id])->save();                    // transport entered twice
    $cost->update(['expense_id' => $advance(['classification' => 'acquisition'])->id]);
    AdvanceSettlement::create(['sourcing_lot_id' => $lot->id, 'supplier_id' => $this->supplier->id, 'supplier_payment_id' => $payment->id, 'amount' => 500, 'settled_on' => '2026-11-06', // settled, nothing accepted, no journal
        'status' => 'verified', 'created_by' => $this->finance->id, 'verified_by' => $this->board->id, 'idempotency_key' => 'x-1']);
    CostRecognition::create(['sourcing_lot_id' => $lot->id, 'amount' => 9_999_999, 'recognised_on' => '2026-11-07', 'status' => 'verified', 'trigger' => 'lot_completed', 'created_by' => $this->finance->id, 'verified_by' => $this->board->id]);

    $messages = collect(FinancialCompleteness::report()['layers'])->flatMap(fn ($l) => $l['findings'])->map(fn ($f) => $f['severity'].': '.$f['message'])->implode("\n");
    foreach (['not attached to a sourcing lot', 'exceed the authorised purchase amount', 'Possible duplicate supplier payment', 'settled with no accepted goods', 'Verified advance settlement #',
        'has no journal entry', 'cost recognised twice', 'no identifiable financial account'] as $needle) {
        expect($messages)->toContain($needle);
    }
    expect(collect(FinancialCompleteness::report()['layers']['source']['findings'])->where('severity', 'blocker')->count())->toBeGreaterThanOrEqual(2); // missing source journals
});

it('never restates an earlier cash-basis payment, and flags a lot that mixes the two bases', function () {
    [$lot, $line] = saLot();
    // Dated before the accrual start: a plain cash-basis payment, expensed when paid, exactly as every existing record.
    $old = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $this->supplier->id, 'amount' => 1_000_000, 'paid_on' => '2026-10-20', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    expect($old->expense->classification)->toBe('operating')->and($old->purchase_order)->toBeNull();
    saVerify($old->expense);
    expect(dr('expense'))->toBe(1_000_000)->and(dr('assets.supplier_advances'))->toBe(0);

    saPay($lot, 3_000_000);   // the rest, as an advance
    saArrive($line, '10', '10');
    $settled = saSettle($lot);

    expect($settled->sum('amount'))->toBe(3_000_000)  // only the advance settles; the cash-basis part was already an expense
        ->and(dr('expense'))->toBe(1_000_000)->and(dr('assets.inventory'))->toBe(3_000_000);
    expect(collect(saGate('sourcing')['findings'])->pluck('message')->implode(' '))->toContain('mixes cash-basis supplier payments');
});

it('leaves the cash basis exactly as it was when the accrual basis is not enabled', function () {
    config(['finance.sourcing_basis' => 'cash']);
    [$lot] = saLot();
    $payment = SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $this->supplier->id, 'amount' => 4_000_000, 'paid_on' => '2026-11-03', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    saVerify($payment->expense);

    expect($payment->expense->classification)->toBe('operating')->and(dr('expense'))->toBe(4_000_000)->and(dr('assets.supplier_advances'))->toBe(0)->and(dr('assets.inventory'))->toBe(0)
        ->and(saProfit()['total_expenses'])->toBe(4_000_000);
    config(['finance.sourcing_basis' => 'accrual', 'finance.sourcing_accrual_from' => null]); // a basis without a start month is also inactive
    expect(SourcingBasis::accrualOn('2026-11-03'))->toBeFalse();
});

it('states exactly what the owner must supply for unsupported product receipts and unlinked lots, without linking anything', function () {
    $co = $this->co;
    $project = $co->project($co->client('Suchona'), 'Suchona Mart');
    $segment = $co->segment($project, 'Product purchasing', '7350');
    $invoice = $co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Zipper Hoodie', 'quantity' => '15', 'unit_price' => '490']], '2026-10-30', 'PR-1');
    $co->payInvoice($invoice, '7350', '2026-10-30')->assertSessionHasNoErrors();
    $payment = $invoice->payments()->firstOrFail();
    $co->post('finance', 'finance.completeness.classify', ['nature' => 'product_sourcing'], $payment)->assertSessionHasNoErrors();
    $draft = Sourcing::createLot(['title' => 'Hoodie lot'], $this->finance);
    Sourcing::addLine($draft, ['supplier_id' => $this->supplier->id, 'description' => 'Zipper Hoodie', 'quantity' => '15', 'unit_price' => 40_000], $this->finance);

    $checklist = FinancialCompleteness::evidenceChecklist();
    expect($checklist)->toHaveCount(2);
    $subjects = collect($checklist)->pluck('subject')->implode(' | ');
    expect($subjects)->toContain('PR-1')->toContain($draft->number);
    foreach ($checklist as $row) {
        expect($row['needed'])->toContain('Supplier identity')->toContain('Supplier payment amount')->toContain('Payment reference')->toContain('Arrival date')
            ->toContain('Accepted quantity (and any shortage or damage)')->toContain('Transportation amount')->toContain('Which project and invoice it belongs to');
    }
    // The matching 15 pieces did not link the lot to the invoice.
    expect($draft->allocations()->count())->toBe(0)->and(SupplierPayment::count())->toBe(0)->and(FinancialCompleteness::receipts()->first()['covered'])->toBeFalse();
    $co->as('finance')->get(route('finance.completeness'))->assertOk()->assertSee('Evidence the owner must supply')->assertSee('Supplier invoice or purchase order');
});

it('gives the Board, not Finance, the right to verify settlements and releases, and renders the advances page', function () {
    [$lot, $line] = saLot();
    saPay($lot, 4_000_000);
    saArrive($line, '10', '10');
    $this->co->post('finance', 'sourcing.advances.propose', ['supplier_id' => $this->supplier->id], $lot)->assertSessionHasNoErrors();
    $settlement = AdvanceSettlement::firstOrFail();

    $this->co->post('finance', 'sourcing.advances.decide', ['decision' => 'verify'], $settlement)->assertForbidden();
    $this->co->post('board', 'sourcing.advances.decide', ['decision' => 'verify'], $settlement)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($settlement->refresh()->status)->toBe('verified')->and(dr('assets.inventory'))->toBe(4_000_000);
    expect(Permissions::map()['super_admin'])->not->toContain('sourcing.advance.approve');
    $this->co->as('finance')->get(route('sourcing.advances'))->assertOk()->assertSee('Supplier Advances')->assertSee('Advance still held');
    $this->co->as('board')->get(route('sourcing.advances'))->assertOk();
});
