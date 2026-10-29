<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Payment;
use App\Models\RevenueAdjustment;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Customer advances, their recognition as revenue, and refunds. The original ClientHub payment is never changed;
 * each adjustment is verified by a different user and booked in the open month it is dated in.
 */
class RevenueController extends Controller
{
    public function index(): View
    {
        $payments = Payment::with(['project.client', 'invoice'])
            ->whereHas('project', fn ($q) => $q->where('currency', config('finance.currency')))
            ->latest('paid_on')->latest('id')->limit(60)->get();

        return view('finance.revenue', [
            'payments' => $payments,
            'adjustments' => RevenueAdjustment::with('payment.project.client')->latest('id')->limit(100)->get(),
            'balances' => $payments->mapWithKeys(fn ($p) => [$p->id => $this->balances($p)]),
        ]);
    }

    public function store(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:advance,recognition,refund'],
            'against' => ['nullable', 'in:revenue,advance'],
            'amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
            'effective_on' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->attempt(function () use ($request, $payment, $data) {
            DB::transaction(function () use ($request, $payment, $data) {
                $payment = Payment::lockForUpdate()->findOrFail($payment->id);
                if ($payment->project->currency !== config('finance.currency')) {
                    throw new RuntimeException('Only '.config('finance.currency').' payments are handled by the finance ledger.');
                }
                $b = $this->balances($payment);
                $kind = $data['kind'];
                $amount = Money::toMinor($data['amount'] ?? '');
                $date = $kind === 'advance' ? $payment->paid_on->toDateString() : ($data['effective_on'] ?? today()->toDateString());

                if ($kind === 'advance' && $amount === 0) {
                    $amount = $payment->amount;
                }
                if ($amount <= 0) {
                    throw new RuntimeException('Amount must be positive.');
                }
                if (substr($date, 0, 7) > now()->format('Y-m') || $date > today()->toDateString()) {
                    throw new RuntimeException('The date cannot be in the future.');
                }
                if (AccountingPeriod::where('month', substr($date, 0, 7))->exists()) {
                    throw new RuntimeException('That month is closed. Date a correction in the current open month instead.');
                }

                $against = null;
                if ($kind === 'advance') {
                    if ($b['advance'] > 0 || $amount > $payment->amount) {
                        throw new RuntimeException('This payment is already marked as an advance, or the amount exceeds the payment.');
                    }
                } elseif ($kind === 'recognition') {
                    if ($date < $payment->paid_on->toDateString()) {
                        throw new RuntimeException('Recognition cannot be earlier than the payment date.');
                    }
                    if ($amount > $b['advance_outstanding']) {
                        throw new RuntimeException('Only '.Money::format($b['advance_outstanding']).' of this advance is still unearned.');
                    }
                } else {
                    $against = $data['against'] ?? 'revenue';
                    $limit = $against === 'advance' ? $b['advance_outstanding'] : $b['revenue_refundable'];
                    if ($date < $payment->paid_on->toDateString()) {
                        throw new RuntimeException('A refund cannot be dated before the payment.');
                    }
                    if ($amount > $limit) {
                        throw new RuntimeException('Refund exceeds the refundable '.$against.' of '.Money::format($limit).'.');
                    }
                }

                $adjustment = RevenueAdjustment::create(['payment_id' => $payment->id, 'kind' => $kind, 'against' => $against,
                    'amount' => $amount, 'effective_on' => $date, 'reason' => $data['reason'] ?? null, 'created_by' => $request->user()->id]);
                Audit::log('revenue.'.$kind.'_recorded', 'payment '.$payment->id, (string) $amount);
            });
        }, 'Recorded; awaiting verification by a Board member.');
    }

    public function decide(Request $request, RevenueAdjustment $adjustment): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(function () use ($request, $adjustment, $approve) {
            DB::transaction(function () use ($request, $adjustment, $approve) {
                $adjustment = RevenueAdjustment::lockForUpdate()->findOrFail($adjustment->id);
                if ($adjustment->status !== 'pending') {
                    throw new RuntimeException('Already decided.');
                }
                if ($adjustment->created_by === $request->user()->id) {
                    throw new RuntimeException('You cannot verify an adjustment you recorded.');
                }
                if ($approve && AccountingPeriod::where('month', $adjustment->effective_on->format('Y-m'))->exists()) {
                    throw new RuntimeException('That month has been closed meanwhile; reject this and record it again in an open month.');
                }
                $adjustment->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $request->user()->id, 'verified_at' => now()]);
                Audit::log('revenue.'.($approve ? 'verified' : 'rejected'), 'adjustment '.$adjustment->id);
            });
        }, $approve ? 'Adjustment verified; it will be booked when its month closes.' : 'Adjustment rejected.');
    }

    /**
     * Pending and verified adjustments both count, so nothing can be advanced, recognised or refunded twice.
     *
     * @return array{advance: int, advance_outstanding: int, revenue_refundable: int}
     */
    private function balances(Payment $payment): array
    {
        $sum = fn (string $kind, ?string $against = null) => (int) RevenueAdjustment::where('payment_id', $payment->id)->where('kind', $kind)
            ->where('status', '!=', 'rejected')->when($against, fn ($q) => $q->where('against', $against))->sum('amount');

        $advance = $sum('advance');
        $recognised = $sum('recognition');
        $advanceOutstanding = $advance - $recognised - $sum('refund', 'advance');

        return [
            'advance' => $advance,
            'advance_outstanding' => max(0, $advanceOutstanding),
            'revenue_refundable' => max(0, $payment->amount - $advance + $recognised - $sum('refund', 'revenue')),
        ];
    }
}
