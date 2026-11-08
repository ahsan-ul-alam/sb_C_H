<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Services\PaymentVoids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The only way a customer payment leaves the active books: Finance proposes with a reason and evidence, a different Board member decides.
 * The payment is never deleted.
 */
class PaymentVoidController extends Controller
{
    public function index(): View
    {
        return view('finance.payment-voids', [
            'requests' => PaymentVoidRequest::with(['payment.project.client', 'payment.invoice', 'requester', 'decider'])->latest('id')->limit(200)->get(),
            'voided' => Payment::withVoided()->whereNotNull('voided_at')->count(),
        ]);
    }

    public function store(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'evidence' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => PaymentVoids::propose($payment, $request->user(), $data['reason'], $data['evidence']),
            'Void proposed. The payment stays active until a different Board member approves it.');
    }

    public function decide(Request $request, PaymentVoidRequest $voidRequest): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:255']]);
        $approve = $data['decision'] === 'approve';

        return $this->attempt(fn () => PaymentVoids::decide($voidRequest, $request->user(), $approve, $data['note'] ?? null),
            $approve ? 'Void approved. The payment is excluded from every active total; the original record and its history are kept.' : 'Void rejected. The payment stays active.');
    }
}
