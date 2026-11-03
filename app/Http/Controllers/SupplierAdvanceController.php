<?php

namespace App\Http\Controllers;

use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\SourcingLot;
use App\Services\AdvanceSettlementService;
use App\Services\CostRecognitionService;
use App\Services\SourcingBasis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierAdvanceController extends Controller
{
    public function index(): View
    {
        $lots = SourcingLot::where('status', '!=', 'cancelled')->where(fn ($q) => $q->whereHas('advanceSettlements')->orWhereHas('costRecognitions')
            ->orWhereHas('payments', fn ($p) => $p->whereHas('expense', fn ($e) => $e->where('classification', 'supplier_advance'))))->orderBy('id')->get();

        return view('sourcing.advances', [
            'rows' => $lots->map(fn (SourcingLot $lot) => [
                'lot' => $lot,
                'suppliers' => $lot->lines()->with('supplier')->get()->groupBy('supplier_id')->map(function ($lines, $supplierId) use ($lot) {
                    $advances = AdvanceSettlementService::advancePayments($lot, (int) $supplierId);

                    return [
                        'supplier' => $lines->first()->supplier, 'paid' => (int) $advances->sum(fn ($p) => $p->effectiveAmount()), 'held' => AdvanceSettlementService::unsettled($lot, (int) $supplierId),
                        'accepted' => AdvanceSettlementService::acceptedValue($lot, (int) $supplierId),
                        'settled' => (int) AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('supplier_id', $supplierId)->where('status', 'verified')->sum('amount'),
                        'purchase' => (int) $lines->sum(fn ($l) => $l->purchaseCost()),
                    ];
                }),
                'settlements' => $lot->advanceSettlements()->with(['supplier'])->latest('id')->get(), 'recognitions' => $lot->costRecognitions()->latest('id')->get(),
                'held' => CostRecognitionService::held($lot), 'unreleased' => CostRecognitionService::unreleased($lot), 'obstacles' => CostRecognitionService::obstacles($lot),
            ]),
            'basis' => config('finance.sourcing_basis'), 'from' => SourcingBasis::accrualFrom(), 'trigger' => SourcingBasis::recognitionTrigger(),
        ]);
    }

    public function propose(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'exists:suppliers,id']]);

        return $this->attempt(function () use ($request, $lot, $data) {
            if (AdvanceSettlementService::propose($lot, (int) $data['supplier_id'], $request->user())->isEmpty()) {
                session()->flash('warning', 'Nothing new to settle: everything accepted so far is already settled or awaiting verification.');
            }
        }, 'Settlement proposed; a Board member must verify it.');
    }

    public function decide(Request $request, AdvanceSettlement $settlement): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(fn () => AdvanceSettlementService::decide($settlement, $approve, $request->user()), $approve ? 'Verified: the advance is now product acquisition cost (no cash moved).' : 'Rejected.');
    }

    public function reverse(Request $request, AdvanceSettlement $settlement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => AdvanceSettlementService::reverse($settlement, $data['reason'], $request->user()), 'Settlement reversed.');
    }

    public function proposeRecognition(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['recognised_on' => ['required', 'date', 'before_or_equal:today']]);

        return $this->attempt(fn () => CostRecognitionService::propose($lot, $data['recognised_on'], $request->user()), 'Release proposed; a Board member must verify it.');
    }

    public function decideRecognition(Request $request, CostRecognition $recognition): RedirectResponse
    {
        $approve = $request->input('decision') === 'verify';

        return $this->attempt(fn () => CostRecognitionService::decide($recognition, $approve, $request->user()), $approve ? 'Verified: the lot\'s cost now reaches profit (no cash moved).' : 'Rejected.');
    }

    public function reverseRecognition(Request $request, CostRecognition $recognition): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => CostRecognitionService::reverse($recognition, $data['reason'], $request->user()), 'Release reversed.');
    }
}
