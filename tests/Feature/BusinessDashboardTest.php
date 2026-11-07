<?php

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\SourcingLot;
use App\Services\AccountingService;
use App\Services\BusinessDashboard;
use App\Services\Ledger;
use App\Services\ProjectSourcingReport;
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
    $co->as('finance')->get(route('dashboard'))->assertOk()->assertSee('Where the money came from')->assertSee('Provisional cash remaining')->assertSee('Provisional position, not a final profit or loss');
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

    $co->as('finance')->get(route('dashboard'))->assertOk()->assertSee('Overall business profit / loss')->assertSee('Total investment');
    $co->as('board')->get(route('dashboard'))->assertOk()->assertSee('Overall business profit / loss');
    $co->as('auditor')->get(route('dashboard'))->assertOk()->assertSee('Overall business profit / loss');
    $co->as('clients')->get(route('dashboard'))->assertOk()->assertDontSee('Overall business profit / loss');     // client management keeps the ClientHub overview
    $co->as('employee')->get(route('dashboard'))->assertForbidden();
    $co->as('finance')->get(route('clienthub.dashboard'))->assertOk();
});
