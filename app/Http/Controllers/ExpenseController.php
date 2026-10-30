<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Services\Audit;
use App\Services\ExpenseVerifier;
use App\Services\SourcingPayments;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class ExpenseController extends Controller
{
    public function index(): View
    {
        return view('finance.expenses', ['expenses' => Expense::latest('spent_on')->latest('id')->paginate(50)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'spent_on' => ['required', 'date'],
        ]);
        $amount = Money::toMinor($data['amount']);
        if ($amount <= 0) {
            return back()->with('error', 'Amount must be positive.')->withInput();
        }
        if (AccountingPeriod::where('month', substr($data['spent_on'], 0, 7))->exists()) {
            return back()->with('error', 'That month is already closed; record a correction in an open month.')->withInput();
        }
        if (Expense::where('category', $data['category'])->where('description', $data['description'])->where('amount', $amount)->whereDate('spent_on', $data['spent_on'])
            ->where('status', '!=', 'rejected')->whereNull('reverses_id')->exists()) {
            return back()->with('error', 'An identical expense (same category, description, amount and date) already exists. If this is a genuine second payment, change the description.')->withInput();
        }
        $expense = ExpenseVerifier::record([...$data, 'amount' => $amount, 'is_asset' => $request->boolean('is_asset')], $request->user());
        Audit::log('expense.recorded', 'expense '.$expense->id, (string) $amount);

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
                $expense->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $request->user()->id, 'verified_at' => now()]);
                if ($approve) {
                    ExpenseVerifier::post($expense, $request->user()->id);
                }
                SourcingPayments::onCorrectionDecided($expense, $approve);
                Audit::log('expense.'.($approve ? 'verified' : 'rejected'), 'expense '.$expense->id);
            });
        }, $approve ? 'Expense verified and posted.' : 'Expense rejected.');
    }
}
