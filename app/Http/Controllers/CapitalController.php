<?php

namespace App\Http\Controllers;

use App\Models\CapitalEntry;
use App\Models\OpeningBatch;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Services\Audit;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'entries' => CapitalEntry::with('partner')->latest('id')->limit(100)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'partner_id' => ['required', 'exists:partners,id'],
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:200'],
        ]);
        $amount = Money::toMinor($data['amount']);
        if ($amount <= 0) {
            return back()->with('error', 'Amount must be positive.')->withInput();
        }
        // Capital already included in an opening balance must not be posted again as a new contribution.
        $covered = OpeningBatch::whereIn('status', ['draft', 'approved', 'posted'])->whereDate('as_of', '>=', $data['paid_on'])
            ->whereHas('lines', fn ($q) => $q->where('kind', 'partner_capital')->where('partner_id', $data['partner_id']))->exists();
        if ($covered) {
            return back()->with('error', 'This partner\'s capital up to that date is already in an opening balance batch. Do not record it again.')->withInput();
        }
        $entry = CapitalEntry::create([...$data, 'amount' => $amount, 'created_by' => $request->user()->id]);
        Audit::log('capital.recorded', 'capital '.$entry->id, (string) $amount);

        return back()->with('status', 'Capital entry recorded; awaiting verification.');
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
                    Ledger::post($capital->paid_on->toDateString(), 'Capital paid by partner '.$capital->partner_id, 'capital', $capital->id, [
                        ['cash', null, $capital->amount, 0],
                        ['partner.capital', $capital->partner_id, 0, $capital->amount],
                    ], $request->user()->id);
                }
                Audit::log('capital.'.($approve ? 'verified' : 'rejected'), 'capital '.$capital->id);
            });
        }, $approve ? 'Capital verified and posted.' : 'Capital entry rejected.');
    }
}
