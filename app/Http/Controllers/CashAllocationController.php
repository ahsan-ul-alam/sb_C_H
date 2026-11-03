<?php

namespace App\Http\Controllers;

use App\Models\CashAllocationBatch;
use App\Models\FinancialAccount;
use App\Services\AccountingService;
use App\Services\CashAllocationService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Assigns legacy unassigned cash to financial accounts: prepared by Finance, approved by a different Board user. */
class CashAllocationController extends Controller
{
    public function index(): View
    {
        return view('finance.cash-allocation', [
            'unassigned' => AccountingService::balance(null),
            // What the posted opening-balance batches recorded, shown only as a reference for the person entering real balances.
            'openingCash' => (int) DB::table('opening_lines as l')->join('opening_batches as b', 'b.id', '=', 'l.opening_batch_id')->where('b.status', 'posted')->where('l.kind', 'cash')->sum('l.amount'),
            'openingBank' => (int) DB::table('opening_lines as l')->join('opening_batches as b', 'b.id', '=', 'l.opening_batch_id')->where('b.status', 'posted')->where('l.kind', 'bank')->sum('l.amount'),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
            'batches' => CashAllocationBatch::with(['lines.account', 'preparer'])->latest('id')->limit(20)->get(),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $data = $request->validate(['amounts' => ['required', 'array'], 'amounts.*' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'note' => ['nullable', 'string', 'max:200']]);
        $amounts = collect($data['amounts'])->filter(fn ($v) => trim((string) $v) !== '')->map(fn ($v) => Money::toMinor($v))->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();

        return $this->attempt(fn () => CashAllocationService::prepare($amounts, $data['note'] ?? null, $request->user()), 'Allocation prepared; a Board member must approve it.');
    }

    public function approve(Request $request, CashAllocationBatch $batch): RedirectResponse
    {
        return $this->attempt(fn () => CashAllocationService::approve($batch, $request->user()), 'Allocation approved and posted as transfers. Total cash is unchanged.');
    }

    public function cancel(Request $request, CashAllocationBatch $batch): RedirectResponse
    {
        return $this->attempt(fn () => CashAllocationService::cancel($batch, $request->user()), 'Allocation cancelled.');
    }
}
