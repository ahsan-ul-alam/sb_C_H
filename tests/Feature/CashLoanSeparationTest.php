<?php

use App\Models\FinancialAccount;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Services\BusinessDashboard;
use App\Services\CashReconciliation;
use App\Services\Ledger;
use App\Services\LoanRecoveryAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
