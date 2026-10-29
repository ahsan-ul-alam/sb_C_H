<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Every money figure is for one selected currency; currencies are never combined.
     * Period metrics use invoice issue dates and payment dates; balances are current.
     */
    public function __invoke(Request $request): View
    {
        $range = DateRange::fromRequest($request);
        $defaultCurrency = BusinessSetting::current()->default_currency;

        $currencies = Project::query()->distinct()->orderBy('currency')->pluck('currency')
            ->push($defaultCurrency)->unique()->sort()->values();
        $currency = $currencies->contains($request->query('currency')) ? $request->query('currency') : $defaultCurrency;

        $paymentsInCurrency = fn (): Builder => Payment::query()->whereHas('project', fn ($q) => $q->where('currency', $currency));
        $paymentsInRange = fn (): Builder => $paymentsInCurrency()
            ->whereDate('paid_on', '>=', $range->from)->whereDate('paid_on', '<=', $range->to);

        $openInvoices = Invoice::query()->where('currency', $currency)->open();

        $metrics = [
            'clients' => Client::whereNull('archived_at')->count(),
            'active_projects' => Project::whereNull('archived_at')->where('status', ProjectStatus::Active)->count(),
            'invoiced' => (int) Invoice::where('currency', $currency)
                ->whereDate('issued_on', '>=', $range->from)->whereDate('issued_on', '<=', $range->to)->sum('total'),
            'received' => (int) $paymentsInRange()->sum('amount'),
            'outstanding' => (int) (clone $openInvoices)->sum(DB::raw('total - amount_paid')),
            'overdue' => (int) Invoice::where('currency', $currency)->overdue()->sum(DB::raw('total - amount_paid')),
            'overdue_count' => Invoice::where('currency', $currency)->overdue()->count(),
            'open_count' => (clone $openInvoices)->count(),
        ];

        $attentionInvoices = (clone $openInvoices)
            ->with('project.client')
            ->orderByRaw('CASE WHEN due_on IS NOT NULL AND date(due_on) < ? THEN 0 ELSE 1 END', [today()->toDateString()])
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderBy('issued_on')
            ->limit(8)
            ->get();

        $recentPayments = $paymentsInRange()
            ->with(['project.client', 'invoice'])
            ->latest('paid_on')->latest('id')
            ->limit(8)
            ->get();

        $activeProjects = Project::query()
            ->with('client')
            ->where('currency', $currency)
            ->where('status', ProjectStatus::Active)
            ->whereNull('archived_at')
            ->withSum('segments', 'amount')
            ->withSum('payments', 'amount')
            ->latest('started_on')
            ->limit(8)
            ->get();

        return view('dashboard', [
            'range' => $range,
            'currency' => $currency,
            'currencies' => $currencies,
            'metrics' => $metrics,
            'attentionInvoices' => $attentionInvoices,
            'recentPayments' => $recentPayments,
            'activeProjects' => $activeProjects,
            'trend' => $this->collectionsTrend($paymentsInRange()->get(['paid_on', 'amount']), $range),
        ]);
    }

    /**
     * Bucket payments by day, week or month depending on the range length.
     *
     * @param  Collection<int, Payment>  $payments
     * @return array{unit: string, buckets: list<array{label: string, title: string, amount: int}>, max: int, total: int}
     */
    private function collectionsTrend(Collection $payments, DateRange $range): array
    {
        $days = $range->from->diffInDays($range->to) + 1;
        $unit = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');

        $bucketStart = fn (CarbonImmutable $date): CarbonImmutable => match ($unit) {
            'day' => $date,
            'week' => $date->startOfWeek(),
            'month' => $date->startOfMonth(),
        };

        $buckets = [];
        for ($cursor = $bucketStart($range->from); $cursor->lte($range->to); $cursor = $cursor->add(1, $unit)) {
            $buckets[$cursor->toDateString()] = [
                'label' => match ($unit) {
                    'day' => $cursor->format('j'),
                    'week' => $cursor->format('j M'),
                    'month' => $cursor->format('M'),
                },
                'title' => match ($unit) {
                    'day' => $cursor->format('D j M Y'),
                    'week' => 'Week of '.$cursor->format('j M Y'),
                    'month' => $cursor->format('F Y'),
                },
                'amount' => 0,
            ];
        }

        foreach ($payments as $payment) {
            $key = $bucketStart(CarbonImmutable::parse($payment->paid_on))->toDateString();
            if (isset($buckets[$key])) {
                $buckets[$key]['amount'] += $payment->amount;
            }
        }

        $amounts = array_column($buckets, 'amount');

        return [
            'unit' => $unit,
            'buckets' => array_values($buckets),
            'max' => $amounts ? max($amounts) : 0,
            'total' => array_sum($amounts),
        ];
    }
}
