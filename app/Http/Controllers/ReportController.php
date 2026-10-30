<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\ExitSettlement;
use App\Models\JournalLine;
use App\Models\OpeningLine;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\Resolution;
use App\Services\RevenueRecognition;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One report page built from the journal (balances) and closed periods (revenue lines). CSV uses the same data.
 */
class ReportController extends Controller
{
    private const LABELS = [
        'cash' => 'Cash & bank', 'receivables' => 'Customer receivables', 'customer.advances' => 'Customer advances (unearned liability)',
        'liabilities' => 'Supplier / other liabilities', 'fund.salary' => 'Salary Fund', 'fund.office' => 'Office & Business Growth Fund',
        'fund.emergency' => 'Emergency Reserve', 'equity.opening' => 'Opening equity', 'income' => 'Income account (net of refunds)',
        'expense' => 'Expenses account', 'exit.incentive' => 'Exit incentives paid', 'exit.adjustment' => 'Exit settlement adjustments',
        'fund.coverage' => 'Sourcing losses covered by Emergency Reserve (awaiting Board application)', 'profit.pool' => 'Profit pool (allocated)', 'loss.pool' => 'Loss pool (allocated)',
    ];

    public function index(Request $request): View|StreamedResponse
    {
        $periods = AccountingPeriod::orderBy('month')->get()->map(fn ($p) => [
            'period' => $p, 'rev' => RevenueRecognition::figures($p->month),
        ]);

        $balances = JournalLine::query()->selectRaw('account, partner_id, SUM(debit) d, SUM(credit) c')->groupBy('account', 'partner_id')->get()
            ->map(fn ($r) => ['account' => $r->account, 'partner_id' => $r->partner_id, 'debit' => (int) $r->d, 'credit' => (int) $r->c]);

        $partners = Partner::orderBy('sort')->get()->keyBy('id');
        $partnerLabels = ['partner.capital' => 'Partner capital (paid-in, net of losses)', 'partner.payable' => 'Partner profit entitlement payable'];
        $rows = $balances->groupBy(fn ($b) => isset($partnerLabels[$b['account']]) ? $b['account'].'|'.$b['partner_id'] : $b['account'])
            ->map(function ($group, $key) use ($partnerLabels, $partners) {
                [$account, $partnerId] = array_pad(explode('|', $key), 2, null);
                $label = $partnerId ? $partnerLabels[$account].' — '.($partners[$partnerId]->code ?? $partnerId) : (self::LABELS[$account] ?? $account);

                return [$label, (int) $group->sum('credit') - (int) $group->sum('debit')];
            })->sortBy(0)->values();

        $data = [
            'periods' => $periods,
            'rows' => $rows,
            'trialDebit' => (int) $balances->sum('debit'),
            'trialCredit' => (int) $balances->sum('credit'),
            'opening' => OpeningLine::query()->join('opening_batches as b', 'b.id', '=', 'opening_lines.opening_batch_id')
                ->where('b.status', 'posted')->selectRaw('opening_lines.kind, SUM(opening_lines.amount) total')->groupBy('opening_lines.kind')->pluck('total', 'kind'),
            'payoutsPaid' => (int) Payout::where('status', 'paid')->sum('amount'),
            'salaryPaid' => (int) Resolution::where('type', 'partner_salary')->where('status', 'paid')->sum('amount'),
            'exits' => ExitSettlement::with('partner')->where('status', '!=', 'cancelled')->get(),
        ];

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($data): void {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['month', 'collected', 'advances_received', 'advances_recognised', 'refunds', 'income', 'expenses', 'net_profit']);
                foreach ($data['periods'] as $p) {
                    fputcsv($out, [$p['period']->month, ...array_map(Money::toDecimal(...), [$p['rev']['collected'], $p['rev']['advances'], $p['rev']['recognised'],
                        $p['rev']['refund_revenue'] + $p['rev']['refund_advance'], $p['period']->income, $p['period']->expenses, $p['period']->net_profit])]);
                }
                fputcsv($out, []);
                fputcsv($out, ['balance', 'credit_minus_debit']);
                foreach ($data['rows'] as [$label, $amount]) {
                    fputcsv($out, [$label, Money::toDecimal($amount)]);
                }
                fclose($out);
            }, 'finance-report.csv', ['Content-Type' => 'text/csv']);
        }

        return view('finance.reports', $data);
    }
}
