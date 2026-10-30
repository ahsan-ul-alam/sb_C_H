<?php

namespace App\Http\Controllers;

use App\Models\EmergencyFundRequest;
use App\Models\SourcingLot;
use App\Services\EmergencyFund;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmergencyFundController extends Controller
{
    public function store(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'reason' => ['required', 'string', 'max:500']]);

        return $this->attempt(fn () => EmergencyFund::request($lot, Money::toMinor($data['amount']), $data['reason'], $request->user()), 'Request submitted for Board approval. Nothing has been reserved or deducted.');
    }

    public function decide(Request $request, EmergencyFundRequest $fundRequest): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:255']]);
        $approve = $data['decision'] === 'approve';

        return $this->attempt(fn () => EmergencyFund::decide($fundRequest, $approve, $data['note'] ?? null, $request->user()), $approve ? 'Approved. Finance can now utilise it.' : 'Request rejected.');
    }

    public function utilise(Request $request, EmergencyFundRequest $fundRequest): RedirectResponse
    {
        return $this->attempt(fn () => EmergencyFund::utilise($fundRequest, $request->user()), 'Coverage utilised and posted to the Emergency Reserve.');
    }

    public function reverse(Request $request, EmergencyFundRequest $fundRequest): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => EmergencyFund::reverse($fundRequest, $data['reason'], $request->user()), 'Coverage reversed.');
    }

    public function cancel(Request $request, EmergencyFundRequest $fundRequest): RedirectResponse
    {
        return $this->attempt(fn () => EmergencyFund::cancel($fundRequest, $request->user()), 'Request cancelled.');
    }
}
