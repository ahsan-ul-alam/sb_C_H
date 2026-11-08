<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\Partner;
use App\Models\Payout;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\PayoutService;
use App\Services\SubmissionGuard;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Partner profit withdrawals: partner requests -> a different Board member approves (never the partner
 * themselves) -> Finance pays (never the partner themselves). Posted only on payment.
 */
class PayoutController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $scoped = ! $user->can('finance.view');
        $query = Payout::with('partner')->latest('id');
        if ($scoped) {
            $query->where('partner_id', $user->partner_id ?? 0);
        }

        return view('finance.payouts', [
            'payouts' => $query->get(),
            'partners' => $scoped ? Partner::where('id', $user->partner_id ?? 0)->get() : Partner::orderBy('sort')->get(),
            'available' => Partner::all()->mapWithKeys(fn ($p) => [$p->id => $this->available($p->id)]),
            'accounts' => FinancialAccount::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'note' => ['nullable', 'string', 'max:200'], 'submission_key' => ['nullable', 'uuid']]);
        $partnerId = $request->user()->partner_id;
        abort_unless($partnerId, 403);

        // A repeated submit surfaces as DuplicateSubmission (a RuntimeException): attempt() shows it, naming the payout already created.
        return $this->attempt(function () use ($request, $data, $partnerId) {
            SubmissionGuard::once($data['submission_key'] ?? null, 'payout.request', $request->user()->id, function () use ($request, $data, $partnerId): Payout {
                Partner::whereKey($partnerId)->lockForUpdate()->first(); // two requests cannot both pass the entitlement check
                $amount = Money::toMinor($data['amount']);
                if ($amount <= 0 || $amount > $this->available($partnerId)) {
                    throw new RuntimeException('Amount must be positive and within your available profit entitlement ('.Money::format($this->available($partnerId)).').');
                }
                $payout = Payout::create(['partner_id' => $partnerId, 'amount' => $amount, 'note' => $data['note'] ?? null, 'requested_by' => $request->user()->id]);
                Audit::log('payout.requested', 'payout '.$payout->id, (string) $amount);

                return $payout;
            });
        }, 'Payout requested.');
    }

    public function decide(Request $request, Payout $payout): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(function () use ($request, $payout, $approve) {
            DB::transaction(function () use ($request, $payout, $approve) {
                $payout = Payout::lockForUpdate()->findOrFail($payout->id);
                $user = $request->user();
                if ($payout->status !== 'requested') {
                    throw new RuntimeException('Payout already decided.');
                }
                if ($payout->requested_by === $user->id || ($user->partner_id && $user->partner_id === $payout->partner_id)) {
                    throw new RuntimeException('You cannot approve your own payout.');
                }
                $payout->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $user->id, 'decided_at' => now()]);
                Audit::log('payout.'.($approve ? 'approved' : 'rejected'), 'payout '.$payout->id);
            });
        }, $approve ? 'Payout approved.' : 'Payout rejected.');
    }

    public function pay(Request $request, Payout $payout): RedirectResponse
    {
        $data = $request->validate(['financial_account_id' => ['nullable', Rule::exists('financial_accounts', 'id')->where('is_active', true)]]);

        return $this->attempt(function () use ($request, $payout, $data) {
            PayoutService::pay($payout, $request->user(), isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null);
        }, 'Payout paid and posted.');
    }

    /**
     * Posted entitlement minus payouts already requested or approved (not yet paid).
     */
    private function available(int $partnerId): int
    {
        $open = (int) Payout::where('partner_id', $partnerId)->whereIn('status', ['requested', 'approved'])->sum('amount');

        return max(0, Ledger::creditBalance('partner.payable', $partnerId) - $open);
    }
}
