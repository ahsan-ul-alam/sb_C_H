<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\Expense;
use App\Models\Partner;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\ProfitAllocator;
use App\Services\RevenueRecognition;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

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
            'preview' => $policy ? ProfitAllocator::preview($period->net_profit, $policy) : [],
            'partners' => Partner::pluck('code', 'id'),
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return $this->attempt(function () use ($request, $data) {
            $month = $data['month'];
            if ($month >= now()->format('Y-m')) {
                throw new RuntimeException('Only a finished month can be closed.');
            }
            if (AccountingPeriod::where('month', $month)->exists()) {
                throw new RuntimeException('This month is already closed.');
            }
            $f = $this->figures($month);
            if ($f['pending'] > 0) {
                throw new RuntimeException($f['pending'].' unverified expense(s) or revenue adjustment(s) fall in this month; verify or reject them first.');
            }
            DB::transaction(function () use ($request, $month, $f) {
                $period = AccountingPeriod::create([
                    'month' => $month, 'income' => $f['income'], 'expenses' => $f['expenses'],
                    'net_profit' => $f['income'] - $f['expenses'], 'closed_by' => $request->user()->id,
                ]);
                // Book the month's cash, earned income, refunds and customer-advance liability (expenses were journaled on verification).
                $lines = RevenueRecognition::journalLines($f);
                if ($lines) {
                    Ledger::post(CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString(), 'Income '.$month, 'period', $period->id, $lines, $request->user()->id);
                }
                Audit::log('period.closed', $month, 'net '.$period->net_profit);
            });
        }, 'Period closed.');
    }

    public function verify(Request $request, AccountingPeriod $period): RedirectResponse
    {
        return $this->attempt(function () use ($request, $period) {
            if ($period->status !== 'closed') {
                throw new RuntimeException('Already verified.');
            }
            if ($period->closed_by === $request->user()->id) {
                throw new RuntimeException('You cannot verify a period you closed.');
            }
            $period->update(['status' => 'verified', 'verified_by' => $request->user()->id, 'verified_at' => now()]);
            Audit::log('period.verified', $period->month);
        }, 'Period verified.');
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
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->endOfMonth();
        $expenses = Expense::whereDate('spent_on', '>=', $start)->whereDate('spent_on', '<=', $end);
        $revenue = RevenueRecognition::figures($month);

        return [
            ...$revenue,
            'expenses' => (int) (clone $expenses)->where('status', 'verified')->sum('amount'),
            'pending' => (clone $expenses)->where('status', 'pending')->count() + $revenue['pending'],
        ];
    }
}
