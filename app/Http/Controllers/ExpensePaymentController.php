<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Every payment made against a payable expense, including voided ones, with filters. Read-only. */
class ExpensePaymentController extends Controller
{
    public function index(Request $request): View
    {
        $payments = ExpensePayment::with(['expense.project', 'account'])
            ->when($request->integer('account'), fn ($q, $id) => $q->where('financial_account_id', $id))
            ->when($request->integer('expense'), fn ($q, $id) => $q->where('expense_id', $id))
            ->when($request->query('state') === 'voided', fn ($q) => $q->whereNotNull('voided_at'))
            ->when($request->query('state') === 'live', fn ($q) => $q->whereNull('voided_at'))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('paid_on', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('paid_on', '<=', $d))
            ->latest('paid_on')->latest('id')->paginate(40)->withQueryString();

        return view('finance.expense-payments', [
            'payments' => $payments,
            'accounts' => FinancialAccount::orderBy('name')->get(),
            'owed' => (int) Expense::where('status', 'verified')->whereNull('reverses_id')->whereIn('payment_status', ['unpaid', 'partial'])->sum(DB::raw('amount - paid_amount')),
        ]);
    }
}
