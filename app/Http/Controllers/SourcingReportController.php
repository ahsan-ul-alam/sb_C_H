<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\EmergencyFundRequest;
use App\Models\SourcingLot;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Services\EmergencyFund;
use App\Services\LotFinancials;
use App\Services\SourcingObligations;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SourcingReportController extends Controller
{
    public function overview(): View
    {
        $lots = SourcingLot::where('status', '!=', 'cancelled')->get();
        $figures = $lots->mapWithKeys(fn ($lot) => [$lot->id => LotFinancials::for($lot)]);
        $sum = fn (string $key) => (int) $figures->sum($key);

        return view('sourcing.overview', [
            'cards' => [
                'activeSuppliers' => Supplier::where('is_active', true)->count(),
                'activeLots' => $lots->reject(fn ($l) => $l->isClosed())->count(),
                'purchase' => $sum('purchase'), 'additional' => $sum('additional'),
                'billing' => $sum('revenue'), 'billed' => $sum('billed'), 'received' => $sum('received'), 'receivable' => $sum('receivable'),
                'expectedProfit' => (int) $figures->sum(fn ($f) => $f['approved_profit'] ?? $f['profit']),
                'actualProfit' => (int) $figures->sum(fn ($f) => max(0, $f['profit'])),
                'actualLoss' => (int) $figures->sum(fn ($f) => max(0, -$f['profit'])),
                'payables' => $sum('supplier_outstanding'), 'costsUnpaid' => $sum('costs_unpaid'),
                'fundRequested' => (int) EmergencyFundRequest::whereIn('status', ['pending', 'approved'])->sum('amount'),
                'fundApproved' => (int) EmergencyFundRequest::whereIn('status', ['approved', 'utilised'])->sum('amount'),
                'fundUtilised' => (int) EmergencyFundRequest::where('status', 'utilised')->sum('amount'),
            ],
            'obligations' => SourcingObligations::outstanding(),
            'pendingExpenses' => SourcingObligations::pendingExpenses(),
            'uncoveredLoss' => (int) $figures->sum('pending_loss_after_fund'),
            'heldCoverage' => SourcingObligations::heldCoverage(),
            'coverageApproved' => SourcingObligations::coverageResolutionApproved(),
            'reserve' => EmergencyFund::balance(),
            'lossLots' => $lots->filter(fn ($l) => $figures[$l->id]['profit'] < 0)->sortBy(fn ($l) => $figures[$l->id]['profit'])->take(8),
            'recent' => $lots->sortByDesc('id')->take(8),
            'figures' => $figures,
        ]);
    }

    public function reports(Request $request): View
    {
        $lots = SourcingLot::query()
            ->where('status', '!=', 'cancelled')
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when(isset(SourcingLot::STATUSES[(string) $request->query('status')]), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->integer('supplier'), fn ($q, $id) => $q->whereHas('lines', fn ($l) => $l->where('supplier_id', $id)))
            ->when($request->integer('product'), fn ($q, $id) => $q->whereHas('lines', fn ($l) => $l->where('supplier_product_id', $id)))
            ->when($request->integer('client'), fn ($q, $id) => $q->whereHas('allocations.project', fn ($p) => $p->where('client_id', $id)))
            ->get();

        $figures = $lots->mapWithKeys(fn ($lot) => [$lot->id => LotFinancials::for($lot)]);
        $result = (string) $request->query('result');
        if ($result === 'profit' || $result === 'loss') {
            $lots = $lots->filter(fn ($l) => $result === 'profit' ? $figures[$l->id]['profit'] >= 0 : $figures[$l->id]['profit'] < 0);
        }

        $lines = $lots->flatMap(fn ($l) => collect($figures[$l->id]['lines'])->map(fn ($f) => $f + ['lot' => $l]));
        $byProduct = $lines->groupBy(fn ($f) => $f['line']->supplier_product_id ? 'p'.$f['line']->supplier_product_id : 'd'.$f['line']->description)
            ->map(fn (Collection $g) => ['name' => $g->first()['line']->description, 'landed' => $g->sum('landed'), 'revenue' => $g->sum('revenue'), 'profit' => $g->sum('profit'), 'lots' => $g->pluck('lot.id')->unique()->count()])
            ->sortByDesc('profit');
        $bySupplier = $lines->groupBy(fn ($f) => $f['line']->supplier_id)
            ->map(fn (Collection $g) => ['name' => $g->first()['line']->supplier->name, 'purchase' => $g->sum('purchase'), 'landed' => $g->sum('landed'), 'revenue' => $g->sum('revenue'), 'profit' => $g->sum('profit')])
            ->sortByDesc('profit');
        $unexpectedByMonth = $lots->flatMap(fn ($l) => $l->costs()->where('is_unexpected', true)->get())
            ->groupBy(fn ($c) => $c->incurred_on->format('Y-m'))->map(fn ($g) => (int) $g->sum('amount'))->sortKeys();

        return view('sourcing.reports', [
            'lots' => $lots->sortByDesc(fn ($l) => $figures[$l->id]['profit']), 'figures' => $figures,
            'byProduct' => $byProduct, 'bySupplier' => $bySupplier, 'unexpectedByMonth' => $unexpectedByMonth,
            'fundRequests' => EmergencyFundRequest::with('lot')->whereIn('sourcing_lot_id', $lots->pluck('id'))->latest('id')->get(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'products' => SupplierProduct::orderBy('name')->get(['id', 'name', 'variant']),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function payments(Request $request): View
    {
        $payments = SupplierPayment::with(['supplier', 'lot', 'expense'])
            ->when($request->integer('supplier'), fn ($q, $id) => $q->where('supplier_id', $id))
            ->latest('paid_on')->latest('id')->paginate(30)->withQueryString();

        return view('sourcing.payments', [
            'payments' => $payments,
            'suppliers' => Supplier::orderBy('name')->get()->map(fn ($s) => ['supplier' => $s, 'payable' => $s->payable()])->filter(fn ($r) => $r['payable']['purchases'] > 0 || $r['payable']['paid'] > 0),
        ]);
    }
}
