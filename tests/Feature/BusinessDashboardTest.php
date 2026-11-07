<?php

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\SourcingLot;
use App\Services\AccountingService;
use App\Services\BusinessDashboard;
use App\Services\Ledger;
use App\Services\ProjectSourcingReport;
use App\Services\StatementReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../Support/inventory_helpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function bdAll(?string $from = null, string $to = '2026-12-31'): array
{
    return BusinessDashboard::build($from ? CarbonImmutable::parse($from) : null, CarbonImmutable::parse($to));
}

function bdReceipt($co, int $amount, string $on, ?string $nature = null): Payment
{
    $client = $co->client('Client '.Str::random(4));
    $project = $co->project($client, 'P '.Str::random(3));
    $p = new Payment(['amount' => $amount, 'paid_on' => $on, 'method' => 'cash', 'reference' => 'R'.Str::random(4)]);
    $p->forceFill(['project_id' => $project->id, 'idempotency_key' => (string) Str::uuid()])->save();
    if ($nature) {
        ReceiptClassification::create(['payment_id' => $p->id, 'nature' => $nature, 'classified_by' => $co->users['finance']->id]);
    }

    return $p;
}

/** A collection recorded against an invoice whose line says $text (that is the evidence the dashboard splits by). */
function bdCollection($co, int $amount, string $on, string $text): Payment
{
    $client = $co->client('Client '.Str::random(4));
    $project = $co->project($client, 'P '.Str::random(3));
    $invoice = new Invoice(['number' => 'T-'.Str::random(6), 'issued_on' => $on, 'currency' => 'BDT']);
    $invoice->forceFill(['project_id' => $project->id, 'total' => $amount, 'amount_paid' => $amount, 'status' => 'paid'])->save();
    $invoice->items()->create(['description' => $text, 'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount, 'position' => 0]);
    $p = new Payment(['amount' => $amount, 'paid_on' => $on, 'method' => 'cash', 'reference' => 'R'.Str::random(4)]);
    $p->forceFill(['project_id' => $project->id, 'invoice_id' => $invoice->id, 'idempotency_key' => (string) Str::uuid()])->save();

    return $p;
}

it('explains the funding like the 7 November backup: investment, collections, recorded outflows, provisional remainder, never profit', function () {
    $co = SellbeesCompany::boot($this);
    $co->invest('A', '26980', '2026-10-25');
    $co->invest('B', '11317', '2026-10-25');
    $co->invest('C', '16940', '2026-10-26');                                                   // 55,237.00
    foreach ([10_000, 10_000] as $a) {
        bdCollection($co, $a * 100, '2026-10-30', 'Onboarding');
    }
    foreach ([2_650, 2_650] as $a) {
        bdCollection($co, $a * 100, '2026-10-31', 'Marketing Charges');
    }
    foreach ([7_350, 10_000, 9_200, 7_350, 19_200] as $a) {
        bdCollection($co, $a * 100, '2026-11-01', 'Zipper Hoodie product purchase');            // 78,400.00 in all
    }
    $co->expense('Office', 'Operating costs', '48403', '2026-10-28');
    $co->expense('Furniture', 'Equipment and furniture tagged asset', '22693', '2026-10-31', asset: true);
    $co->expense('Sourcing: supplier payment', 'Lot payments', '27530', '2026-11-03');
    $co->expense('Sourcing: Transportation', 'Lot transport', '280', '2026-11-03');
    foreach (['8000', '500'] as $loanAmount) {
        $loan = $co->expense('Loan', 'Loan lent', $loanAmount, '2026-11-05');
        DB::table('loan_receivables')->insert(['borrower_name' => 'X', 'expense_id' => $loan->id, 'principal' => (int) $loanAmount * 100, 'lent_on' => '2026-11-05', 'status' => 'verified', 'created_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $d = bdAll(null, '2026-11-07');
    $f = $d['funding'];
    expect($f['investment'])->toBe(5_523_700)->and($f['collections'])->toBe(7_840_000)
        ->and($f['collection_split']['onboarding']['amount'])->toBe(2_000_000)->and($f['collection_split']['marketing']['amount'])->toBe(530_000)->and($f['collection_split']['product_sourcing']['amount'])->toBe(5_310_000)
        ->and($f['total_in'])->toBe(13_363_700)->and($f['total_out'])->toBe(10_740_600)->and($f['remaining'])->toBe(2_623_100)               // 26,231.00
        ->and($f['outflows'])->toMatchArray(['operating' => 4_840_300, 'equipment' => 2_269_300, 'sourcing' => 2_781_000, 'loans' => 850_000])
        ->and($f['collections_not_in_ledger'])->toBe(7_840_000)->and($f['status'])->toBe('unverified');
    // Funding is not profit: no collection is earned revenue, the expenses are the owner-policy total, and the result is never presented as a final loss.
    expect($d['revenue']['verified'])->toBe(0)->and($d['expenses']['total'])->toBe(7_109_600)->and($d['net_profit']['status'])->not->toBe('verified')
        ->and($d['net_profit']['amount'])->toBe(-7_109_600);
    $co->as('finance')->get(route('dashboard'))->assertOk()->assertSee('Where the money came from')->assertSee('Expected closing cash')->assertSee('Provisional position, not a final profit or loss');
});

it('shows unclassified collections as funding inflows without ever making them earned revenue, and counts nothing twice', function () {
    $co = SellbeesCompany::boot($this);
    $p = bdReceipt($co, 300_000, '2026-02-12');                                         // no classification, no evidence of its nature
    $before = bdAll()['funding'];
    expect($before['collections'])->toBe(300_000)->and($before['total_in'])->toBe(300_000)->and($before['collection_split']['other']['amount'])->toBe(300_000)
        ->and(bdAll()['revenue']['verified'])->toBe(0)->and(bdAll()['net_profit']['status'])->toBe('incomplete');

    // The same collection posted to the ledger in real time must not be counted again, and a transfer between accounts changes neither side.
    Ledger::post('2026-02-12', 'Receipt', 'payment', $p->id, [['cash', null, 300_000, 0], ['client.funds', null, 0, 300_000]], null);
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $co->invest('A', '1000', '2026-02-01');
    $mid = bdAll()['funding'];
    AccountingService::transfer(['from' => $cash->id, 'to' => $bank->id, 'amount' => 30_000, 'date' => '2026-02-13', 'idempotency_key' => (string) Str::uuid()], $co->users['finance']);
    $after = bdAll()['funding'];

    expect($mid['collections'])->toBe(300_000)->and($mid['total_in'])->toBe(400_000)->and($mid['collections_not_in_ledger'])->toBe(0)
        ->and($after['total_in'])->toBe($mid['total_in'])->and($after['total_out'])->toBe($mid['total_out']);
});

it('does not let partner capital change profit', function () {
    $co = SellbeesCompany::boot($this);
    $co->invest('A', '50000', '2026-02-02');
    $d = bdAll();

    expect($d['investment']['net'])->toBe(5_000_000)->and($d['net_profit']['amount'])->toBe(0)->and($d['revenue']['verified'])->toBe(0);
});

it('counts every office purchase once, including equipment-tagged ones, and adds no depreciation', function () {
    $co = SellbeesCompany::boot($this);
    $co->invest('A', '100000', '2026-02-01');
    $co->expense('Office', 'Rent', '500', '2026-02-05');
    $co->expense('Furniture', 'Sofa tagged asset', '1000', '2026-02-06', asset: true);

    $feb = bdAll('2026-02-01', '2026-02-28');
    $mar = bdAll('2026-03-01', '2026-03-31');
    expect($feb['expenses']['total'])->toBe(150_000)->and($feb['expenses']['asset_tagged'])->toBe(100_000)->and($feb['expenses']['other'])->toBe(50_000)
        ->and($feb['net_profit']['amount'])->toBe(-150_000)
        ->and($mar['expenses']['total'])->toBe(0);                                         // no monthly release: the asset is not charged again later
    expect(DB::table('journal_lines')->where('account', 'like', '%depreciation%')->count())->toBe(0);
});

it('excludes supplier advances, loan principal, pending entries and reversals from management expenses', function () {
    $co = SellbeesCompany::boot($this);
    $co->invest('A', '100000', '2026-02-01');
    $co->expense('Sourcing: supplier payment', 'Lot payment', '800', '2026-02-05');          // goes through its lot, never an office cost
    $loan = $co->expense('Loan', 'Loan to a partner', '300', '2026-02-06');
    DB::table('loan_receivables')->insert(['borrower_name' => 'X', 'expense_id' => $loan->id, 'principal' => 30_000, 'lent_on' => '2026-02-06', 'status' => 'verified', 'created_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);
    $real = $co->expense('Office', 'Paper', '200', '2026-02-07');
    $mistake = $co->expense('Office', 'Entered twice', '200', '2026-02-07');
    (new Expense)->forceFill(['category' => 'Office', 'description' => 'Reversal', 'amount' => 20_000, 'spent_on' => '2026-02-08', 'status' => 'verified', 'created_by' => $co->users['finance']->id,
        'reverses_id' => $mistake->id, 'correction_type' => 'recording_error', 'payment_status' => 'paid', 'paid_amount' => 20_000])->save();
    (new Expense)->forceFill(['category' => 'Office', 'description' => 'Not yet verified', 'amount' => 90_000, 'spent_on' => '2026-02-09', 'status' => 'pending', 'created_by' => $co->users['finance']->id,
        'payment_status' => 'paid', 'paid_amount' => 90_000])->save();

    $d = bdAll();
    expect($d['expenses']['total'])->toBe(20_000)                                          // only the 200.00 that really stands
        ->and($d['expenses']['pending']['amount'])->toBe(90_000)->and($d['status']['expenses'])->toBe('provisional')
        ->and($real->amount)->toBe(20_000);
});

it('never turns unclassified receipts or client money into earned revenue', function () {
    $co = SellbeesCompany::boot($this);
    bdReceipt($co, 1_000_000, '2026-02-10', 'service');                // applied service fee: verified revenue
    bdReceipt($co, 5_000_000, '2026-02-11', 'product_sourcing');       // applied client money
    bdReceipt($co, 300_000, '2026-02-12');                             // unclassified, no invoice text: unknown nature
    $d = bdAll();

    expect($d['revenue']['verified'])->toBe(1_000_000)->and($d['receipts']['sourcing_receipts']['amount'])->toBe(5_000_000)->and($d['receipts']['unknown']['amount'])->toBe(300_000)
        ->and($d['status']['revenue'])->toBe('incomplete')->and($d['net_profit']['status'])->toBe('incomplete')
        ->and(collect($d['unresolved'])->pluck('label')->implode('|'))->toContain('Receipts of unknown nature');
});

it('keeps revenue by receipt date and expenses by expense date across periods', function () {
    $co = SellbeesCompany::boot($this);
    bdReceipt($co, 400_000, '2026-02-10', 'service');
    bdReceipt($co, 700_000, '2026-03-10', 'service');
    $co->expense('Office', 'Feb cost', '100', '2026-02-20');
    $co->expense('Office', 'Mar cost', '300', '2026-03-20');

    $feb = bdAll('2026-02-01', '2026-02-28');
    $mar = bdAll('2026-03-01', '2026-03-31');
    $all = bdAll();
    expect($feb['revenue']['verified'])->toBe(400_000)->and($feb['expenses']['total'])->toBe(10_000)->and($feb['net_profit']['amount'])->toBe(390_000)
        ->and($mar['revenue']['verified'])->toBe(700_000)->and($mar['expenses']['total'])->toBe(30_000)->and($mar['net_profit']['amount'])->toBe(670_000)
        ->and($all['net_profit']['amount'])->toBe($feb['net_profit']['amount'] + $mar['net_profit']['amount']);
});

it('recognises sourcing margin only on settled lots with a stated owner, from the agreed amount and real costs', function () {
    $s = integrationStock($this);
    $lot = SourcingLot::findOrFail($s['lot']->id);
    $row = collect(ProjectSourcingReport::build()['lots'])->first();
    $agreed = (int) collect($row['buckets'])->except('unallocated')->sum('agreed');
    $cost = (int) collect($row['buckets'])->except('unallocated')->sum('cost');
    expect($agreed)->toBeGreaterThan(0);

    $open = bdAll();                                                                       // not settled: an estimate, never profit
    expect($open['sourcing']['recognised'])->toBe(0)->and($open['sourcing']['potential_total'])->not->toBe(0)->and($open['sourcing']['estimate_total'])->toBe($agreed - $cost)->and($open['status']['sourcing'])->toBe('provisional');

    $lot->update(['ownership' => null, 'status' => 'settled', 'settled_at' => '2026-03-05']);   // settled but nobody knows whose it is
    expect(bdAll()['sourcing']['recognised'])->toBe(0)->and(bdAll()['sourcing']['unknown_owner'])->toContain($lot->number);

    $lot->update(['ownership' => 'client']);
    $settled = bdAll();
    expect($settled['sourcing']['recognised'])->toBe($agreed - $cost)->and($settled['revenue']['verified'])->toBe($agreed - $cost)
        ->and(bdAll('2026-04-01', '2026-04-30')['sourcing']['recognised'])->toBe(0)               // outside the settlement month
        ->and(bdAll('2026-03-01', '2026-03-31')['sourcing']['recognised'])->toBe($agreed - $cost);
});

it('keeps transfers between accounts out of profit and labels unverified cash', function () {
    $co = SellbeesCompany::boot($this);
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $co->invest('A', '1000', '2026-02-01');
    $before = bdAll();
    AccountingService::transfer(['from' => $cash->id, 'to' => $bank->id, 'amount' => 30_000, 'date' => '2026-02-05', 'idempotency_key' => (string) Str::uuid()], $co->users['finance']);
    $after = bdAll();

    expect($after['net_profit']['amount'])->toBe($before['net_profit']['amount'])->and($after['cash']['ledger_total'])->toBe($before['cash']['ledger_total'])
        ->and($after['cash']['status'])->toBe('unverified');                               // no verified statement: shown as unverified, not as available cash
});

it('shows the business dashboard only to those allowed to see company profit', function () {
    $co = SellbeesCompany::boot($this);
    $co->invest('A', '1000', '2026-02-01');

    $co->as('finance')->get(route('dashboard'))->assertOk()->assertSee('Overall Business Position')->assertSee('Customer Collections');
    $co->as('board')->get(route('dashboard'))->assertOk()->assertSee('Overall Business Position');
    $co->as('auditor')->get(route('dashboard'))->assertOk()->assertSee('Overall Business Position');
    $co->as('clients')->get(route('dashboard'))->assertOk()->assertDontSee('Overall Business Position');     // client management keeps the ClientHub overview
    $co->as('employee')->get(route('dashboard'))->assertForbidden();
    $co->as('finance')->get(route('clienthub.dashboard'))->assertOk();
});

it('separates a BDT 350 cash balance from a BDT 150 loss (the owner example), with every taka bridged', function () {
    $co = SellbeesCompany::boot($this);
    foreach (['A' => '200', 'B' => '150', 'C' => '150'] as $code => $amount) {
        $co->invest($code, $amount, '2026-02-01');                                           // partner investment 500
    }
    foreach (range(1, 5) as $i) {
        bdReceipt($co, 1_000, '2026-02-03', 'service');                                      // onboarding 5 x 10 = 50
    }
    $projects = [];
    foreach (range(1, 5) as $i) {
        bdReceipt($co, 10_000, '2026-02-04', 'product_sourcing');                            // product collections 5 x 100 = 500
        $projects[] = $co->project($co->client('Buyer '.$i), 'Buy '.$i)->id;
    }
    // One settled, client-owned lot: 5 pieces at 80 (delivery included), each agreed with a client at 100.
    $supplier = DB::table('suppliers')->insertGetId(['name' => 'Supplier', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $lot = DB::table('sourcing_lots')->insertGetId(['number' => 'SL-T01', 'title' => 'Example', 'status' => 'settled', 'allocation_basis' => 'value', 'pricing_method' => 'fixed', 'pricing_value' => 0,
        'ownership' => 'client', 'settled_at' => '2026-02-28 10:00:00', 'created_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);
    $line = DB::table('sourcing_lot_lines')->insertGetId(['sourcing_lot_id' => $lot, 'supplier_id' => $supplier, 'description' => 'Item', 'unit' => 'pcs', 'quantity' => 5, 'usable_quantity' => 5,
        'unit_price' => 8_000, 'received_quantity' => 5, 'arrived_on' => '2026-02-10', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($projects as $projectId) {
        DB::table('sourcing_allocations')->insert(['sourcing_lot_id' => $lot, 'sourcing_lot_line_id' => $line, 'project_id' => $projectId, 'kind' => 'sale', 'description' => 'Item', 'quantity' => 1,
            'unit_price' => 10_000, 'created_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $co->expense('Sourcing: supplier payment', 'Supplier cost including delivery', '400', '2026-02-10');   // supplier cost 400
    $co->expense('Office', 'Operating expense', '300', '2026-02-12');                                     // operating expense 300

    $d = bdAll();
    expect($d['investment']['net'])->toBe(50_000)->and($d['revenue']['service'])->toBe(5_000)->and($d['receipts']['collections']['product_sourcing']['amount'])->toBe(50_000)
        ->and($d['sourcing']['recognised'])->toBe(10_000)                                    // earned margin 100: agreed 500 - cost 400, cost counted once
        ->and($d['revenue']['verified'])->toBe(15_000)                                       // gross earnings before operating expense: 150
        ->and($d['expenses']['total'])->toBe(30_000)->and($d['net_profit']['amount'])->toBe(-15_000)         // net loss 150
        ->and($d['funding']['total_in'])->toBe(105_000)->and($d['funding']['total_out'])->toBe(70_000)->and($d['funding']['remaining'])->toBe(35_000)   // expected cash 350
        ->and($d['funding']['outflows']['sourcing'])->toBe(40_000)->and($d['funding']['proof_difference'])->toBe(0)
        ->and($d['bridge']['total'])->toBe($d['funding']['remaining']);                      // the walk from the loss to the cash is exact
    expect($d['funding']['remaining'])->not->toBe($d['net_profit']['amount']);
});

it('keeps account balances honest: expected from the ledger, actual only from a verified statement, transfers neutral, opening carried', function () {
    $co = SellbeesCompany::boot($this);
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $co->invest('A', '1000', '2026-02-01');                                                   // 1,000.00 into the default account
    AccountingService::transfer(['from' => $cash->id, 'to' => $bank->id, 'amount' => 30_000, 'date' => '2026-02-05', 'idempotency_key' => (string) Str::uuid()], $co->users['finance']);
    Carbon::setTestNow('2026-03-01 10:00:00');
    $statement = StatementReconciliationService::record($bank, ['statement_date' => '2026-02-10', 'statement_balance' => 25_000, 'reference' => 'Bank statement 10 Feb'], $co->users['finance']);
    StatementReconciliationService::decide($statement, true, $co->users['board']);

    $feb = collect(bdAll('2026-02-01', '2026-02-28')['accounts'])->keyBy('name');
    expect($feb['Office Cash']['expected'])->toBe(70_000)->and($feb['Office Cash']['inflows'])->toBe(100_000)->and($feb['Office Cash']['transfers'])->toBe(-30_000)
        ->and($feb['Office Cash']['actual'])->toBeNull()->and($feb['Office Cash']['state'])->toContain('not verified')           // no statement: never an estimated "actual"
        ->and($feb['City Bank']['expected'])->toBe(30_000)->and($feb['City Bank']['transfers'])->toBe(30_000)
        ->and($feb['City Bank']['actual'])->toBe(25_000)->and($feb['City Bank']['variance'])->toBe(-5_000)->and($feb['City Bank']['unexplained'])->toBe(5_000);
    $d = bdAll('2026-02-01', '2026-02-28');
    expect($d['funding']['total_in'])->toBe(100_000)->and($d['funding']['total_out'])->toBe(0)                              // the transfer is neither inflow nor outflow
        ->and(collect($d['accounts'])->sum('expected'))->toBe(100_000)->and($d['cash']['status'])->toBe('unverified');

    $mar = collect(bdAll('2026-03-01', '2026-03-31')['accounts'])->keyBy('name');                                          // March opens with February's closing
    expect($mar['Office Cash']['opening'])->toBe(70_000)->and($mar['Office Cash']['expected'])->toBe(70_000)->and($mar['City Bank']['opening'])->toBe(30_000);
});

it('classifies loans, later expense payments, supplier refunds and loan repayments correctly and counts nothing twice', function () {
    $co = SellbeesCompany::boot($this);
    $co->invest('A', '1000', '2026-02-01');
    $loan = $co->expense('Loan', 'Loan lent', '300', '2026-02-02');
    DB::table('loan_receivables')->insert(['borrower_name' => 'X', 'expense_id' => $loan->id, 'principal' => 30_000, 'lent_on' => '2026-02-02', 'status' => 'verified', 'created_by' => $co->users['finance']->id, 'created_at' => now(), 'updated_at' => now()]);
    Ledger::post('2026-02-20', 'Loan repaid', 'loan_receivable_repayment', 1, [['cash', null, 5_000, 0], ['assets.loans_receivable', null, 0, 5_000]], null);
    $supplier = $co->expense('Sourcing: supplier payment', 'Advance to a supplier', '400', '2026-02-03');
    $refund = (new Expense)->forceFill(['category' => 'Sourcing: supplier payment', 'description' => 'Supplier refund', 'amount' => 10_000, 'spent_on' => '2026-02-09', 'status' => 'verified',
        'created_by' => $co->users['finance']->id, 'reverses_id' => $supplier->id, 'correction_type' => 'refund_received', 'payment_status' => 'paid', 'paid_amount' => 10_000]);
    $refund->save();
    Ledger::post('2026-02-09', 'Supplier refund', 'expense', $refund->id, [['cash', null, 10_000, 0], ['expense', null, 0, 10_000]], null);
    // An unpaid operating cost recorded earlier and paid later: the cost is counted once (at its expense date) and its cash once (when paid).
    $unpaid = (new Expense)->forceFill(['category' => 'Office', 'description' => 'Bill paid later', 'amount' => 20_000, 'spent_on' => '2026-02-04', 'status' => 'verified', 'created_by' => $co->users['finance']->id,
        'payment_status' => 'unpaid', 'paid_amount' => 0]);
    $unpaid->save();
    $payment = (new ExpensePayment)->forceFill(['expense_id' => $unpaid->id, 'amount' => 20_000, 'paid_on' => '2026-02-15', 'idempotency_key' => (string) Str::uuid(), 'created_by' => $co->users['finance']->id]);
    $payment->save();
    Ledger::post('2026-02-15', 'Paid bill', 'expense_payment', $payment->id, [['liabilities', null, 20_000, 0], ['cash', null, 0, 20_000]], null);

    $d = bdAll();
    $f = $d['funding'];
    expect($f['outflows']['loans'])->toBe(30_000)->and($f['outflows']['sourcing'])->toBe(30_000)                       // 400 paid, 100 refunded
        ->and($f['outflows']['operating'])->toBe(20_000)->and($f['outflows']['other'])->toBe(0)->and($f['other_inflows'])->toBe(5_000)  // repayment is an inflow, not income
        ->and($d['expenses']['total'])->toBe(20_000)                                          // loans, supplier money and the refund are not office costs
        ->and($d['net_profit']['amount'])->toBe(-20_000)->and($f['remaining'])->toBe(100_000 + 5_000 - 80_000)
        ->and($f['proof_difference'])->toBe(0)->and($d['bridge']['total'])->toBe($f['remaining']);
});
