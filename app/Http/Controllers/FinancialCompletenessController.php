<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Services\Audit;
use App\Services\FinancialCompleteness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class FinancialCompletenessController extends Controller
{
    public function index(): View
    {
        return view('finance.completeness', [
            'report' => FinancialCompleteness::report(), 'receipts' => FinancialCompleteness::receipts(),
            'natures' => ReceiptClassification::NATURES, 'enforcedFrom' => FinancialCompleteness::enforcedFrom(),
        ]);
    }

    /** Say what a receipt is for. Changes a label only (never an amount); a receipt billed from a sourcing lot cannot be relabelled. */
    public function classify(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['nature' => ['required', 'in:'.implode(',', array_keys(ReceiptClassification::NATURES))], 'notes' => ['nullable', 'string', 'max:191']]);

        return $this->attempt(function () use ($request, $payment, $data) {
            $row = FinancialCompleteness::receipts()->firstWhere(fn ($r) => $r['payment']->id === $payment->id);
            if ($row && $row['source'] === 'sourcing billing') {
                throw new RuntimeException('This receipt was billed from a sourcing lot, so it is product sourcing by record and cannot be relabelled.');
            }
            ReceiptClassification::updateOrCreate(['payment_id' => $payment->id], ['nature' => $data['nature'], 'notes' => $data['notes'] ?? null, 'classified_by' => $request->user()->id]);
            Audit::log('receipt.classified', 'payment '.$payment->id, $data['nature']);
        }, 'Receipt classified.');
    }
}
