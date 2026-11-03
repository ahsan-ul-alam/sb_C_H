<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\OtherIncome;
use App\Services\OtherIncomeService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class OtherIncomeController extends Controller
{
    public function index(): View
    {
        return view('finance.other-income', [
            'items' => OtherIncome::with('account')->latest('received_on')->latest('id')->paginate(30),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'], 'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'received_on' => ['required', 'date'],
            'financial_account_id' => ['nullable', 'exists:financial_accounts,id'], 'idempotency_key' => ['nullable', 'uuid'],
        ]);

        return $this->attempt(function () use ($request, $data) {
            if (! OtherIncomeService::record([...$data, 'amount' => Money::toMinor($data['amount'])], $request->user())) {
                throw new RuntimeException('This entry was already recorded.');
            }
        }, 'Recorded; awaiting verification by a Board member.');
    }

    public function decide(Request $request, OtherIncome $income): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(fn () => OtherIncomeService::decide($income, $approve, $request->user()), $approve ? 'Verified and posted.' : 'Rejected.');
    }

    public function reverse(Request $request, OtherIncome $income): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => OtherIncomeService::reverse($income, $data['reason'], $request->user()), 'Entry reversed.');
    }
}
