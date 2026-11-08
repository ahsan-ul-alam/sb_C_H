<?php

namespace App\Http\Controllers;

use App\Models\AccountStatement;
use App\Models\FinancialAccount;
use App\Models\StatementReconcilingItem;
use App\Services\AccountingService;
use App\Services\IntegrityGate;
use App\Services\StatementReconciliationService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StatementController extends Controller
{
    private const SIGNED_AMOUNT = '/^-?\d{1,13}(\.\d{1,2})?$/';

    public function index(): View
    {
        $accounts = FinancialAccount::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
        $latest = StatementReconciliationService::latestVerified()->keyBy('financial_account_id');

        return view('finance.statements', [
            'rows' => $accounts->map(fn ($a) => [
                'account' => $a, 'ledger' => AccountingService::balance($a->id), 'statement' => $latest[$a->id] ?? null,
                'position' => isset($latest[$a->id]) ? StatementReconciliationService::position($latest[$a->id]) : null,
            ]),
            'pending' => AccountStatement::with(['account', 'items'])->where('status', 'pending')->latest('id')->get(),
            'kinds' => StatementReconcilingItem::KINDS, 'blockers' => IntegrityGate::blockers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'financial_account_id' => ['required', 'exists:financial_accounts,id'], 'statement_date' => ['required', 'date', 'before_or_equal:today'],
            'statement_balance' => ['required', 'regex:'.self::SIGNED_AMOUNT], 'reference' => ['required', 'string', 'max:191'], 'notes' => ['nullable', 'string', 'max:191'],
            'evidence' => ['nullable', 'in:statement,owner_confirmed'], 'cutoff_time' => ['nullable', 'date_format:H:i'],
            'items' => ['nullable', 'array', 'max:10'], 'items.*.kind' => ['nullable', 'string'], 'items.*.amount' => ['nullable', 'regex:'.self::SIGNED_AMOUNT], 'items.*.description' => ['nullable', 'string', 'max:191'],
        ]);
        $items = collect($data['items'] ?? [])->filter(fn ($i) => trim((string) ($i['amount'] ?? '')) !== '')
            ->map(fn ($i) => ['kind' => (string) ($i['kind'] ?? ''), 'amount' => $this->signed($i['amount']), 'description' => (string) ($i['description'] ?? '')])->values()->all();

        return $this->attempt(fn () => StatementReconciliationService::record(FinancialAccount::findOrFail($data['financial_account_id']), [
            'statement_date' => $data['statement_date'], 'statement_balance' => $this->signed($data['statement_balance']),
            'reference' => $data['reference'], 'notes' => $data['notes'] ?? null, 'items' => $items,
            'evidence' => $data['evidence'] ?? 'statement', 'cutoff_time' => $data['cutoff_time'] ?? null,
        ], $request->user()), 'Recorded; a second person must verify it against the real statement.');
    }

    public function decide(Request $request, AccountStatement $statement): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(fn () => StatementReconciliationService::decide($statement, $approve, $request->user()), $approve ? 'Statement verified.' : 'Statement rejected.');
    }

    private function signed(string $value): int
    {
        $minor = Money::toMinor(ltrim(trim($value), '-'));

        return str_starts_with(trim($value), '-') ? -$minor : $minor;
    }
}
