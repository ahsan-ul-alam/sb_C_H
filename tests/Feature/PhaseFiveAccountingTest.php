<?php

use App\Models\AccountingPeriod;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Services\AccountingService;
use App\Services\ExpenseVerifier;
use App\Services\IntegrityGate;
use App\Services\Ledger;
use App\Services\LotFinancials;
use App\Services\PeriodProcessor;
use App\Services\ProfitAllocator;
use App\Services\ProjectSourcingReport;
use App\Services\ReconciliationService;
use App\Services\Sourcing;
use App\Services\SourcingArrival;
use App\Services\SourcingPayments;
use App\Services\StatementReconciliationService;
use App\Support\Money;
use App\Support\Split;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Phase 5: exact paisa arithmetic, statement reconciliation and the integrity gate, the project-wise product sourcing figures
| (kept apart: agreed, billed, received, cost, margin, cash surplus), arrival quantities and supplier-cost link checks.
| "Today" is 2026-11-10. Every expectation is computed independently of the code under test.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01']);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
    $this->board = $this->co->users['board'];
    $this->cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
});

afterEach(fn () => Carbon::setTestNow());

function p5Check(string $needle): array
{
    return collect(ReconciliationService::report())->firstWhere('check', $needle);
}

/** Two products from two suppliers, three shared/direct costs (one odd paisa split), approved, then allocated to two projects. */
function p5Scenario(): array
{
    $co = test()->co;
    $finance = test()->finance;
    $s1 = Supplier::create(['name' => 'Supplier One']);
    $s2 = Supplier::create(['name' => 'Supplier Two']);
    $lot = Sourcing::createLot(['title' => 'Two products'], $finance);
    $p1 = Sourcing::addLine($lot, ['supplier_id' => $s1->id, 'description' => 'Product one', 'quantity' => '10', 'unit_price' => 4_000], $finance);
    $p2 = Sourcing::addLine($lot, ['supplier_id' => $s2->id, 'description' => 'Product two', 'quantity' => '5', 'unit_price' => 3_333], $finance);
    $transport = Sourcing::addCost($lot, ['category' => 'Transportation', 'amount' => 2_000, 'kind' => 'actual', 'incurred_on' => '2026-11-02'], $finance);
    Sourcing::addCost($lot, ['category' => 'Loading/unloading', 'amount' => 501, 'kind' => 'actual', 'incurred_on' => '2026-11-02'], $finance);
    Sourcing::addCost($lot, ['category' => 'Packaging', 'amount' => 100, 'kind' => 'actual', 'incurred_on' => '2026-11-02', 'line_id' => $p1->id], $finance);
    Sourcing::addCost($lot, ['category' => 'Handling', 'amount' => 777, 'kind' => 'estimate', 'incurred_on' => '2026-11-02'], $finance); // not incurred yet
    Sourcing::advance($lot, $finance);
    Sourcing::savePricing($lot, 'fixed', 1_000, [], $finance);
    Sourcing::advance($lot, $finance);
    Sourcing::approvePricing($lot, test()->board);
    $lot->refresh();

    $a = $co->project($co->client('Client A'), 'Project A');
    $b = $co->project($co->client('Client B'), 'Project B');
    $alloc = fn ($project, $line, string $qty) => Sourcing::allocate($lot, ['project_id' => $project->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => $qty], $finance);
    $allocations = ['a1' => $alloc($a, $p1, '6'), 'b1' => $alloc($b, $p1, '3'), 'a2' => $alloc($a, $p2, '5')]; // 1 of product one stays unallocated

    return compact('lot', 's1', 's2', 'p1', 'p2', 'transport', 'a', 'b', 'allocations');
}

it('keeps every amount in exact integer paisa', function () {
    expect(Money::toMinor('0.07'))->toBe(7)->and(Money::toMinor('0.7'))->toBe(70)->and(Money::toMinor('1234567890123.99'))->toBe(123_456_789_012_399)
        ->and(Money::multiply('3.00', 3_333))->toBe(9_999)->and(Money::multiply('0.33', 1))->toBe(0)->and(Money::multiply('0.50', 1))->toBe(1);
    // A shared cost is split without losing or inventing a paisa.
    expect(Split::apply(100, [1, 1, 1]))->toBe([34, 33, 33])->and(array_sum(Split::apply(2_501, [600, 300, 100])))->toBe(2_501);
    foreach ([[1, [1, 1]], [7, [3, 3, 3]], [1_000_001, [17, 29, 31, 5]], [99, [0, 5, 0]]] as [$amount, $weights]) {
        expect(array_sum(Split::apply($amount, $weights)))->toBe($amount);
    }
    // Sums of integer columns stay integers all the way from the database.
    JournalLine::query()->count();
    expect(is_int(AccountingService::totalCash()))->toBeTrue();
});

it('refuses to post the same record twice and never an unbalanced entry', function () {
    Ledger::post('2026-11-05', 'First', 'test', 99, [['cash', null, 100, 0], ['expense', null, 0, 100]], null);

    expect(fn () => Ledger::post('2026-11-05', 'Again', 'test', 99, [['cash', null, 100, 0], ['expense', null, 0, 100]], null))->toThrow(RuntimeException::class, 'already been posted')
        ->and(fn () => Ledger::post('2026-11-05', 'Skewed', 'test', 100, [['cash', null, 100, 0], ['expense', null, 0, 99]], null))->toThrow(RuntimeException::class, 'Unbalanced')
        ->and(JournalEntry::where('source_type', 'test')->count())->toBe(1);
    // The database itself also refuses a second entry for one source.
    expect(fn () => DB::table('journal_entries')->insert(['entry_date' => '2026-11-05', 'memo' => 'Raw duplicate', 'source_type' => 'test', 'source_id' => 99, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('attributes every paisa of a lot to projects or to unallocated stock, and nothing else', function () {
    $s = p5Scenario();
    $lot = $s['lot'];
    $f = LotFinancials::for($lot);
    $buckets = ProjectSourcingReport::buckets($lot, $f);

    // Independent expectation: purchase 40,000 + 16,665, additional 2,000 + 501 + 100 + the open 777 estimate.
    expect($f['purchase'])->toBe(56_665)->and($f['additional'])->toBe(3_378)->and($f['landed'])->toBe(60_043);
    expect(array_sum(array_column($buckets, 'cost')))->toBe(60_043);
    foreach (['supplier_paid', 'supplier_outstanding', 'costs_unpaid', 'estimates_open'] as $field) {
        expect(array_sum(array_column($buckets, $field)))->toBe((int) $f[$field], $field);
    }
    // Project A bought 6 of product one's 10 pieces and all 5 of product two; B bought 3 of product one; 1 piece is unallocated.
    $line1 = $f['lines'][$s['p1']->id];
    $line2 = $f['lines'][$s['p2']->id];
    expect($f['lines'][$s['p1']->id]['landed'] + $f['lines'][$s['p2']->id]['landed'])->toBe(60_043);
    $expectedA = $line1['landed'] * 6 / 10 + $line2['landed'];
    expect(abs($buckets[$s['a']->id]['cost'] - $expectedA))->toBeLessThan(2)
        ->and(abs($buckets[$s['b']->id]['cost'] - $line1['landed'] * 3 / 10))->toBeLessThan(1)
        ->and(abs($buckets['unallocated']['cost'] - $line1['landed'] / 10))->toBeLessThan(1);
    // The unallocated stock is never charged to a project.
    expect($buckets['unallocated']['agreed'])->toBe(0);
});

it('keeps agreed amount, margin, recognised margin and cash surplus apart, with partial client and supplier payments', function () {
    $s = p5Scenario();
    $lot = $s['lot'];
    $co = $this->co;

    // Supplier One is paid in two parts, Supplier Two in one part, the transport cost is paid.
    SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $s['s1']->id, 'amount' => 20_000, 'paid_on' => '2026-11-05', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $s['s1']->id, 'amount' => 10_000, 'paid_on' => '2026-11-06', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => $s['s2']->id, 'amount' => 5_000, 'paid_on' => '2026-11-06', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    SourcingPayments::payCost($s['transport'], '2026-11-06', $this->finance);
    // Client A is billed for its product-one allocation and pays part of it; Client B is billed and pays nothing.
    $invoiceA = Sourcing::bill($s['allocations']['a1'], null, null, $this->finance);
    Sourcing::bill($s['allocations']['b1'], null, null, $this->finance);
    $co->payInvoice($invoiceA->refresh(), '30.00', '2026-11-07')->assertSessionHasNoErrors();

    $f = LotFinancials::for($lot->refresh());
    $report = ProjectSourcingReport::build();
    $a = $report['projects'][$s['a']->id];
    $b = $report['projects'][$s['b']->id];

    // Supplier balances are exact: 40,000 − 30,000 and 16,665 − 5,000.
    expect($f['supplier_paid'])->toBe(35_000)->and($f['supplier_outstanding'])->toBe(21_665);
    // Agreed amounts follow the allocations (computed here from quantity × approved price).
    $price = fn ($line) => $lot->lines->find($line->id)->approved_price;
    expect($a['agreed'])->toBe(6 * $price($s['p1']) + 5 * $price($s['p2']))->and($b['agreed'])->toBe(3 * $price($s['p1']));
    // Client side: billed and received are separate from agreed.
    expect($a['received'])->toBe(3_000)->and($a['billed'])->toBe(6 * $price($s['p1']))->and($a['client_outstanding'])->toBe($a['agreed'] - 3_000)->and($b['received'])->toBe(0);
    // Margin is agreed − attributable cost; cash surplus is received − paid out. They are different numbers.
    expect($a['estimated_margin'])->toBe($a['agreed'] - $a['cost'])->and($a['cash_surplus'])->toBe($a['received'] - $a['supplier_paid'] - $a['costs_paid'])
        ->and($a['cash_surplus'])->not->toBe($a['estimated_margin'])->and($b['cash_surplus'])->toBe(-($b['supplier_paid'] + $b['costs_paid']));
    // Project figures plus the unallocated bucket explain the lot's money to the paisa.
    $unallocated = $report['lots'][0]['buckets']['unallocated'];
    expect($a['supplier_paid'] + $b['supplier_paid'] + $unallocated['supplier_paid'])->toBe(35_000)
        ->and($a['costs_paid'] + $b['costs_paid'] + $unallocated['costs_paid'])->toBe(2_000)
        ->and($a['estimates_open'] + $b['estimates_open'] + $unallocated['estimates_open'])->toBe(777);
    // Nothing is recognised until the lot is completed (receiving cash or goods is not enough).
    expect($a['recognised_margin'])->toBe(0)->and($b['recognised_margin'])->toBe(0);
    Sourcing::advance($lot, $this->finance); // approved → in progress
    expect(ProjectSourcingReport::build()['projects'][$s['a']->id]['recognised_margin'])->toBe(0);
    Sourcing::advance($lot, $this->finance); // in progress → completed
    $after = ProjectSourcingReport::build()['projects'];
    expect($after[$s['a']->id]['recognised_margin'])->toBe($after[$s['a']->id]['estimated_margin'])->and($after[$s['b']->id]['recognised_margin'])->toBe($after[$s['b']->id]['estimated_margin']);
});

it('reports several lots of one project together and shows the page', function () {
    $first = p5Scenario();
    $co = $this->co;
    $lot2 = Sourcing::createLot(['title' => 'Second lot'], $this->finance);
    $line = Sourcing::addLine($lot2, ['supplier_id' => $first['s2']->id, 'description' => 'Product three', 'quantity' => '4', 'unit_price' => 2_500], $this->finance);
    Sourcing::advance($lot2, $this->finance);
    Sourcing::savePricing($lot2, 'fixed', 500, [], $this->finance);
    Sourcing::advance($lot2, $this->finance);
    Sourcing::approvePricing($lot2, $this->board);
    Sourcing::allocate($lot2->refresh(), ['project_id' => $first['a']->id, 'line_id' => $line->id, 'kind' => 'sale', 'quantity' => '4'], $this->finance);

    $report = ProjectSourcingReport::build();
    $a = $report['projects'][$first['a']->id];
    expect($a['lots'])->toHaveCount(2)->and($a['cost'])->toBe(collect($report['lots'])->sum(fn ($l) => $l['buckets'][$first['a']->id]['cost'] ?? 0))
        ->and(collect($report['lots'])->sum(fn ($l) => array_sum(array_column($l['buckets'], 'cost'))))->toBe(60_043 + 10_000);
    $co->as('finance')->get(route('sourcing.projects'))->assertOk()->assertSee('Project A')->assertSee('Estimated margin');
});

it('never treats ordered goods as received, and keeps accepted within received', function () {
    $s = Supplier::create(['name' => 'Arrivals']);
    $lot = Sourcing::createLot(['title' => 'Arrival lot'], $this->finance);
    $line = Sourcing::addLine($lot, ['supplier_id' => $s->id, 'description' => 'Goods', 'quantity' => '10', 'unit_price' => 1_000], $this->finance);

    $q = ProjectSourcingReport::quantities(LotFinancials::for($lot->refresh()))[0];
    expect($q['received'])->toBeNull()->and($q['pending'])->toBeNull()->and($q['purchased'])->toBe('10.00'); // not recorded, not assumed

    // Arrival rules.
    expect(fn () => SourcingArrival::record($line, '11', null, '2026-11-08', $this->finance))->toThrow(RuntimeException::class, 'between 0 and the purchased')
        ->and(fn () => SourcingArrival::record($line, '8', null, '2026-11-08', $this->finance))->toThrow(RuntimeException::class, 'state the accepted quantity')
        ->and(fn () => SourcingArrival::record($line, '8', '9', '2026-11-08', $this->finance))->toThrow(RuntimeException::class, 'cannot exceed the quantity received')
        ->and(fn () => SourcingArrival::record($line, '8', '7', '2026-11-11', $this->finance))->toThrow(RuntimeException::class, 'future');
    SourcingArrival::record($line, '8', '7', '2026-11-08', $this->finance);

    $q = ProjectSourcingReport::quantities(LotFinancials::for($lot->refresh()))[0];
    expect($q['received'])->toBe('8.00')->and($q['accepted'])->toBe('7.00')->and($q['pending'])->toBe('2.00')->and($q['not_usable'])->toBe('3.00')
        ->and($q['arrived_on']->toDateString())->toBe('2026-11-08');
    // Accepted quantity drives the unit cost: 10,000 landed over 7 usable pieces.
    expect(LotFinancials::for($lot)['lines'][$line->id]['unit_cost'])->toBe('1428.5714');

    // After pricing approval the usable quantity is locked; a plain arrival of what was accepted is still allowed.
    Sourcing::advance($lot, $this->finance);
    Sourcing::savePricing($lot, 'fixed', 100, [], $this->finance);
    Sourcing::advance($lot, $this->finance);
    Sourcing::approvePricing($lot, $this->board);
    expect(fn () => SourcingArrival::record($line, '8', '6', '2026-11-09', $this->finance))->toThrow(RuntimeException::class, 'locked once pricing is approved');
    SourcingArrival::record($line, '9', '7', '2026-11-09', $this->finance);
    expect($line->refresh()->received_quantity)->toBe('9.00');
    // A cancelled lot cannot change.
    $other = Sourcing::createLot(['title' => 'Cancelled lot'], $this->finance);
    $otherLine = Sourcing::addLine($other, ['supplier_id' => $s->id, 'description' => 'Goods', 'quantity' => '2', 'unit_price' => 100], $this->finance);
    Sourcing::cancel($other, 'test');
    expect(fn () => SourcingArrival::record($otherLine, '1', null, '2026-11-08', $this->finance))->toThrow(RuntimeException::class, 'can no longer be changed');
});

it('flags a one-paisa difference against a verified statement and blocks close and allocation until it is explained', function () {
    $this->co->invest('A', '100000', '2026-10-20');
    $period = PeriodProcessor::close('2026-10', $this->finance);
    PeriodProcessor::verify($period, $this->board);
    expect(IntegrityGate::blockers())->toBe([]);

    $ledger = StatementReconciliationService::ledgerBalanceAsOf($this->cash->id, '2026-11-09');
    expect($ledger)->toBe(100_000_00);
    // The statement is one paisa lower than the books: 0.01, nothing more.
    $statement = StatementReconciliationService::record($this->cash, ['statement_date' => '2026-11-09', 'statement_balance' => $ledger - 1, 'reference' => 'STMT-1'], $this->finance);
    expect(IntegrityGate::blockers())->toBe([]); // a statement nobody verified yet proves nothing
    StatementReconciliationService::decide($statement, true, $this->board);

    $position = StatementReconciliationService::position($statement->refresh());
    expect($position['unexplained'])->toBe(1)->and($position['state'])->toBe('unexplained difference')
        ->and(IntegrityGate::blockers())->toHaveCount(1)->and(IntegrityGate::blockers()[0])->toContain('0.01');
    expect(p5Check('Accounts whose verified statement is not fully explained')['ok'])->toBeFalse();
    expect(fn () => PeriodProcessor::close('2026-09', $this->finance))->toThrow(RuntimeException::class, 'unexplained difference');
    expect(fn () => ProfitAllocator::prepare($period->refresh(), $this->finance))->toThrow(RuntimeException::class, 'unexplained difference');
    expect(AccountingPeriod::where('month', '2026-09')->exists())->toBeFalse()->and(DB::table('allocations')->count())->toBe(0);

    // The books were not touched to make it disappear: the only way forward is a later statement that explains the difference.
    expect(StatementReconciliationService::ledgerBalanceAsOf($this->cash->id, '2026-11-09'))->toBe($ledger);
    $fixed = StatementReconciliationService::record($this->cash, ['statement_date' => '2026-11-10', 'statement_balance' => $ledger - 1, 'reference' => 'STMT-2',
        'items' => [['kind' => 'unrecorded_charge', 'amount' => 1, 'description' => 'Bank fee on the statement, not yet booked']]], $this->finance);
    StatementReconciliationService::decide($fixed, true, $this->board);

    expect(StatementReconciliationService::position($fixed->refresh())['unexplained'])->toBe(0)->and(IntegrityGate::blockers())->toBe([])
        ->and(p5Check('Accounts whose verified statement is not fully explained')['ok'])->toBeTrue();
    // Allocation is no longer blocked by the gate (it stops for the real reason: this month made no profit).
    expect(fn () => ProfitAllocator::prepare($period->refresh(), $this->finance))->toThrow(RuntimeException::class, 'nothing to distribute');
});

it('guards statement entry: evidence, a second person, dates and duplicates', function () {
    $this->co->invest('A', '5000', '2026-11-02');
    $base = ['statement_date' => '2026-11-09', 'statement_balance' => 5_000_00];

    expect(fn () => StatementReconciliationService::record($this->cash, [...$base, 'reference' => '  '], $this->finance))->toThrow(RuntimeException::class, 'reference')
        ->and(fn () => StatementReconciliationService::record($this->cash, [...$base, 'statement_date' => '2026-11-11', 'reference' => 'X'], $this->finance))->toThrow(RuntimeException::class, 'future')
        ->and(fn () => StatementReconciliationService::record($this->cash, [...$base, 'reference' => 'X', 'items' => [['kind' => 'bogus', 'amount' => 5, 'description' => 'x']]], $this->finance))->toThrow(RuntimeException::class, 'type and a description');
    $statement = StatementReconciliationService::record($this->cash, [...$base, 'reference' => 'STMT-A'], $this->finance);
    expect(fn () => StatementReconciliationService::record($this->cash, [...$base, 'reference' => 'STMT-A2'], $this->finance))->toThrow(RuntimeException::class, 'already recorded')
        ->and(fn () => StatementReconciliationService::decide($statement, true, $this->finance))->toThrow(RuntimeException::class, 'you recorded');
    StatementReconciliationService::decide($statement, true, $this->board);
    expect(fn () => StatementReconciliationService::decide($statement, true, $this->co->users['board2']))->toThrow(RuntimeException::class, 'Already decided');

    // The books at a statement date ignore entries made after it.
    $this->co->invest('B', '1000', '2026-11-10');
    expect(StatementReconciliationService::ledgerBalanceAsOf($this->cash->id, '2026-11-09'))->toBe(5_000_00)->and(StatementReconciliationService::ledgerBalanceAsOf($this->cash->id, '2026-11-10'))->toBe(6_000_00)
        ->and(StatementReconciliationService::position($statement->refresh())['unexplained'])->toBe(0);
    // The page shows calculated and verified figures separately.
    $this->co->as('finance')->get(route('finance.statements'))->assertOk()->assertSee('not verified')->assertSee('STMT-A');
});

it('moves money between own accounts without revenue, expense or a false discrepancy', function () {
    $this->co->invest('A', '10000', '2026-11-02');
    $this->co->post('finance', 'finance.transfers.store', ['from' => (string) $this->cash->id, 'to' => (string) $this->bank->id, 'amount' => '4000', 'date' => '2026-11-05', 'idempotency_key' => (string) Str::uuid()])
        ->assertSessionHasNoErrors();
    $total = AccountingService::totalCash();

    expect($total)->toBe(10_000_00)->and(dr('income'))->toBe(0)->and(dr('expense'))->toBe(0)
        ->and(AccountingService::balance($this->cash->id))->toBe(6_000_00)->and(AccountingService::balance($this->bank->id))->toBe(4_000_00);
    foreach ([[$this->cash, 6_000_00], [$this->bank, 4_000_00]] as [$account, $balance]) {
        $s = StatementReconciliationService::record($account, ['statement_date' => '2026-11-09', 'statement_balance' => $balance, 'reference' => 'S-'.$account->id], $this->finance);
        StatementReconciliationService::decide($s, true, $this->board);
        expect(StatementReconciliationService::position($s->refresh())['unexplained'])->toBe(0);
    }
    expect(IntegrityGate::blockers())->toBe([]);
});

it('blocks a close on an unbalanced entry and refuses to hide it with a balancing entry', function () {
    $id = DB::table('journal_entries')->insertGetId(['entry_date' => '2026-10-15', 'memo' => 'Corrupt', 'source_type' => 'test', 'source_id' => 7, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('journal_lines')->insert([['journal_entry_id' => $id, 'account' => 'cash', 'debit' => 100, 'credit' => 0], ['journal_entry_id' => $id, 'account' => 'expense', 'debit' => 0, 'credit' => 99]]);

    expect(IntegrityGate::blockers()[0])->toContain('out of balance')
        ->and(p5Check('Journal entries whose debits differ from credits')['ok'])->toBeFalse()
        ->and(fn () => PeriodProcessor::close('2026-10', $this->finance))->toThrow(RuntimeException::class, 'out of balance');
    // The ledger will not accept a plug entry either, and the unexplained paisa stays visible.
    expect(fn () => Ledger::post('2026-10-31', 'Plug', 'test', 8, [['cash', null, 1, 0]], null))->toThrow(RuntimeException::class, 'Unbalanced')
        ->and(AccountingPeriod::count())->toBe(0);
});

it('finds supplier costs recorded outside the sourcing module or attached to two records', function () {
    $s = p5Scenario();
    $this->co->invest('A', '1000', '2026-11-03');
    SourcingPayments::recordSupplierPayment($s['lot'], ['supplier_id' => $s['s1']->id, 'amount' => 5_000, 'paid_on' => '2026-11-05', 'idempotency_key' => (string) Str::uuid()], $this->finance);
    // A properly linked sourcing payment is not flagged.
    expect(p5Check('Supplier-cost expenses not linked to a sourcing payment or lot cost')['recorded'])->toBe(0);

    // The same supplier cost keyed into the Expense module by hand sits outside the lot: its margin and supplier balance cannot see it.
    $manual = ExpenseVerifier::record(['category' => 'Sourcing: supplier payment', 'description' => 'Hand-typed payment to Supplier One', 'amount' => 5_000, 'spent_on' => '2026-11-05'], $this->finance);
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $manual)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect($manual->refresh()->status)->toBe('verified');
    $row = p5Check('Supplier-cost expenses not linked to a sourcing payment or lot cost');
    expect($row['recorded'])->toBe(5_000)->and($row['ok'])->toBeFalse();

    // One expense attached to two sourcing records would count a cost twice.
    $expenseId = $s['lot']->payments()->first()->expense_id;
    DB::table('supplier_payments')->insert(['supplier_id' => $s['s1']->id, 'sourcing_lot_id' => $s['lot']->id, 'amount' => 1, 'paid_on' => '2026-11-06', 'idempotency_key' => 'dup-link', 'expense_id' => $expenseId,
        'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(p5Check('Expenses used by more than one sourcing record')['recorded'])->toBe(1);
});

it('keeps a supplier payment from becoming a second expense', function () {
    $s = p5Scenario();
    $before = AccountingService::totalCash();
    $this->co->invest('A', '50000', '2026-11-03');
    $payment = SourcingPayments::recordSupplierPayment($s['lot'], ['supplier_id' => $s['s1']->id, 'amount' => 8_000, 'paid_on' => '2026-11-05', 'idempotency_key' => $key = (string) Str::uuid()], $this->finance);
    $this->co->post('board', 'finance.expenses.decide', ['decision' => 'verify'], $payment->expense)->assertSessionHasNoErrors();

    // Cash leaves once, as one expense; repeating the submission records nothing more.
    expect(SourcingPayments::recordSupplierPayment($s['lot'], ['supplier_id' => $s['s1']->id, 'amount' => 8_000, 'paid_on' => '2026-11-05', 'idempotency_key' => $key], $this->finance))->toBeNull()
        ->and(AccountingService::totalCash())->toBe($before + 50_000_00 - 8_000)->and(JournalEntry::where('source_type', 'expense')->where('source_id', $payment->expense_id)->count())->toBe(1)
        ->and(DB::table('supplier_payments')->count())->toBe(1)
        ->and(p5Check('Supplier-cost expenses not linked to a sourcing payment or lot cost')['recorded'])->toBe(0);
    expect(fn () => SourcingPayments::recordSupplierPayment($s['lot'], ['supplier_id' => $s['s1']->id, 'amount' => 40_000, 'paid_on' => '2026-11-05'], $this->finance))
        ->toThrow(RuntimeException::class, 'exceeds what this lot owes');
});
