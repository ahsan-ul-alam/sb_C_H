<?php

namespace App\Http\Controllers;

use App\Models\AccountTransfer;
use App\Models\FinancialAccount;
use App\Services\AccountingService;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Cash and bank accounts and the transfers between them. Balances come from the journal; this controller never
 * stores or edits one.
 */
class FinancialAccountController extends Controller
{
    public function index(): View
    {
        return view('finance.accounts', [
            'balances' => AccountingService::balances(),
            'total' => AccountingService::totalCash(),
            'accounts' => FinancialAccount::orderBy('name')->get(),
            'transfers' => AccountTransfer::with(['from', 'to'])->latest('transferred_on')->latest('id')->limit(50)->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:financial_accounts,name'],
            'type' => ['required', Rule::in(array_keys(FinancialAccount::TYPES))],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $account = FinancialAccount::create([...$data, 'is_default' => ! FinancialAccount::exists()]);
        Audit::log('account.created', 'account '.$account->id, $account->name);

        return back()->with('status', 'Account created. It starts at zero; move legacy cash into it with a transfer from "Unassigned".');
    }

    public function update(Request $request, FinancialAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('financial_accounts', 'name')->ignore($account->id)],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'allow_negative' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(function () use ($account, $data, $request) {
            $active = $request->boolean('is_active');
            if (! $active && AccountingService::balance($account->id) !== 0) {
                throw new RuntimeException('Only an account with a zero balance can be deactivated. Transfer its money out first.');
            }
            $default = $request->boolean('is_default') && $active;
            if ($default) {
                FinancialAccount::where('id', '!=', $account->id)->update(['is_default' => false]);
            }
            $account->update(['name' => $data['name'], 'is_active' => $active, 'is_default' => $default, 'allow_negative' => $request->boolean('allow_negative')]);
            Audit::log('account.updated', 'account '.$account->id, $account->name);
        }, 'Account updated.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'string'], // an account id, or "unassigned" / empty for the legacy cash
            'to' => ['nullable', 'string'],
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $side = fn (?string $v): ?int => ctype_digit((string) $v) ? (int) $v : null;
        $from = $side($data['from'] ?? null);
        $to = $side($data['to'] ?? null);

        return $this->attempt(function () use ($request, $data, $from, $to) {
            $transfer = AccountingService::transfer([...$data, 'from' => $from, 'to' => $to, 'amount' => Money::toMinor($data['amount'])], $request->user());
            if (! $transfer) {
                throw new RuntimeException('This transfer was already recorded.');
            }
        }, 'Transfer recorded. Total company cash is unchanged.');
    }

    public function voidTransfer(Request $request, AccountTransfer $transfer): RedirectResponse
    {
        return $this->attempt(fn () => AccountingService::voidTransfer($transfer, $request->user()), 'Transfer voided by a reversing entry.');
    }
}
