<?php

namespace App\Http\Controllers;

use App\Models\CapitalEntry;
use App\Models\FinancialAccount;
use App\Models\OpeningBatch;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Services\Audit;
use App\Services\DuplicateSubmission;
use App\Services\InvestmentService;
use App\Services\Ledger;
use App\Services\SubmissionGuard;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Actual paid capital. The 40/30/30 investment ratio is only a commitment target; money counts
 * only once an entry is recorded by Finance and verified by a different Board user.
 */
class CapitalController extends Controller
{
    public function index(): View
    {
        $partners = Partner::orderBy('sort')->get();
        $policy = PolicyVersion::orderByDesc('effective_from')->orderByDesc('id')->first();
        $paid = $partners->mapWithKeys(fn ($p) => [$p->id => Ledger::creditBalance('partner.capital', $p->id)]);

        return view('finance.capital', [
            'partners' => $partners, 'policy' => $policy, 'paid' => $paid,
            'withdrawn' => $partners->mapWithKeys(fn ($p) => [$p->id => InvestmentService::withdrawnTotal($p->id)]),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
            'entries' => CapitalEntry::with(['partner', 'account'])->latest('id')->limit(100)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'partner_id' => ['required', 'exists:partners,id'],
            'kind' => ['nullable', 'in:contribution,withdrawal'],
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:200'],
            'financial_account_id' => ['nullable', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'method' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string', 'max:255'],
            'submission_key' => ['nullable', 'uuid'],
        ]);
        $data['kind'] ??= 'contribution';
        $amount = Money::toMinor($data['amount']);
        if ($amount <= 0) {
            return back()->with('error', 'Amount must be positive.')->withInput();
        }
        // Capital already included in an opening balance must not be posted again as a new contribution.
        $covered = $data['kind'] === 'contribution' && OpeningBatch::whereIn('status', ['draft', 'approved', 'posted'])->whereDate('as_of', '>=', $data['paid_on'])
            ->whereHas('lines', fn ($q) => $q->where('kind', 'partner_capital')->where('partner_id', $data['partner_id']))->exists();
        if ($covered) {
            return back()->with('error', 'This partner\'s capital up to that date is already in an opening balance batch. Do not record it again.')->withInput();
        }
        // A repeated submit (double click, retry) of the same entry must not create a second postable record.
        $duplicate = CapitalEntry::where('partner_id', $data['partner_id'])->where('kind', $data['kind'])->where('amount', $amount)->whereDate('paid_on', $data['paid_on'])
            ->where('reference', $data['reference'] ?? null)->whereNotIn('status', ['rejected', 'reversed'])->first();
        if ($duplicate) {
            return back()->with('error', 'An identical capital entry (#'.$duplicate->id.': same partner, amount, date and reference) already exists. If this is a genuine second contribution, give it a different reference.')->withInput();
        }
        try {
            $entry = SubmissionGuard::once($data['submission_key'] ?? null, 'capital.record', $request->user()->id, function () use ($data, $amount, $request): CapitalEntry {
                $entry = CapitalEntry::create([...Arr::except($data, 'submission_key'), 'amount' => $amount, 'created_by' => $request->user()->id]);
                Audit::log('capital.recorded', 'capital '.$entry->id, $data['kind'].' '.$amount);

                return $entry;
            });
        } catch (DuplicateSubmission $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', ($data['kind'] === 'withdrawal' ? 'Capital withdrawal' : 'Capital entry').' recorded; awaiting verification.');
    }

    public function reverse(Request $request, CapitalEntry $capital): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => InvestmentService::reverse($capital, $data['reason'], $request->user()), 'Capital entry reversed.');
    }

    public function decide(Request $request, CapitalEntry $capital): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(function () use ($request, $capital, $approve) {
            DB::transaction(function () use ($request, $capital, $approve) {
                $capital = CapitalEntry::lockForUpdate()->findOrFail($capital->id);
                if ($capital->status !== 'pending') {
                    throw new RuntimeException('Entry already decided.');
                }
                if ($capital->created_by === $request->user()->id) {
                    throw new RuntimeException('You cannot verify an entry you recorded.');
                }
                $capital->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $request->user()->id, 'verified_at' => now()]);
                if ($approve) {
                    InvestmentService::post($capital, $request->user()->id);
                }
                Audit::log('capital.'.($approve ? 'verified' : 'rejected'), 'capital '.$capital->id);
            });
        }, $approve ? 'Capital verified and posted.' : 'Capital entry rejected.');
    }
}
