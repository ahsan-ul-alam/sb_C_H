<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\CourierAdvance;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A courier charge SELLBEES paid on behalf of a client and recovers from that client's sales proceeds. It is NOT a loan to the client and NEVER revenue.
 *
 *   1. Sellbees pays the courier: an ordinary expense (Dr expense / Cr cash), recorded and verified as usual. Nothing here pays it again.
 *   2. Verified reclassification (non-cash, once):   Dr assets.client_courier_advance / Cr expense      (the expense is no longer Sellbees' cost; it is a receivable from the client)
 *      Proposed by one user and verified by a different one, like the loan-receivable reclassification; closed months are never touched.
 *   3. Customer collection (existing):                Dr cash / Cr client.funds
 *   4. Settlement (existing entry, extra lines):      Dr client.funds / Cr assets.client_courier_advance      the withheld client money repays the advance; cash does not move
 *   5. Excess (the order later shrank through returns, so the client owes less than was reclassified): Dr expense / Cr assets.client_courier_advance, once per settlement.
 * After full recovery the receivable and the client-funds liability for the advance are both zero, no income is booked, and a repeated settlement cannot recover it twice.
 */
class CourierAdvanceService
{
    public const ACCOUNT = 'assets.client_courier_advance';

    /** Propose that a courier charge Sellbees paid (a verified, fully paid expense) is recoverable from the order's client. Repeating returns the existing proposal. */
    public static function propose(ClientOrder $order, Expense $expense, User $user): CourierAdvance
    {
        return DB::transaction(function () use ($order, $expense, $user) {
            $order = ClientOrder::whereKey($order->id)->lockForUpdate()->with(['items', 'project'])->firstOrFail();
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $existing = CourierAdvance::where('client_order_id', $order->id)->whereIn('status', ['pending', 'verified'])->first();
            if ($existing) {
                return $existing;
            }
            $amount = $order->recoverableCharge();
            if ($amount <= 0) {
                throw new RuntimeException('This order has no client-borne courier charge paid by Sellbees to recover (it must be delivered, client-borne, and marked "paid by Sellbees").');
            }
            self::assertReclassifiable($expense);
            $used = (int) CourierAdvance::where('expense_id', $expense->id)->whereIn('status', ['pending', 'verified'])->sum('amount');
            if ($used + $amount > (int) $expense->amount) {
                throw new RuntimeException('That expense ('.Money::format((int) $expense->amount).') is not large enough to cover this advance as well as the ones already linked to it.');
            }
            $advance = CourierAdvance::create(['client_order_id' => $order->id, 'expense_id' => $expense->id, 'amount' => $amount, 'created_by' => $user->id]);
            Audit::log('courier_advance.proposed', 'order '.$order->id, 'expense '.$expense->id.' '.$amount);

            return $advance;
        });
    }

    public static function decide(CourierAdvance $advance, bool $approve, User $user, ?string $note = null): void
    {
        if (! $approve && trim((string) $note) === '') {
            throw new RuntimeException('Give a reason for rejecting.');
        }
        DB::transaction(function () use ($advance, $approve, $user, $note) {
            $advance = CourierAdvance::whereKey($advance->id)->lockForUpdate()->firstOrFail();
            if ($advance->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($advance->created_by === $user->id) {
                throw new RuntimeException('You cannot verify a courier advance you proposed.');
            }
            $expense = Expense::whereKey($advance->expense_id)->lockForUpdate()->firstOrFail();
            if ($approve) {
                self::assertReclassifiable($expense);
                $order = ClientOrder::whereKey($advance->client_order_id)->lockForUpdate()->with('items')->firstOrFail();
                if ($order->recoverableCharge() < (int) $advance->amount) {
                    throw new RuntimeException('The charge recoverable from the client is now lower than this proposal (a later return); propose it again.');
                }
                Ledger::post($expense->spent_on->toDateString(), 'Expense #'.$expense->id.' reclassified as a courier charge recoverable from the client (order '.$order->reference.')', 'courier_advance', $advance->id, [
                    [self::ACCOUNT, null, (int) $advance->amount, 0],
                    ['expense', null, 0, (int) $advance->amount],
                ], $user->id);
            }
            $advance->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $user->id, 'verified_at' => now(), 'decision_note' => trim((string) $note) ?: null]);
            Audit::log('courier_advance.'.($approve ? 'verified' : 'rejected'), 'courier advance '.$advance->id, 'expense '.$expense->id);
        });
    }

    /** What is still withheld from the client for this order: the whole charge until a verified advance exists, afterwards only what is not yet cleared. */
    public static function stillWithheld(ClientOrder $order): int
    {
        $owed = $order->recoverableCharge();
        if ($owed <= 0) {
            return 0;
        }
        $advance = CourierAdvance::where('client_order_id', $order->id)->where('status', 'verified')->first();

        return $advance ? max(0, $owed - (int) $advance->applied) : $owed;
    }

    /**
     * What a settlement does with courier advances, computed (and optionally locked) before anything is posted. The amount applied never exceeds what the client still owes,
     * what is open on the advance, or the money actually held, so client funds can never go negative and nothing is recovered twice.
     *
     * @return array{rows: list<array{advance: CourierAdvance, apply: int, release: int}>, apply: int, release: int, unrecorded: int, withheld: int}
     */
    public static function plan(Client $client, int $headroom, bool $lock = false): array
    {
        $orders = ClientOrder::where('fulfilment', 'stock')->where('delivery_charge_status', 'paid_by_sellbees')->whereHas('project', fn ($q) => $q->where('client_id', $client->id))->with('items')->orderBy('id')->get();
        $advances = CourierAdvance::where('status', 'verified')->whereIn('client_order_id', $orders->pluck('id'))->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('id')->get()->keyBy('client_order_id');

        $rows = [];
        $unrecorded = 0;
        $apply = 0;
        $release = 0;
        $left = max(0, $headroom);
        foreach ($orders as $o) {
            $owed = $o->recoverableCharge();
            $advance = $advances->get($o->id);
            if ($owed <= 0 && ! $advance) {
                continue;
            }
            if (! $advance) {
                $unrecorded += $owed;

                continue;
            }
            $stillOwed = max(0, $owed - (int) $advance->applied);
            $take = min($stillOwed, $advance->open(), $left);
            $left -= $take;
            $excess = max(0, $advance->open() - $stillOwed);
            $rows[] = ['advance' => $advance, 'apply' => $take, 'release' => $excess];
            $apply += $take;
            $release += $excess;
        }

        return ['rows' => $rows, 'apply' => $apply, 'release' => $release, 'unrecorded' => $unrecorded, 'withheld' => $unrecorded + $apply];
    }

    /** Record the plan on the advances and post the excess release (the clearing lines themselves go into the settlement's own entry). */
    public static function commit(array $plan, int $settlementId, string $date, ?int $userId): void
    {
        foreach ($plan['rows'] as $r) {
            if ($r['apply'] > 0 || $r['release'] > 0) {
                $r['advance']->update(['applied' => (int) $r['advance']->applied + $r['apply'], 'released' => (int) $r['advance']->released + $r['release']]);
            }
        }
        if ($plan['release'] > 0) {
            Ledger::post($date, 'Courier advance not recoverable after returns returned to expense (settlement #'.$settlementId.')', 'courier_advance_release', $settlementId, [
                ['expense', null, $plan['release'], 0],
                [self::ACCOUNT, null, 0, $plan['release']],
            ], $userId);
        }
    }

    /** The receivable the ledger should hold: verified advances less what was cleared or released. */
    public static function openReceivable(): int
    {
        return (int) CourierAdvance::where('status', 'verified')->selectRaw('COALESCE(SUM(amount - applied - released),0) s')->value('s');
    }

    /** @return Collection<int, Expense> verified, fully paid, operating or other expenses that can be linked (newest first) */
    public static function candidateExpenses(): Collection
    {
        return Expense::where('status', 'verified')->where('payment_status', 'paid')->whereNull('reverses_id')->where('is_asset', false)->whereIn('classification', ['operating', 'other'])
            ->latest('spent_on')->latest('id')->limit(60)->get();
    }

    private static function assertReclassifiable(Expense $expense): void
    {
        if ($expense->status !== 'verified' || $expense->reverses_id || $expense->payment_status !== 'paid') {
            throw new RuntimeException('Only a verified, fully paid expense can be linked to a courier advance.');
        }
        if ($expense->is_asset || ! in_array($expense->classification, ['operating', 'other'], true)) {
            throw new RuntimeException('Only an operating or other expense can be linked to a courier advance.');
        }
        if (Expense::where('reverses_id', $expense->id)->whereIn('status', ['pending', 'verified'])->exists()) {
            throw new RuntimeException('This expense has a reversal; link it only if the reversal is rejected.');
        }
        if (! JournalEntry::where('source_type', 'expense')->where('source_id', $expense->id)->exists()) {
            throw new RuntimeException('This expense has no journal entry, so no cash left through the books.');
        }
        if (AccountingService::isClosed($expense->spent_on->toDateString())) {
            throw new RuntimeException('The month of this expense is closed; closed periods are never changed.');
        }
    }
}
