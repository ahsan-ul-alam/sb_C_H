<?php

use App\Models\AccountStatement;
use App\Models\AccountTransfer;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Services\BusinessDashboard;
use App\Services\CashReconciliation;
use App\Services\LoanReceivableService;
use App\Services\StatementReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
    $this->co = SellbeesCompany::boot($this);
});

afterEach(fn () => Carbon::setTestNow());

/** A balance recorded by Finance and verified by a different Board member, exactly as the Account Statements screen does it. */
function crStatement(FinancialAccount $account, int $minor, string $date, string $evidence = 'statement', bool $verify = true, ?string $time = null): AccountStatement
{
    $s = StatementReconciliationService::record($account, ['statement_date' => $date, 'statement_balance' => $minor, 'reference' => $evidence === 'owner_confirmed' ? 'Owner written confirmation' : 'STMT-'.Str::random(4),
        'evidence' => $evidence, 'cutoff_time' => $time], test()->co->users['finance']);
    if ($verify) {
        StatementReconciliationService::decide($s, true, test()->co->users['board']);
    }

    return $s->refresh();
}

function crSnapshot(): array
{
    return CashReconciliation::snapshot();
}

it('shows Not Verified, never an invented zero, without accounts or without a verified statement', function () {
    $none = crSnapshot();
    expect($none['status'])->toBe('not_verified')->and($none['actual'])->toBeNull()->and($none['difference'])->toBeNull()->and($none['reason'])->toContain('No cash, bank or bKash account');

    $account = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    crStatement($account, 0, '2026-11-07', verify: false);   // recorded, but no second person has verified it
    $pending = crSnapshot();
    expect($pending['status'])->toBe('not_verified')->and($pending['actual'])->toBeNull()->and($pending['difference'])->toBeNull();

    $page = $this->co->as('board')->get('/')->assertOk()->getContent();
    expect($page)->toContain('Cash reconciliation')->toContain('Not Verified')->toContain(CashReconciliation::BANGLA);
});

it('signs the difference: positive when actual exceeds expected, negative when it is less, zero when they match', function () {
    $account = FinancialAccount::factory()->asDefault()->create(['name' => 'Bank + bKash']);
    $this->co->invest('A', '1000', '2026-11-05');   // the ERP now expects 1,000.00 in the accounts

    crStatement($account, 120_000, '2026-11-06');
    $plus = crSnapshot();
    expect($plus['expected'])->toBe(100_000)->and($plus['actual'])->toBe(120_000)->and($plus['difference'])->toBe(20_000)->and($plus['sign'])->toBe('+')->and($plus['status'])->toBe('unreconciled');

    crStatement($account, 80_000, '2026-11-07');   // a newer complete cutoff supersedes the older one
    $minus = crSnapshot();
    expect($minus['cutoff'])->toBe('2026-11-07')->and($minus['difference'])->toBe(-20_000)->and($minus['sign'])->toBe('−')->and($minus['direction'])->toContain('less than');

    crStatement($account, 100_000, '2026-11-08');
    $zero = crSnapshot();
    expect($zero['difference'])->toBe(0)->and($zero['sign'])->toBe('')->and($zero['status'])->toBe('reconciled')->and($zero['direction'])->toBe('Balances match');

    $page = $this->co->as('board')->get('/')->assertOk()->getContent();
    expect($page)->toContain('Unreconciled cash difference')->toContain('Reconciled');
});

it('never mixes statements of different cutoff dates and never double counts a transfer between accounts', function () {
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Cash in Hand']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'City Bank']);
    $this->co->invest('A', '1000', '2026-11-05');

    crStatement($cash, 0, '2026-11-06');
    crStatement($bank, 100_000, '2026-11-07');   // a different date: the two cannot be added together
    $mixed = crSnapshot();
    expect($mixed['status'])->toBe('not_verified')->and($mixed['actual'])->toBeNull()->and($mixed['reason'])->toContain('not mixed')->and($mixed['missing'])->toBe(['Cash in Hand']);

    crStatement($bank, 100_000, '2026-11-06');   // now both accounts have a verified statement on 6 Nov
    crStatement($cash, 0, '2026-11-08');           // a later date with only one account does not override the complete cutoff
    $matched = crSnapshot();
    expect($matched['cutoff'])->toBe('2026-11-06')->and($matched['actual'])->toBe(100_000)->and($matched['difference'])->toBe(0)->and($matched['statements'])->toHaveCount(2);

    // Moving money between the accounts changes neither the expected total nor the actual total: it is counted once.
    $before = $matched['expected'];
    $this->co->post('finance', 'finance.transfers.store', ['from' => (string) $cash->id, 'to' => (string) $bank->id, 'amount' => '5', 'date' => '2026-11-06', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(AccountTransfer::count())->toBe(1)->and(CashReconciliation::expectedCash('BDT', '2026-11-06'))->toBe($before)->and(crSnapshot()['actual'])->toBe(100_000);
});

it('keeps owner-confirmed figures distinct from statement-verified ones and stores the cutoff time', function () {
    $account = FinancialAccount::factory()->asDefault()->create(['name' => 'Bank + bKash']);
    $this->co->invest('A', '1000', '2026-11-05');
    $s = crStatement($account, 100_000, '2026-11-08', 'owner_confirmed', time: '11:30');

    $snap = crSnapshot();
    expect($snap['evidence'])->toBe('owner_confirmed')->and($s->evidence)->toBe('owner_confirmed')->and($s->cutoff_at->format('Y-m-d H:i'))->toBe('2026-11-08 11:30')
        ->and($snap['statements'][0]['evidence'])->toBe('owner_confirmed');
    expect($this->co->as('board')->get('/')->getContent())->toContain('owner-confirmed, not statement-verified')->not->toContain('approximately');

    // The same person cannot verify the balance they recorded.
    $own = StatementReconciliationService::record($account, ['statement_date' => '2026-11-07', 'statement_balance' => 1, 'reference' => 'x'], $this->co->users['board']);
    expect(fn () => StatementReconciliationService::decide($own, true, $this->co->users['board']))->toThrow(RuntimeException::class);
});

it('excludes a voided receipt exactly once, moves the difference by exactly that amount and sets the right invoice status', function () {
    $account = FinancialAccount::factory()->asDefault()->create(['name' => 'Bank + bKash']);
    $project = $this->co->project($this->co->client('Suchona Mart'));
    $segment = $this->co->segment($project, 'Product', '300');
    $invoice = $this->co->invoice($project, [['segment_id' => $segment->id, 'description' => 'Goods', 'quantity' => '1', 'unit_price' => '300']], '2026-11-01', 'CR-1');
    $this->co->payInvoice($invoice, '100', '2026-11-02')->assertSessionHasNoErrors();
    $this->co->payInvoice($invoice, '200', '2026-11-03')->assertSessionHasNoErrors();
    expect($invoice->refresh()->status->value)->toBe('paid');
    crStatement($account, 25_000, '2026-11-08');

    $before = crSnapshot();
    expect($before['expected'])->toBe(30_000)->and($before['difference'])->toBe(-5_000);

    $duplicate = Payment::where('amount', 20_000)->sole();
    $this->co->voidPayment($duplicate);
    $after = crSnapshot();

    expect($after['expected'])->toBe(10_000)->and($before['expected'] - $after['expected'])->toBe(20_000)
        ->and($after['difference'] - $before['difference'])->toBe(20_000)
        ->and($invoice->refresh()->amount_paid)->toBe(10_000)->and($invoice->status->value)->toBe('partial')->and($invoice->balance())->toBe(20_000)
        ->and(Payment::withVoided()->count())->toBe(2);

    $this->co->post('finance', 'payments.void-requests.store', ['reason' => 'again', 'evidence' => 'x'], $duplicate->id)->assertNotFound();   // not voided twice
    expect(crSnapshot()['expected'])->toBe(10_000);
});

it('keeps a loan receivable out of cash, expected cash and the difference', function () {
    $account = FinancialAccount::factory()->asDefault()->create(['name' => 'Bank + bKash']);
    $this->co->invest('A', '10000', '2026-11-01');
    $expense = $this->co->expense('Loan', 'Loan for Ahsan', '8000', '2026-11-02');
    crStatement($account, 200_000, '2026-11-08');
    $before = crSnapshot();

    $loan = LoanReceivableService::propose($expense, ['borrower_partner_id' => $this->co->partner('B')->id], $this->co->users['finance']);
    LoanReceivableService::decide($loan, true, $this->co->users['board']);
    $after = crSnapshot();
    $d = BusinessDashboard::build(null, CarbonImmutable::parse('2026-11-08'));

    expect($after['expected'])->toBe($before['expected'])->and($after['difference'])->toBe($before['difference'])->and($after['actual'])->toBe(200_000)
        ->and($d['fund']['loans_receivable'])->toBe(800_000)->and($d['fund']['cash_plus_receivables'])->toBe($d['fund']['available_cash'] + 800_000);
});

it('never posts anything or touches profit when a difference exists', function () {
    $account = FinancialAccount::factory()->asDefault()->create(['name' => 'Bank + bKash']);
    $this->co->invest('A', '1000', '2026-11-05');
    $this->co->expense('Rent', 'Office', '100', '2026-11-06');
    $build = fn () => BusinessDashboard::build(null, CarbonImmutable::parse('2026-11-08'));
    $before = $build();
    $entries = JournalEntry::count();
    $lines = DB::table('journal_lines')->count();

    crStatement($account, 5_000, '2026-11-08');   // far from the ERP's expectation
    $after = $build();

    expect($after['reconciliation']['difference'])->not->toBe(0)
        ->and($after['net_profit']['amount'])->toBe($before['net_profit']['amount'])->and($after['expenses']['total'])->toBe($before['expenses']['total'])
        ->and($after['revenue']['verified'])->toBe($before['revenue']['verified'])->and($after['fund']['total_net_assets'])->toBe($before['fund']['total_net_assets'])
        ->and(JournalEntry::count())->toBe($entries)->and(DB::table('journal_lines')->count())->toBe($lines);
});

/** The production rows from the 2026-11-08 backup that matter for collections and cash (minor units), under their original ids. */
function crLoadBackup(): void
{
    $now = now();
    foreach ([1 => 'Minuddin', 2 => 'Shariful Islam', 3 => 'SK Mart'] as $id => $name) {
        DB::table('clients')->insert(['id' => $id, 'name' => $name, 'mobile' => '0170000000'.$id, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('projects')->insert(['id' => $id, 'client_id' => $id, 'name' => ['Rivo Mart', 'Suchona Mart', 'Sk Mart'][$id - 1], 'status' => 'active', 'currency' => 'BDT', 'started_on' => '2026-10-30', 'created_at' => $now, 'updated_at' => $now]);
    }
    foreach ([[1, 1, 'Onboarding', 1000000], [2, 1, 'Product purchasing', 735000], [3, 1, 'Marketing Charges', 265000], [4, 2, 'Onboarding', 1000000], [5, 2, 'Product Sourcing', 1920000], [6, 3, 'Oboarding', 1000000], [7, 3, 'Product Sourcing', 735000]] as [$id, $p, $n, $a]) {
        DB::table('segments')->insert(['id' => $id, 'project_id' => $p, 'name' => $n, 'amount' => $a, 'created_at' => $now, 'updated_at' => $now]);
    }
    foreach ([[1, 1, 'INV-1', 735000], [2, 1, 'INV-2', 1000000], [3, 1, 'INV-3', 265000], [4, 2, 'INV-OB261', 1000000], [6, 2, 'INV-PR261', 1920000], [7, 3, 'INV-7', 735000], [9, 3, 'INV-9', 265000], [10, 2, 'INV-PR2-L2', 2904000]] as [$id, $p, $n, $t]) {
        DB::table('invoices')->insert(['id' => $id, 'project_id' => $p, 'number' => $n, 'issued_on' => '2026-10-30', 'currency' => 'BDT', 'total' => $t, 'amount_paid' => $t, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now]);
    }
    DB::table('invoice_items')->insert([
        ['invoice_id' => 4, 'segment_id' => 4, 'description' => 'Onboarding', 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000],
        ['invoice_id' => 6, 'segment_id' => null, 'description' => 'Goods', 'quantity' => 1, 'unit_price' => 1920000, 'amount' => 1920000],
        ['invoice_id' => 10, 'segment_id' => null, 'description' => 'Goods and ads', 'quantity' => 1, 'unit_price' => 2904000, 'amount' => 2904000],
    ]);
    foreach ([[4, 1, 1, 735000, '2026-10-30', 'mobile_banking'], [5, 1, 2, 1000000, '2026-10-29', 'mobile_banking'], [6, 1, 3, 265000, '2026-10-30', 'mobile_banking'],
        [7, 2, null, 1000000, '2026-10-30', 'mobile_banking'], [8, 2, 6, 1000000, '2026-10-30', 'mobile_banking'], [9, 2, 6, 920000, '2026-11-01', 'cash'],
        [10, 3, 7, 735000, '2026-11-01', 'cash'], [12, 3, 9, 265000, '2026-11-01', 'cash'], [13, 2, null, 1920000, '2026-11-04', 'cash'], [14, 2, 10, 2904000, '2026-11-07', 'cash']] as [$id, $p, $i, $a, $d, $m]) {
        DB::table('payments')->insert(['id' => $id, 'project_id' => $p, 'invoice_id' => $i, 'amount' => $a, 'paid_on' => $d, 'method' => $m, 'idempotency_key' => (string) Str::uuid(), 'posting_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }
    DB::table('payment_allocations')->insert([['id' => 5, 'payment_id' => 7, 'segment_id' => 4, 'amount' => 1000000], ['id' => 6, 'payment_id' => 13, 'segment_id' => 5, 'amount' => 1920000]]);
    $cash = [1 => 1637000, 2 => 1106700, 3 => 2698000, 4 => -198600, 5 => -800000, 6 => 25000, 7 => -4591700, 8 => -280000, 9 => -290000, 10 => -50000, 11 => -394300, 12 => -50000, 13 => -140000,
        14 => -600000, 15 => -28000, 16 => -50000, 17 => -80000, 18 => -1035000, 21 => 57000, 22 => -2153000, 23 => 1800000, 24 => -50000];
    foreach ($cash as $id => $net) {
        DB::table('journal_entries')->insert(['id' => $id, 'entry_date' => $id === 24 ? '2026-11-08' : '2026-11-01', 'memo' => 'backup '.$id, 'source_type' => 'backup', 'source_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('journal_lines')->insert([
            ['journal_entry_id' => $id, 'account' => 'cash', 'debit' => max($net, 0), 'credit' => max(-$net, 0)],
            ['journal_entry_id' => $id, 'account' => $net > 0 ? 'equity.opening' : 'expense', 'debit' => max(-$net, 0), 'credit' => max($net, 0)],
        ]);
    }
}

it('reproduces the owner scenario from the backup rows: -23,771 before the approved voids and +5,429 after', function () {
    crLoadBackup();
    $cash = FinancialAccount::factory()->asDefault()->create(['name' => 'Cash in Hand']);
    $bank = FinancialAccount::factory()->bank()->create(['name' => 'Bank + bKash']);
    crStatement($cash, 0, '2026-11-08', 'owner_confirmed', time: '12:00');           // owner: cash in hand is 0
    crStatement($bank, 4_900_000, '2026-11-08', 'owner_confirmed', time: '12:00');   // owner: bank + bKash is exactly 49,000

    $before = crSnapshot();
    expect($before['expected'])->toBe(7_277_100)->and($before['actual'])->toBe(4_900_000)->and($before['difference'])->toBe(-2_377_100)->and($before['sign'])->toBe('−')
        ->and($before['evidence'])->toBe('owner_confirmed')->and($before['status'])->toBe('unreconciled');

    foreach ([8, 13] as $id) {   // each void is requested by Finance and approved by a different Board member
        $this->co->post('finance', 'payments.void-requests.store', ['reason' => 'Not received (owner confirmation)', 'evidence' => 'Owner written confirmation of receipts: 10,000 + 9,200 + 29,040'], $id)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->co->post('board', 'finance.payment-voids.decide', ['decision' => 'approve'], PaymentVoidRequest::where('payment_id', $id)->sole())->assertSessionHasNoErrors()->assertSessionMissing('error');
    }
    $after = crSnapshot();
    $invoice6 = Invoice::findOrFail(6);

    expect($after['expected'])->toBe(4_357_100)->and($after['difference'])->toBe(542_900)->and($after['sign'])->toBe('+')
        ->and($after['expected'] - 4_900_000)->toBe(-542_900)
        ->and($invoice6->amount_paid)->toBe(920_000)->and($invoice6->status->value)->toBe('partial')->and($invoice6->balance())->toBe(1_000_000)
        ->and(Invoice::findOrFail(4)->balance())->toBe(0)->and(Invoice::findOrFail(10)->balance())->toBe(0)
        ->and(Payment::count())->toBe(8)->and(Payment::withVoided()->count())->toBe(10);
});
