<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\LoanReceivable;
use App\Models\LoanReceivableRepayment;
use App\Models\LoanRecoveryAllocation;
use App\Models\Partner;
use App\Services\LoanReceivableService;
use App\Services\LoanRecoveryAllocationService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class LoanReceivableController extends Controller
{
    public function index(): View
    {
        $linked = LoanReceivable::whereIn('status', ['pending', 'verified'])->pluck('expense_id');

        return view('finance.loans-receivable', [
            'loans' => LoanReceivable::with(['repayments.account', 'partner', 'expense'])->latest('id')->get(),
            'candidates' => Expense::where('status', 'verified')->whereNull('reverses_id')->where('payment_status', 'paid')->where('is_asset', false)
                ->whereIn('classification', ['operating', 'other'])->whereNotIn('id', $linked)->latest('spent_on')->limit(200)->get(),
            'partners' => Partner::orderBy('sort')->get(), 'documents' => Document::latest('id')->limit(100)->get(),
            'allocations' => LoanRecoveryAllocation::with(['partner', 'creator', 'document'])->latest('id')->get()->groupBy('loan_receivable_repayment_id'),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'expense_id' => ['required', 'exists:expenses,id'], 'borrower_partner_id' => ['nullable', 'exists:partners,id'],
            'borrower_name' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(fn () => LoanReceivableService::propose(Expense::findOrFail($data['expense_id']), $data, $request->user()), 'Proposed; awaiting verification by a Board member.');
    }

    public function decide(Request $request, LoanReceivable $loan): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(fn () => LoanReceivableService::decide($loan, $approve, $request->user(), $request->input('reason')), $approve ? 'Verified: the expense is now a loan receivable.' : 'Rejected.');
    }

    public function reverse(Request $request, LoanReceivable $loan): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => LoanReceivableService::reverse($loan, $data['reason'], $request->user()), 'Reclassification reversed.');
    }

    public function repay(Request $request, LoanReceivable $loan): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'repaid_on' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120'],
            'financial_account_id' => ['nullable', 'exists:financial_accounts,id'], 'idempotency_key' => ['nullable', 'uuid'],
        ]);

        return $this->attempt(function () use ($request, $loan, $data) {
            if (! LoanReceivableService::recordRepayment($loan, [...$data, 'amount' => Money::toMinor($data['amount'])], $request->user())) {
                throw new RuntimeException('This repayment was already recorded.');
            }
        }, 'Recorded; awaiting verification by a Board member.');
    }

    public function allocate(Request $request, LoanReceivableRepayment $repayment): RedirectResponse
    {
        $d = $request->validate(['partner_id' => ['required', 'exists:partners,id'], 'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'reason' => ['required', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:120'], 'document_id' => ['nullable', 'exists:documents,id'], 'idempotency_key' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => LoanRecoveryAllocationService::propose($repayment, Partner::findOrFail($d['partner_id']), Money::toMinor($d['amount']), $d['reason'], $d['reference'] ?? null, $d['document_id'] ?? null, $request->user(), $d['idempotency_key'] ?? null),
            'Allocation proposed for tracking only: no cash, capital or income is created. A Board member must verify it.');
    }

    public function decideAllocation(Request $request, LoanRecoveryAllocation $allocation): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(fn () => LoanRecoveryAllocationService::decide($allocation, $approve, $request->user(), $request->input('reason')), $approve ? 'Verified as a tracking allocation. Nothing was posted.' : 'Rejected.');
    }

    public function reverseAllocation(Request $request, LoanRecoveryAllocation $allocation): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => LoanRecoveryAllocationService::reverse($allocation, $d['reason'], $request->user()), 'Allocation reversed; the amount is free again.');
    }

    public function allocationAsCapital(LoanRecoveryAllocation $allocation): RedirectResponse
    {
        return $this->attempt(fn () => LoanRecoveryAllocationService::refuseCapitalConversion($allocation), '');
    }

    public function decideRepayment(Request $request, LoanReceivableRepayment $repayment): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(fn () => LoanReceivableService::decideRepayment($repayment, $approve, $request->user()), $approve ? 'Verified and posted.' : 'Rejected.');
    }

    public function reverseRepayment(Request $request, LoanReceivableRepayment $repayment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => LoanReceivableService::reverseRepayment($repayment, $data['reason'], $request->user()), 'Repayment reversed.');
    }
}
