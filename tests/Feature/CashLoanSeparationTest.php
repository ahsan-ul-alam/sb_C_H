<?php

use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Services\BusinessDashboard;
use App\Services\CashReconciliation;
use App\Services\Ledger;
use App\Services\LoanRecoveryAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Audit regression: the dashboard's Expected Cash is the money that came in less the money that went out (capital + customer receipts + other cash inflows - outflows),
| and a company loan is a SEPARATE asset. Lending moves cash into the receivable, a verified repayment moves it back exactly once, and an unpaid invoice or an
| investor tracking allocation never touches cash. (The BDT 81,271 seen in the owner's database is this same arithmetic over data that still includes the two
| unvoided payments and no baseline adjustment; see the audit report.)
*/
beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    $this->co = SellbeesCompany::boot($this);
    FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->co->invest('A', '57000', '2026-11-05');
    $this->ahsan = $this->co->expense('Loan', 'Loan for Ahsan', '8000', '2026-10-26');
    $this->salman = $this->co->expense('Loan', 'Salman Loan', '500', '2026-11-05');
});

afterEach(fn () => Carbon::setTestNow());

function cashView(): array
{
    $d = BusinessDashboard::build(null, CarbonImmutable::today());
    $f = $d['funding'];

    return ['available' => $d['fund']['available_cash'], 'receivable' => $d['fund']['loans_receivable'], 'cash_plus_receivable' => $d['fund']['cash_plus_receivables'], 'remaining' => $f['remaining'],
        'in' => $f['total_in'], 'out' => $f['total_out'], 'investment' => $f['investment'], 'collections' => $f['collections'], 'other_in' => $f['other_inflow_parts'], 'outflows' => $f['outflows'],
        'invoices_outstanding' => $d['position']['invoices_outstanding'], 'proof' => $f['proof_difference'], 'ledger_cash' => Ledger::debitBalance('cash'), 'expected_cash' => CashReconciliation::expectedCash('BDT')];
}

it('keeps cash and company loans separate: lending moves cash into the receivable, a verified repayment moves it back once, nothing else changes', function () {
    $client = $this->co->client('Suchona Client');
    $project = $this->co->project($client, 'Suchona Mart');
    $this->co->invoice($project, [['description' => 'Product', 'quantity' => '1', 'unit_price' => '9900']], '2026-11-08', 'INV-9900');   // an unpaid invoice

    foreach ([$this->ahsan, $this->salman] as $expense) {
        $this->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $expense->id, 'borrower_name' => $expense->id === $this->ahsan->id ? 'Ahsan' : 'Salman'])->assertSessionHasNoErrors();
        $this->co->post('board', 'finance.loans-receivable.decide', ['decision' => 'verify'], LoanReceivable::latest('id')->firstOrFail())->assertSessionHasNoErrors();
    }
    $lent = cashView();
    // 57,000 capital - 8,500 lent = 48,500 cash; the 8,500 is a receivable, in the position and never in cash. The unpaid 9,900 invoice is in neither.
    expect($lent['available'])->toBe(4_850_000)->and($lent['receivable'])->toBe(850_000)->and($lent['cash_plus_receivable'])->toBe(5_700_000)->and($lent['invoices_outstanding'])->toBe(990_000)
        ->and($lent['collections'])->toBe(0)->and($lent['remaining'])->toBe($lent['available'])->and($lent['ledger_cash'])->toBe(4_850_000)->and($lent['expected_cash'])->toBe(4_850_000)
        ->and($lent['proof'])->toBe(0)->and($lent['outflows']['loans'])->toBe(850_000);

    foreach (LoanReceivable::orderBy('id')->get() as $loan) {
        $this->co->post('finance', 'finance.loans-receivable.repay', ['amount' => (string) ($loan->principal / 100), 'repaid_on' => '2026-11-09', 'idempotency_key' => (string) Str::uuid()], $loan)->assertSessionHasNoErrors();
        $r = LoanReceivableRepayment::latest('id')->firstOrFail();
        expect(cashView()['available'])->toBe($loan->id === 1 ? 4_850_000 : 4_850_000 + 800_000);                                   // a pending repayment is not cash yet
        $this->co->post('board', 'finance.loans-receivable.repayments.decide', ['decision' => 'verify'], $r)->assertSessionHasNoErrors();
    }
    $repaid = cashView();
    // After both are repaid and verified: cash 57,000, receivable 0, and cash + receivable is unchanged (the money only moved between the two).
    expect($repaid['available'])->toBe(5_700_000)->and($repaid['receivable'])->toBe(0)->and($repaid['cash_plus_receivable'])->toBe($lent['cash_plus_receivable'])
        ->and($repaid['other_in'])->toBe(['loan_receivable_repayment' => 850_000])                                                    // the repayments, counted once
        ->and($repaid['investment'])->toBe(5_700_000)->and($repaid['remaining'])->toBe($repaid['investment'] + $repaid['collections'] + 850_000 - $repaid['out'])
        ->and($repaid['remaining'])->toBe($repaid['available'])->and($repaid['ledger_cash'])->toBe(5_700_000)->and($repaid['expected_cash'])->toBe(5_700_000)->and($repaid['proof'])->toBe(0)
        ->and($repaid['invoices_outstanding'])->toBe(990_000);                                                                       // still not cash

    // An investor tracking allocation of a repayment changes none of it.
    $r = LoanReceivableRepayment::orderBy('id')->firstOrFail();
    $a = LoanRecoveryAllocationService::propose($r, $this->co->partner('C'), (int) $r->amount, 'tracking', null, null, $this->co->users['finance']);
    LoanRecoveryAllocationService::decide($a, true, $this->co->users['board']);
    expect(cashView())->toBe($repaid);
});

it('walks the owner scenario: cash 49,000 with 8,500 lent, a partial, a duplicate and a pending repayment, then 57,500 with nothing owed, profit and capital never moving', function () {
    // The owner's situation: 49,000 actual cash and two loans (8,000 and 500) outstanding. beforeEach put 57,000 in and lent 8,500 out; add 500 more capital so cash is exactly 49,000.
    $this->co->invest('B', '500', '2026-11-06');
    $client = $this->co->client('Unpaid Client');
    $this->co->invoice($this->co->project($client, 'Open Project'), [['description' => 'Product', 'quantity' => '1', 'unit_price' => '9900']], '2026-11-08', 'INV-OPEN');   // BDT 9,900 unpaid
    foreach ([$this->ahsan, $this->salman] as $expense) {
        $this->co->post('finance', 'finance.loans-receivable.store', ['expense_id' => $expense->id, 'borrower_name' => $expense->id === $this->ahsan->id ? 'Ahsan' : 'Salman'])->assertSessionHasNoErrors();
        $this->co->post('board', 'finance.loans-receivable.decide', ['decision' => 'verify'], LoanReceivable::latest('id')->firstOrFail())->assertSessionHasNoErrors();
    }
    $ahsan = LoanReceivable::where('borrower_name', 'Ahsan')->firstOrFail();
    $salman = LoanReceivable::where('borrower_name', 'Salman')->firstOrFail();
    $view = function () {
        $d = BusinessDashboard::build(null, CarbonImmutable::today());

        return ['cash' => $d['fund']['available_cash'], 'receivable' => $d['fund']['loans_receivable'], 'profit' => $d['net_profit']['amount'], 'revenue' => $d['revenue']['verified'], 'expenses' => $d['expenses']['total'],
            'capital' => $d['investment']['contributed'], 'invoices' => $d['position']['invoices_outstanding']];
    };
    $start = $view();
    expect($start['cash'])->toBe(4_900_000)->and($start['receivable'])->toBe(850_000)->and($start['invoices'])->toBe(990_000);          // 49,000 cash, 8,500 owed, 9,900 unpaid and outside cash

    $repay = function ($loan, string $amount, ?string $key = null) {
        return $this->co->post('finance', 'finance.loans-receivable.repay', ['amount' => $amount, 'repaid_on' => '2026-11-09', 'idempotency_key' => $key ?? (string) Str::uuid()], $loan);
    };
    $verify = fn () => $this->co->post('board', 'finance.loans-receivable.repayments.decide', ['decision' => 'verify'], LoanReceivableRepayment::latest('id')->firstOrFail())->assertSessionHasNoErrors();

    $key = (string) Str::uuid();
    $repay($ahsan, '3000', $key)->assertSessionHasNoErrors();                                                                            // partial, pending
    expect($view()['cash'])->toBe(4_900_000);                                                                                            // a pending repayment is not cash
    $repay($ahsan, '3000', $key)->assertSessionHas('error');                                                                             // the same submission twice
    expect(LoanReceivableRepayment::count())->toBe(1);
    $verify();
    expect($view()['cash'])->toBe(5_200_000)->and($view()['receivable'])->toBe(550_000);                                                 // 52,000 and 5,500 still owed
    $repay($ahsan, '5000.01')->assertSessionHas('error');                                                                                // more than what is owed
    $repay($ahsan, '5000')->assertSessionHasNoErrors();
    $verify();
    $repay($salman, '500')->assertSessionHasNoErrors();
    $verify();

    $end = $view();
    expect($end['cash'])->toBe(5_750_000)->and($end['receivable'])->toBe(0)                                                               // 49,000 + 8,000 + 500 = 57,500, nothing owed
        ->and($end['profit'])->toBe($start['profit'])->and($end['revenue'])->toBe($start['revenue'])->and($end['expenses'])->toBe($start['expenses'])->and($end['capital'])->toBe($start['capital'])   // repayments are not profit or capital
        ->and($end['invoices'])->toBe(990_000)                                                                                           // the unpaid invoice is still outside cash
        ->and(JournalEntry::where('source_type', 'loan_receivable_repayment')->count())->toBe(3)                                         // each repayment posted exactly once
        ->and((int) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_entries.source_type', 'loan_receivable_repayment')->where('journal_lines.account', 'cash')->sum('journal_lines.debit'))->toBe(850_000);

    $first = LoanReceivableRepayment::orderBy('id')->firstOrFail();
    $allocation = LoanRecoveryAllocationService::propose($first, $this->co->partner('C'), 300_000, 'tracking', null, null, $this->co->users['finance']);
    LoanRecoveryAllocationService::decide($allocation, true, $this->co->users['board']);
    expect($view())->toBe($end);                                                                                                         // an investor tracking allocation moves nothing
});
