<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Document;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use App\Models\Project;
use App\Services\Audit;
use App\Services\DuplicateSubmission;
use App\Services\ExpenseService;
use App\Services\ExpenseVerifier;
use App\Services\SourcingPayments;
use App\Services\SubmissionGuard;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class ExpenseController extends Controller
{
    public function index(): View
    {
        return view('finance.expenses', [
            'expenses' => Expense::with(['project', 'account', 'payments.account'])->latest('spent_on')->latest('id')->paginate(50),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
            'projects' => Project::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'documents' => Document::latest('id')->limit(100)->get(),
            'payable' => (int) Expense::where('status', 'verified')->whereNull('reverses_id')->whereIn('payment_status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'spent_on' => ['required', 'date'],
            'classification' => ['nullable', 'in:'.implode(',', array_keys(ExpenseService::CLASSIFICATIONS))],
            'payment_status' => ['nullable', 'in:paid,unpaid'],
            'paid_on' => ['nullable', 'date'],
            'financial_account_id' => ['nullable', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'project_id' => ['nullable', 'exists:projects,id'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'document_id' => ['nullable', 'exists:documents,id'],
            'prepaid_months' => ['nullable', 'integer', 'between:1,60', 'required_if:classification,prepaid'],
            'submission_key' => ['required', 'uuid'],
        ]);
        $amount = Money::toMinor($data['amount']);
        if ($amount <= 0) {
            return back()->with('error', 'Amount must be positive.')->withInput();
        }
        $isAsset = $request->boolean('is_asset') || ($data['classification'] ?? null) === 'asset';
        $data['classification'] = $isAsset ? 'asset' : ($data['classification'] ?? 'operating');
        if ($data['classification'] === 'direct' && empty($data['project_id'])) {
            return back()->with('error', 'A direct project cost must name its project.')->withInput();
        }
        if (AccountingPeriod::where('month', substr($data['spent_on'], 0, 7))->exists()) {
            return back()->with('error', 'That month is already closed; record a correction in an open month.')->withInput();
        }
        $existing = Expense::where('category', $data['category'])->where('description', $data['description'])->where('amount', $amount)->whereDate('spent_on', $data['spent_on'])
            ->where('status', '!=', 'rejected')->whereNull('reverses_id')->first();
        if ($existing) {
            return back()->with('error', 'An identical expense (#'.$existing->id.': same category, description, amount and date) already exists. If this is a genuine second payment, change the description.')->withInput();
        }
        $unpaid = ($data['payment_status'] ?? 'paid') === 'unpaid';
        $data['payment_status'] = $unpaid ? 'unpaid' : 'paid';
        if ($unpaid) {
            unset($data['paid_on']);
        }
        $submissionKey = $data['submission_key'] ?? null;
        unset($data['submission_key']);
        try {
            $expense = SubmissionGuard::once($submissionKey, 'expense.record', $request->user()->id, [...$data, 'amount' => $amount, 'is_asset' => $isAsset], function () use ($data, $amount, $isAsset, $request): Expense {
                $expense = ExpenseVerifier::record([...array_filter($data, fn ($v) => $v !== null && $v !== ''), 'amount' => $amount, 'is_asset' => $isAsset], $request->user());
                Audit::log('expense.recorded', 'expense '.$expense->id, (string) $amount);

                return $expense;
            });
        } catch (DuplicateSubmission $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', $expense->status === 'verified'
            ? 'Expense recorded and verified automatically under the Board\'s standing policy.'
            : 'Expense recorded; awaiting Board verification (it is outside any standing policy).');
    }

    public function decide(Request $request, Expense $expense): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(function () use ($request, $expense, $approve) {
            DB::transaction(function () use ($request, $expense, $approve) {
                $expense = Expense::lockForUpdate()->findOrFail($expense->id);
                if ($expense->status !== 'pending') {
                    throw new RuntimeException('Expense already decided.');
                }
                if ($expense->created_by === $request->user()->id) {
                    throw new RuntimeException('You cannot verify an expense you recorded.');
                }
                if (! $approve && trim((string) $request->input('reason')) === '' && $request->boolean('reason_required')) {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $expense->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $request->user()->id, 'verified_at' => now(), 'decision_note' => trim((string) $request->input('reason')) ?: null]);
                if ($approve) {
                    ExpenseVerifier::post($expense, $request->user()->id);
                }
                SourcingPayments::onCorrectionDecided($expense, $approve);
                ExpenseService::onDecided($expense, $approve);
                Audit::log('expense.'.($approve ? 'verified' : 'rejected'), 'expense '.$expense->id);
            });
        }, $approve ? 'Expense verified and posted.' : 'Expense rejected.');
    }

    /** Pay all or part of an unpaid expense. */
    public function pay(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'paid_on' => ['required', 'date'],
            'financial_account_id' => ['nullable', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:200'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        return $this->attempt(function () use ($request, $expense, $data) {
            $payment = ExpenseService::pay($expense, [...$data, 'amount' => Money::toMinor($data['amount'])], $request->user());
            if (! $payment) {
                throw new RuntimeException('This payment was already recorded.');
            }
        }, 'Payment recorded.');
    }

    public function voidPayment(Request $request, ExpensePayment $expensePayment): RedirectResponse
    {
        return $this->attempt(fn () => ExpenseService::voidPayment($expensePayment, $request->user()), 'Payment voided; the payable is restored.');
    }

    /** Request a reversal; the Board verifies it like any correction. */
    public function reverse(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:recording_error,refund_received,credit_note'],
            'reason' => ['required', 'string', 'max:150'],
            // Where each original payment comes back to: expense payment id (0 = an expense paid in one go) => account.
            'refund_accounts' => ['nullable', 'array'],
            'refund_accounts.*' => ['nullable', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
        ]);
        $refundAccounts = collect($data['refund_accounts'] ?? [])->filter(fn ($account) => $account !== null && $account !== '')->mapWithKeys(fn ($account, $key) => [(int) $key => (int) $account])->all();

        return $this->attempt(fn () => ExpenseService::reverse($expense, $data['type'] ?? null, $data['reason'], $request->user(), $refundAccounts), 'Reversal recorded; awaiting Board verification.');
    }

    /** Shared (non-direct) expenses and how each is currently split across projects. */
    public function costAllocation(): View
    {
        return view('finance.cost-allocation', [
            'expenses' => Expense::with('allocations.project')->where('status', 'verified')->whereNull('reverses_id')->whereNotIn('classification', ['direct', 'asset', 'prepaid'])
                ->where('category', 'not like', 'Sourcing:%')->latest('spent_on')->latest('id')->paginate(30),
            'projects' => Project::where('currency', config('finance.currency'))->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Explicit split of a shared expense across projects (reporting only). */
    public function allocate(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate(['allocations' => ['required', 'array'], 'allocations.*' => ['nullable', 'regex:'.Money::AMOUNT_REGEX]]);
        $amounts = collect($data['allocations'])->filter(fn ($v) => trim((string) $v) !== '')->map(fn ($v) => Money::toMinor($v))->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();

        return $this->attempt(fn () => ExpenseService::allocate($expense, $amounts), 'Shared cost split saved.');
    }
}
