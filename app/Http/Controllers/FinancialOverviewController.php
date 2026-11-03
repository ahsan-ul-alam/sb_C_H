<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Project;
use App\Services\AccountingService;
use App\Services\FinancialReportingService;
use App\Services\FinancialStatements;
use App\Services\ProjectFinancialService;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One page, one source of truth: every figure comes from FinancialReportingService / ProjectFinancialService /
 * AccountingService, which read the same verified records as the ledger.
 */
class FinancialOverviewController extends Controller
{
    public function index(Request $request): View
    {
        $range = DateRange::fromRequest($request);
        $projects = Project::with('client')->where('currency', config('finance.currency'))->whereNull('archived_at')->get();
        $summaries = ProjectFinancialService::summaries($projects->pluck('id'));

        return view('finance.overview', [
            'range' => $range,
            'kpis' => FinancialReportingService::kpis($range->from, $range->to),
            'pl' => FinancialReportingService::profitAndLoss($range->from, $range->to),
            'bridge' => FinancialReportingService::distributableBridge($range->from->format('Y-m')),
            'series' => FinancialReportingService::monthlySeries(12),
            'categories' => FinancialReportingService::expenseCategories($range->from, $range->to),
            'profitability' => FinancialReportingService::projectProfitability(10),
            'accounts' => AccountingService::balances(),
            'capital' => FinancialStatements::capital(),
            'receivables' => $projects->map(fn ($p) => ['project' => $p, ...$summaries[$p->id]])->filter(fn ($r) => $r['outstanding'] > 0)->sortByDesc('outstanding')->take(8)->values(),
            'payables' => Expense::with('project')->where('status', 'verified')->whereNull('reverses_id')->whereIn('payment_status', ['unpaid', 'partial'])->orderBy('spent_on')->limit(8)->get(),
        ]);
    }
}
