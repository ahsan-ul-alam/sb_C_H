<?php

namespace App\Http\Controllers;

use App\Services\BusinessDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BusinessDashboardController extends Controller
{
    public const PERIODS = ['all' => 'All time', 'this_month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Custom range'];

    /**
     * Flow metrics (revenue, expenses, earned margin) use the chosen period; balances (investment, cash, receivables) are as of the end of it, cash as of today.
     */
    public function __invoke(Request $request): View
    {
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'all';
        $today = CarbonImmutable::today();
        [$from, $to] = match ($period) {
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'custom' => $this->custom($request, $today),
            default => [null, $today],
        };

        return view('business.dashboard', [
            'data' => BusinessDashboard::build($from, $to), 'period' => $period, 'periods' => self::PERIODS, 'from' => $from, 'to' => $to,
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function custom(Request $request, CarbonImmutable $today): array
    {
        $parse = function (mixed $value): ?CarbonImmutable {
            try {
                return is_string($value) && $value !== '' ? CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay() : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $from = $parse($request->query('from')) ?? $today->startOfMonth();
        $to = $parse($request->query('to')) ?? $today;

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }
}
