<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\JournalLine;
use App\Models\Partner;
use App\Models\Resolution;
use App\Models\User;
use App\Services\Audit;
use App\Services\Ledger;
use App\Support\Increment;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Board resolutions. Partner salary is never automatic: a resolution must be approved by a Board member who is
 * neither the requester nor the beneficiary, then paid by Finance from the Salary Fund. Employee increments are
 * recorded as approved decisions (new salary vs previous).
 */
class ResolutionController extends Controller
{
    public const TYPES = ['partner_salary' => 'Partner salary', 'employee_increment' => 'Employee increment', 'exit_request' => 'Partner exit request', 'other' => 'Other'];

    /**
     * What a user may raise is the union of what each of their roles allows: Board/Super Admin anything,
     * HR employee increments, a Partner items about their own partner record.
     */
    public static function mayRaise(User $user, string $type, int $partnerId): bool
    {
        if ($user->hasRole('board_member', 'super_admin')) {
            return true;
        }
        if ($user->hasRole('hr') && $type === 'employee_increment') {
            return true;
        }

        return $user->hasRole('partner') && in_array($type, ['partner_salary', 'exit_request'], true)
            && $user->partner_id !== null && $partnerId === $user->partner_id;
    }

    /**
     * @return list<string>
     */
    public static function typesFor(User $user): array
    {
        return array_values(array_filter(array_keys(self::TYPES), fn ($t) => self::mayRaise($user, $t, (int) $user->partner_id)));
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $query = Resolution::with('partner')->latest('id');
        if (! $user->can('finance.view') && ! $user->can('resolution.decide')) {
            $query->where('requested_by', $user->id);
        }

        return view('finance.resolutions', [
            'resolutions' => $query->get(),
            'partners' => Partner::where('status', 'active')->orderBy('sort')->get(),
            'salaryFund' => Ledger::creditBalance('fund.salary'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'title' => ['required', 'string', 'max:200'],
            'partner_id' => ['nullable', 'required_if:type,partner_salary,exit_request', 'exists:partners,id'],
            'employee_name' => ['nullable', 'required_if:type,employee_increment', 'string', 'max:120'],
            'amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
            'previous_amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
            'month' => ['nullable', 'date_format:Y-m'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();
        $type = $data['type'];

        abort_unless(self::mayRaise($user, $type, (int) ($data['partner_id'] ?? 0)), 403);

        // Theoretical increment is a recommendation only; the Board decides the actual rate on approval.
        $basis = ['amount' => Money::toMinor($data['amount'] ?? 0)];
        if ($type === 'employee_increment') {
            $profit = max(0, (int) AccountingPeriod::where('status', 'verified')->where('month', '>', now()->subMonths(13)->format('Y-m'))->sum('net_profit'));
            $investment = (int) JournalLine::where('account', 'partner.capital')->sum('credit');
            $rate = Increment::rateBps($profit, $investment);
            $basis = ['amount' => 0, 'annual_profit' => $profit, 'original_investment' => $investment,
                'rate_bps' => $rate, 'theoretical_bps' => Increment::theoreticalBps($rate)];
        }

        $resolution = Resolution::create([
            ...$data,
            ...$basis,
            'previous_amount' => Money::toMinor($data['previous_amount'] ?? 0),
            'requested_by' => $user->id,
        ]);
        Audit::log('resolution.requested', 'resolution '.$resolution->id, $resolution->type);

        return back()->with('status', 'Resolution submitted to the Board.');
    }

    public function decide(Request $request, Resolution $resolution): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(function () use ($request, $resolution, $approve) {
            DB::transaction(function () use ($request, $resolution, $approve) {
                $resolution = Resolution::lockForUpdate()->findOrFail($resolution->id);
                $user = $request->user();
                if ($resolution->status !== 'pending') {
                    throw new RuntimeException('Resolution already decided.');
                }
                if ($resolution->requested_by === $user->id || ($resolution->partner_id && $resolution->partner_id === $user->partner_id)) {
                    throw new RuntimeException('You cannot decide a resolution you raised or that benefits you.');
                }
                $extra = [];
                if ($approve && $resolution->type === 'employee_increment') {
                    $bps = $request->input('approved_bps');
                    if (! ctype_digit((string) $bps) || (int) $bps > 10000 || ! $request->date('effective_on')) {
                        throw new RuntimeException('Enter the Board-approved increment (basis points, 0-10000) and an effective date.');
                    }
                    $reference = trim((string) $request->input('approval_reference'));
                    if ($reference === '') {
                        throw new RuntimeException('Enter the Board approval reference.');
                    }
                    $newSalary = Increment::apply($resolution->previous_amount, (int) $bps);
                    if ($newSalary - $resolution->previous_amount > Ledger::creditBalance('fund.salary')) {
                        throw new RuntimeException('The Salary Fund balance cannot cover this increase.');
                    }
                    $extra = ['approved_bps' => (int) $bps, 'effective_on' => $request->date('effective_on'),
                        'amount' => $newSalary, 'approval_reference' => $reference];
                }
                $resolution->update([...$extra, 'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decided_at' => now()]);
                Audit::log('resolution.'.($approve ? 'approved' : 'rejected'), 'resolution '.$resolution->id);
            });
        }, $approve ? 'Resolution approved.' : 'Resolution rejected.');
    }

    public function pay(Request $request, Resolution $resolution): RedirectResponse
    {
        return $this->attempt(function () use ($request, $resolution) {
            DB::transaction(function () use ($request, $resolution) {
                $resolution = Resolution::lockForUpdate()->findOrFail($resolution->id);
                $user = $request->user();
                if ($resolution->type !== 'partner_salary' || $resolution->status !== 'approved') {
                    throw new RuntimeException('Only an approved partner-salary resolution can be paid.');
                }
                if ($user->partner_id && $user->partner_id === $resolution->partner_id) {
                    throw new RuntimeException('You cannot pay your own salary.');
                }
                if ($resolution->amount <= 0 || Ledger::creditBalance('fund.salary') < $resolution->amount) {
                    throw new RuntimeException('The Salary Fund balance is insufficient.');
                }
                if (Ledger::debitBalance('cash') < $resolution->amount) {
                    throw new RuntimeException('Not enough cash recorded in the ledger.');
                }
                Ledger::post(today()->toDateString(), 'Partner salary: '.$resolution->title, 'salary', $resolution->id, [
                    ['fund.salary', $resolution->partner_id, $resolution->amount, 0],
                    ['cash', null, 0, $resolution->amount],
                ], $user->id);
                $resolution->update(['status' => 'paid', 'paid_by' => $user->id, 'paid_at' => now()]);
                Audit::log('salary.paid', 'resolution '.$resolution->id);
            });
        }, 'Salary paid from the Salary Fund.');
    }
}
