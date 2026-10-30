<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\Partner;
use App\Services\FinancialStatements;
use App\Services\PeriodProcessor;
use App\Services\ProfitAllocator;
use App\Services\SourcingObligations;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PeriodController extends Controller
{
    public function index(): View
    {
        $month = request('month', now()->subMonth()->format('Y-m'));

        return view('finance.periods', [
            'periods' => AccountingPeriod::with('allocation')->orderByDesc('month')->get(),
            'month' => $month,
            'draft' => $this->figures($month),
        ]);
    }

    public function show(AccountingPeriod $period): View
    {
        $policy = ProfitAllocator::policyFor($period->month);

        return view('finance.period', [
            'period' => $period,
            'allocation' => $period->allocation()->with(['lines', 'policy'])->first(),
            'policy' => $policy,
            'preview' => $policy ? ProfitAllocator::preview($period->net_profit, $policy, ProfitAllocator::coverageFor($period->net_profit)) : [],
            'obligations' => $period->net_profit > 0 ? SourcingObligations::outstanding(CarbonImmutable::createFromFormat('Y-m', $period->month)->endOfMonth()->toDateString()) : null,
            'heldCoverage' => $period->net_profit < 0 ? SourcingObligations::heldCoverage() : 0,
            'coverageApproved' => SourcingObligations::coverageResolutionApproved(),
            'partners' => Partner::pluck('code', 'id'),
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return $this->attempt(fn () => PeriodProcessor::close($data['month'], $request->user()), 'Period closed.');
    }

    public function verify(Request $request, AccountingPeriod $period): RedirectResponse
    {
        return $this->attempt(fn () => PeriodProcessor::verify($period, $request->user()), 'Period verified.');
    }

    public function prepare(Request $request, AccountingPeriod $period): RedirectResponse
    {
        return $this->attempt(fn () => ProfitAllocator::prepare($period, $request->user()), 'Allocation prepared; awaiting Board approval.');
    }

    public function approve(Request $request, Allocation $allocation): RedirectResponse
    {
        return $this->attempt(fn () => ProfitAllocator::approve($allocation, $request->user()), 'Allocation approved.');
    }

    public function post(Request $request, Allocation $allocation): RedirectResponse
    {
        return $this->attempt(fn () => ProfitAllocator::post($allocation, $request->user()), 'Allocation posted to the ledger.');
    }

    /**
     * @return array<string, int>
     */
    private function figures(string $month): array
    {
        return FinancialStatements::monthFigures($month);
    }
}
