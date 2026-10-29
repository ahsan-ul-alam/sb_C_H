<?php

namespace App\Http\Controllers;

use App\Models\CapitalEntry;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\User;
use App\Services\Audit;
use App\Services\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Minimal partner management. Ratios are never edited here: they live only in signed policy versions.
 */
class PartnerController extends Controller
{
    public const MAX_ACTIVE = 3;

    public function show(Partner $partner): View
    {
        return view('finance.partner', [
            'partner' => $partner,
            'linkedUser' => User::where('partner_id', $partner->id)->first(),
            'capital' => Ledger::creditBalance('partner.capital', $partner->id),
            'payable' => Ledger::creditBalance('partner.payable', $partner->id),
            'entries' => CapitalEntry::where('partner_id', $partner->id)->latest('paid_on')->get(),
            'payouts' => Payout::where('partner_id', $partner->id)->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha', 'max:4', 'unique:partners,code'],
            'name' => ['required', 'string', 'max:120', 'unique:partners,name'],
            'joined_on' => ['nullable', 'date'],
        ]);

        if (Partner::where('status', 'active')->count() >= self::MAX_ACTIVE) {
            return back()->with('error', 'The current policy has exactly '.self::MAX_ACTIVE.' active founders. A new founder needs a new signed policy first.')->withInput();
        }

        $partner = Partner::create([...$data, 'code' => strtoupper($data['code']), 'sort' => (int) Partner::max('sort') + 1]);
        Audit::log('partner.created', $partner->code);

        return back()->with('status', 'Partner added. Add them to a new policy version before any allocation.');
    }

    public function edit(Partner $partner): View
    {
        return view('finance.partner-edit', ['partner' => $partner, 'linkedUser' => User::where('partner_id', $partner->id)->first()]);
    }

    /**
     * Profile details only (name, joining date). Ratios, status, capital and payouts are changed only through
     * policies, the exit workflow and the verified ledgers.
     */
    public function update(Request $request, Partner $partner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('partners', 'name')->ignore($partner->id)],
            'joined_on' => ['nullable', 'date'],
        ]);
        $before = $partner->only(['name', 'joined_on']);
        $partner->update($data);
        Audit::log('partner.updated', $partner->code, 'name: '.$before['name'].' -> '.$partner->name.'; joined: '
            .($before['joined_on']?->toDateString() ?? '-').' -> '.($partner->joined_on?->toDateString() ?? '-'));

        return redirect()->route('finance.partners.show', $partner)->with('status', 'Partner '.$partner->code.' updated.');
    }
}
