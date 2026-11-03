<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\Loan;
use App\Models\LoanEntry;
use App\Services\Audit;
use App\Services\LoanService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class LoanController extends Controller
{
    public function index(): View
    {
        return view('finance.loans', [
            'loans' => Loan::with(['entries.account'])->latest('id')->get(),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['lender' => ['required', 'string', 'max:120'], 'reference' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:255']]);
        $loan = Loan::create([...$data, 'created_by' => $request->user()->id]);
        Audit::log('loan.created', 'loan '.$loan->id, $loan->lender);

        return back()->with('status', 'Loan created. Record the amount received below.');
    }

    public function entry(Request $request, Loan $loan): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:receipt,principal'], 'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'entry_date' => ['required', 'date'],
            'financial_account_id' => ['nullable', 'exists:financial_accounts,id'], 'idempotency_key' => ['nullable', 'uuid'],
        ]);

        return $this->attempt(function () use ($request, $loan, $data) {
            if (! LoanService::record($loan, [...$data, 'amount' => Money::toMinor($data['amount'])], $request->user())) {
                throw new RuntimeException('This entry was already recorded.');
            }
        }, 'Recorded; awaiting verification by a Board member.');
    }

    public function decide(Request $request, LoanEntry $entry): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(fn () => LoanService::decide($entry, $approve, $request->user()), $approve ? 'Verified and posted.' : 'Rejected.');
    }

    public function reverse(Request $request, LoanEntry $entry): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => LoanService::reverse($entry, $data['reason'], $request->user()), 'Entry reversed.');
    }

    public function interest(Request $request, Loan $loan): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'spent_on' => ['required', 'date'], 'financial_account_id' => ['nullable', 'exists:financial_accounts,id']]);

        return $this->attempt(fn () => LoanService::recordInterest($loan, [...$data, 'amount' => Money::toMinor($data['amount'])], $request->user()), 'Interest recorded as an expense; it appears under Expenses for verification.');
    }
}
