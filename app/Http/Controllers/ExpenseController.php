<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Services\Audit;
use App\Services\Ledger;
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
        $expense = Expense::create([...$data, 'amount' => $amount, 'created_by' => $request->user()->id]);
        Audit::log('expense.recorded', 'expense '.$expense->id, (string) $amount);

        return back()->with('status', 'Expense recorded; awaiting verification.');
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
                    Ledger::post($expense->spent_on->toDateString(), $expense->category.': '.$expense->description, 'expense', $expense->id, [
                        ['expense', null, $expense->amount, 0],
                        ['cash', null, 0, $expense->amount],
                    ], $request->user()->id);
                }
                Audit::log('expense.'.($approve ? 'verified' : 'rejected'), 'expense '.$expense->id);
            });
        }, $approve ? 'Expense verified and posted.' : 'Expense rejected.');
    }
}
