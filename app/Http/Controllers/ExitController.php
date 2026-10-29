<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ExitSettlement;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Resolution;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\ProfitAllocator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Partner exit settlement.
 *
 * AdjustedInvestment = RecordedInvestment - ApprovedUnadjustedLosses - ApprovedExpensesOrLiabilities - PreviousCapitalWithdrawals
 * ExitIncentive      = verified new-policy Company Fund allocations of the last 12 months x the partner's PROFIT FUND ratio
 * FinalSettlement    = AdjustedInvestment + ExitIncentive + UnpaidApprovedEntitlements + OtherReceivables - OtherLiabilities
 *
 * Payment is only recorded when Finance confirms the transfer actually happened.
 */
class ExitController extends Controller
{
    public function index(): View
    {
        return view('finance.exits', [
            'exits' => ExitSettlement::with('partner')->latest('id')->get(),
            'documents' => Document::where('subject_type', 'exit')->get()->groupBy('subject_id'),
            'partners' => Partner::where('status', 'active')->orderBy('sort')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $money = ['nullable', 'regex:'.Money::AMOUNT_REGEX];
        $data = $request->validate([
            'partner_id' => ['required', 'exists:partners,id'],
            'exit_date' => ['required', 'date'],
            'previous_withdrawals' => $money,
            'expenses_liabilities' => $money,
            'other_liabilities' => $money,
            'other_receivables' => $money,
            'adjustment_note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->attempt(function () use ($request, $data) {
            $partner = Partner::findOrFail($data['partner_id']);
            if (! $partner->isActive() || ExitSettlement::where('partner_id', $partner->id)->where('status', '!=', 'cancelled')->exists()) {
                throw new RuntimeException('This partner already has an exit settlement.');
            }
            if (! Resolution::where('partner_id', $partner->id)->where('type', 'exit_request')->where('status', 'approved')->exists()) {
                throw new RuntimeException('The partner must first submit an exit request that the Board has approved (Board Resolutions).');
            }
            $exitDate = CarbonImmutable::parse($data['exit_date']);
            $policy = ProfitAllocator::policyFor($exitDate->format('Y-m'));
            if (! $policy || $policy->liveBlocker()) {
                throw new RuntimeException($policy?->liveBlocker() ?? 'No policy exists.');
            }

            $bps = (int) ($policy->profit_bps[$partner->code] ?? 0);
            $base = ProfitAllocator::companyFundBase($exitDate);
            $incentive = (int) bcdiv(bcmul((string) $base, (string) $bps, 0), '10000', 0);

            // Gross paid-in capital from the ledger (verified entries and opening balances); losses are deducted separately below.
            $recorded = (int) JournalLine::where('account', 'partner.capital')->where('partner_id', $partner->id)->sum('credit');
            $losses = (int) DB::table('allocation_lines as l')->join('allocations as a', 'a.id', '=', 'l.allocation_id')
                ->where('a.status', 'posted')->where('a.kind', 'loss')->where('l.partner_id', $partner->id)->sum('l.amount');
            $withdrawals = Money::toMinor($data['previous_withdrawals'] ?? 0);
            $expenses = Money::toMinor($data['expenses_liabilities'] ?? 0);
            $adjusted = $recorded - $losses - $expenses - $withdrawals;

            $unpaid = Ledger::creditBalance('partner.payable', $partner->id) + $this->salaryDue($partner->id);
            $liabilities = Money::toMinor($data['other_liabilities'] ?? 0);
            $receivables = Money::toMinor($data['other_receivables'] ?? 0);

            $settlement = ExitSettlement::create([
                'partner_id' => $partner->id, 'exit_date' => $exitDate->toDateString(), 'company_fund_base' => $base,
                'profit_bps' => $bps, 'incentive' => $incentive,
                'recorded_investment' => $recorded, 'unadjusted_losses' => $losses, 'expenses_liabilities' => $expenses,
                'previous_withdrawals' => $withdrawals, 'unpaid_entitlements' => $unpaid,
                'other_liabilities' => $liabilities, 'other_receivables' => $receivables,
                'capital_balance' => $adjusted, 'profit_payable' => $unpaid, 'other_adjustment' => $receivables - $liabilities,
                'adjustment_note' => $data['adjustment_note'] ?? null,
                'net_settlement' => $adjusted + $incentive + $unpaid + $receivables - $liabilities,
                'prepared_by' => $request->user()->id,
            ]);
            Audit::log('exit.prepared', 'exit '.$settlement->id, 'incentive '.$incentive.' net '.$settlement->net_settlement);
        }, 'Exit settlement calculated; awaiting Board approval.');
    }

    public function approve(Request $request, ExitSettlement $exit): RedirectResponse
    {
        return $this->attempt(function () use ($request, $exit) {
            DB::transaction(function () use ($request, $exit) {
                $exit = ExitSettlement::lockForUpdate()->findOrFail($exit->id);
                $user = $request->user();
                if ($exit->status !== 'proposed') {
                    throw new RuntimeException('Already approved.');
                }
                if (Document::where('subject_type', 'exit')->where('subject_id', $exit->id)->doesntExist()) {
                    throw new RuntimeException('Attach the supporting legal/financial documents before approval.');
                }
                if ($exit->prepared_by === $user->id || $user->partner_id === $exit->partner_id) {
                    throw new RuntimeException('You cannot approve this settlement.');
                }
                $exit->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
                $exit->partner->update(['status' => 'exited', 'exited_on' => $exit->exit_date]);
                Audit::log('exit.approved', 'exit '.$exit->id);
            });
        }, 'Exit approved; partner marked as exited.');
    }

    /**
     * Finance confirms the transfer happened. Posts one balanced entry that clears the partner's capital and
     * profit-payable accounts; any difference to the approved figure goes to exit.adjustment.
     */
    public function pay(Request $request, ExitSettlement $exit): RedirectResponse
    {
        $data = $request->validate(['paid_on' => ['required', 'date'], 'payment_reference' => ['required', 'string', 'max:200']]);

        return $this->attempt(function () use ($request, $exit, $data) {
            DB::transaction(function () use ($request, $exit, $data) {
                $exit = ExitSettlement::lockForUpdate()->findOrFail($exit->id);
                $user = $request->user();
                if ($exit->status !== 'approved' || $exit->payment_status !== 'unpaid') {
                    throw new RuntimeException('Only an approved, unpaid settlement can be paid.');
                }
                if ($user->partner_id && $user->partner_id === $exit->partner_id) {
                    throw new RuntimeException('You cannot pay out your own settlement.');
                }
                $documents = Document::where('subject_type', 'exit')->where('subject_id', $exit->id)->get();
                if ($documents->isEmpty() || $documents->contains(fn ($d) => $d->review_status !== 'approved')) {
                    throw new RuntimeException('Every supporting document must be approved by the Board before payment.');
                }
                $salaryDue = $this->salaryDue($exit->partner_id);
                $payable = Ledger::creditBalance('partner.payable', $exit->partner_id);
                if ($payable + $salaryDue !== (int) $exit->unpaid_entitlements) {
                    throw new RuntimeException('Partner entitlements changed after the settlement was approved; it needs a new calculation.');
                }
                if ($exit->net_settlement > 0 && Ledger::debitBalance('cash') < $exit->net_settlement) {
                    throw new RuntimeException('Not enough cash recorded in the ledger.');
                }
                if ($salaryDue > Ledger::creditBalance('fund.salary')) {
                    throw new RuntimeException('The Salary Fund cannot cover the approved salary owed to this partner.');
                }

                $capital = Ledger::creditBalance('partner.capital', $exit->partner_id);
                $signed = [ // positive = debit, negative = credit
                    ['partner.capital', $exit->partner_id, $capital],
                    ['partner.payable', $exit->partner_id, $payable],
                    ['exit.incentive', null, (int) $exit->incentive],
                    ['fund.salary', null, $salaryDue],
                ];
                $plug = (int) $exit->net_settlement - array_sum(array_column($signed, 2));
                $signed[] = ['exit.adjustment', null, $plug];
                $signed[] = ['cash', null, -(int) $exit->net_settlement];

                $lines = [];
                foreach ($signed as [$account, $partnerId, $amount]) {
                    $lines[] = [$account, $partnerId, max($amount, 0), max(-$amount, 0)];
                }
                $entry = Ledger::post($data['paid_on'], 'Exit settlement partner '.$exit->partner_id, 'exit', $exit->id, $lines, $user->id);

                Resolution::where('partner_id', $exit->partner_id)->where('type', 'partner_salary')->where('status', 'approved')
                    ->update(['status' => 'paid', 'paid_by' => $user->id, 'paid_at' => now()]);
                $exit->update(['payment_status' => 'paid', 'paid_on' => $data['paid_on'], 'payment_reference' => $data['payment_reference'],
                    'paid_by' => $user->id, 'journal_entry_id' => $entry->id]);
                Audit::log('exit.paid', 'exit '.$exit->id, $data['payment_reference']);
            });
        }, 'Settlement payment recorded.');
    }

    /**
     * Cancel an unpaid, unposted calculation so it can be recalculated from current records.
     */
    public function cancel(Request $request, ExitSettlement $exit): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(function () use ($request, $exit, $data) {
            DB::transaction(function () use ($request, $exit, $data) {
                $exit = ExitSettlement::lockForUpdate()->findOrFail($exit->id);
                if ($exit->status === 'cancelled' || $exit->payment_status !== 'unpaid' || $exit->journal_entry_id) {
                    throw new RuntimeException('A paid or posted settlement cannot be cancelled; correct it with an authorised adjustment.');
                }
                $exit->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancel_reason' => $data['cancel_reason']]);
                $exit->partner->update(['status' => 'active', 'exited_on' => null]);
                Audit::log('exit.cancelled', 'exit '.$exit->id, $data['cancel_reason']);
            });
        }, 'Settlement cancelled. Calculate it again from the current records.');
    }

    private function salaryDue(int $partnerId): int
    {
        return (int) Resolution::where('partner_id', $partnerId)->where('type', 'partner_salary')->where('status', 'approved')->sum('amount');
    }
}
