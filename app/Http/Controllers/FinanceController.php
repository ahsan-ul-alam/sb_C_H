<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\CapitalEntry;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\PolicyVersion;
use App\Models\Resolution;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function dashboard(): View
    {
        $partners = Partner::orderBy('sort')->get()->map(fn ($p) => [
            'partner' => $p,
            'capital' => Ledger::creditBalance('partner.capital', $p->id),
            'payable' => Ledger::creditBalance('partner.payable', $p->id),
        ]);

        return view('finance.dashboard', [
            'partners' => $partners,
            'funds' => [
                'Salary Fund' => Ledger::creditBalance('fund.salary'),
                'Office & Growth Fund' => Ledger::creditBalance('fund.office'),
                'Emergency Reserve' => Ledger::creditBalance('fund.emergency'),
            ],
            'cash' => Ledger::debitBalance('cash'),
            'totals' => [
                'Revenue (closed periods)' => (int) AccountingPeriod::sum('income'),
                'Expenses (closed periods)' => (int) AccountingPeriod::sum('expenses'),
                'Net profit (closed periods)' => (int) AccountingPeriod::sum('net_profit'),
            ],
            'partnerFund' => (int) $partners->sum('payable'),
            'advances' => Ledger::creditBalance('customer.advances'),
            'recentAudit' => AuditLog::with('user')->orderByDesc('id')->limit(6)->get(),
            'periods' => AccountingPeriod::with('allocation')->orderByDesc('month')->limit(6)->get(),
            'pending' => [
                'Capital to verify' => CapitalEntry::where('status', 'pending')->count(),
                'Expenses to verify' => Expense::where('status', 'pending')->count(),
                'Payouts awaiting approval' => Payout::where('status', 'requested')->count(),
                'Resolutions pending' => Resolution::where('status', 'pending')->count(),
                'Allocations to approve/post' => Allocation::whereIn('status', ['proposed', 'approved'])->count(),
            ],
            'policy' => PolicyVersion::orderByDesc('effective_from')->orderByDesc('id')->first(),
        ]);
    }

    /**
     * Partner self-service: own balances only.
     */
    public function me(Request $request): View
    {
        $partner = Partner::findOrFail($request->user()->partner_id ?? 0);

        return view('finance.me', [
            'partner' => $partner,
            'capital' => Ledger::creditBalance('partner.capital', $partner->id),
            'payable' => Ledger::creditBalance('partner.payable', $partner->id),
            'payouts' => Payout::where('partner_id', $partner->id)->latest()->get(),
            'capitalEntries' => CapitalEntry::where('partner_id', $partner->id)->latest('paid_on')->get(),
        ]);
    }

    public function journal(): View
    {
        return view('finance.journal', [
            'lines' => JournalLine::with(['entry', 'partner'])->orderByDesc('id')->paginate(60),
        ]);
    }

    public function journalCsv(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['entry', 'date', 'memo', 'account', 'partner', 'debit', 'credit']);
            JournalLine::with(['entry', 'partner'])->orderBy('id')->chunk(500, function ($lines) use ($out): void {
                foreach ($lines as $l) {
                    fputcsv($out, [$l->journal_entry_id, $l->entry->entry_date->toDateString(), $l->entry->memo, $l->account,
                        $l->partner?->code, Money::toDecimal($l->debit), Money::toDecimal($l->credit)]);
                }
            });
            fclose($out);
        }, 'journal.csv', ['Content-Type' => 'text/csv']);
    }

    public function audit(): View
    {
        return view('finance.audit', ['logs' => AuditLog::with('user')->orderByDesc('id')->paginate(60)]);
    }

    public function partners(): View
    {
        return view('finance.partners', [
            'partners' => Partner::orderBy('sort')->get(),
            'policy' => PolicyVersion::orderByDesc('effective_from')->orderByDesc('id')->first(),
            'linkedUsers' => User::whereNotNull('partner_id')->get()->keyBy('partner_id'),
        ]);
    }
}
