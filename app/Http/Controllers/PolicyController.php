<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PolicyController extends Controller
{
    public function index(): View
    {
        return view('finance.policies', [
            'policies' => PolicyVersion::orderByDesc('effective_from')->orderByDesc('id')->get(),
            'partners' => Partner::where('status', 'active')->orderBy('sort')->get(),
            'documents' => Document::where('subject_type', 'policy')->get()->groupBy('subject_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $codes = Partner::where('status', 'active')->pluck('code')->all();
        $rules = ['name' => ['required', 'string', 'max:120'], 'effective_from' => ['required', 'date']];
        foreach ($codes as $c) {
            $rules["investment.$c"] = ['required', 'integer', 'min:0', 'max:10000'];
            $rules["profit.$c"] = ['required', 'integer', 'min:0', 'max:10000'];
        }
        $data = $request->validate($rules);

        foreach (['investment', 'profit'] as $key) {
            if (array_sum($data[$key]) !== 10000) {
                return back()->with('error', ucfirst($key).' ratios must add up to 100% (10000 basis points).')->withInput();
            }
        }

        $equal = array_fill_keys($codes, 1);
        $policy = PolicyVersion::create([
            'name' => $data['name'], 'effective_from' => $data['effective_from'],
            'investment_bps' => $data['investment'], 'profit_bps' => $data['profit'],
            'ownership_shares' => $equal, 'loss_shares' => $equal,
            'created_by' => $request->user()->id,
        ]);
        Audit::log('policy.drafted', $policy->name);

        return back()->with('status', 'Draft policy saved. It cannot post anything until the Board signs it.');
    }

    public function approve(Request $request, PolicyVersion $policy): RedirectResponse
    {
        // The effective date is part of the Board decision: it is entered here, not inherited from the draft.
        $data = $request->validate(['signed_reference' => ['required', 'string', 'max:200'], 'effective_from' => ['required', 'date']]);

        return $this->attempt(function () use ($request, $policy, $data) {
            if ($policy->isApproved()) {
                throw new RuntimeException('Already approved.');
            }
            if ($policy->created_by === $request->user()->id) {
                throw new RuntimeException('The author of a policy cannot approve it.');
            }
            $policy->update(['approved_by' => $request->user()->id, 'approved_at' => now(), 'signed_reference' => $data['signed_reference'], 'effective_from' => $data['effective_from']]);
            Audit::log('policy.approved', $policy->name, $data['signed_reference'].' effective '.$data['effective_from']);
        }, 'Policy approved.');
    }

    public function confirmProfit(Request $request, PolicyVersion $policy): RedirectResponse
    {
        $data = $request->validate(['profit_signed_reference' => ['required', 'string', 'max:200']]);

        return $this->attempt(function () use ($request, $policy, $data) {
            if (! $policy->isApproved()) {
                throw new RuntimeException('Approve the policy first.');
            }
            if ($policy->isProfitConfirmed()) {
                throw new RuntimeException('Already confirmed.');
            }
            $policy->update(['profit_confirmed_by' => $request->user()->id, 'profit_confirmed_at' => now(), 'profit_signed_reference' => $data['profit_signed_reference']]);
            Audit::log('policy.profit_confirmed', $policy->name, $data['profit_signed_reference']);
        }, 'Profit-fund ratio confirmed.');
    }
}
