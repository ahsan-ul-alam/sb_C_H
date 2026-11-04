<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Resolution;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only views that keep Main Finance honest about sourcing: money owed but not yet in the ledger, and
 * Emergency Fund coverage waiting to be applied to a partner loss. Nothing here posts anything. Every figure is a projection of
 * SourcingReconciliation, which is built from the source transactions, so the views cannot disagree with each other.
 */
class SourcingObligations
{
    /** A Board resolution (type "Other") with exactly this title authorises coverage to reduce partner loss charges. */
    public const COVERAGE_RESOLUTION = 'Apply Emergency Fund coverage to partner loss allocation';

    /**
     * Everything sourcing owes or has paid out unverified, by the lot, supplier and cost that owes it: supplier payables (ordered, net of
     * payments), unpaid costs of live lots (a part-paid cost owes only the remainder), and payments awaiting Board verification.
     * A proposed cost awaiting verification is counted once, as an owed cost, never again as a pending payment.
     *
     * This is the COMPLETE list that holds back profit allocation (a payable the ledger already carries is still an open obligation);
     * profit allocation only asks whether the total is above zero. See notInLedger() for what the ledger does not carry yet.
     *
     * @return array{suppliers: int, costs: int, pending: int, total: int}
     */
    public static function outstanding(?string $asOf = null): array
    {
        $model = SourcingReconciliation::build($asOf);
        $rows = collect($model['rows']);
        $suppliers = (int) $rows->where('kind', 'supplier')->sum('remaining_owed');
        $costs = (int) $rows->where('kind', 'cost')->sum('remaining_owed');
        $pending = (int) $rows->sum('pending_payment') + $model['unlinked_pending'];

        return ['suppliers' => $suppliers, 'costs' => $costs, 'pending' => $pending, 'total' => $suppliers + $costs + $pending];
    }

    /**
     * The part of the obligations the general ledger does not carry yet. Under the accrual sourcing basis, accepted goods not yet
     * paid for (a payable settlement) and incurred-but-unpaid acquisition costs are already ledger liabilities, so a free-cash figure that adds
     * the ledger's liabilities and the obligations above would count them twice. It is worked out per lot, supplier and cost from the source
     * transactions, never by subtracting one aggregate from another. Under the cash basis nothing is in the ledger yet, so the result equals
     * outstanding().
     *
     * @return array{suppliers: int, costs: int, pending: int, total: int, in_ledger: int, lines: list<array{lot: string, kind: string, ref: int, obligation: int, in_ledger: int, not_in_ledger: int}>, rows: list<array<string, mixed>>}
     */
    public static function notInLedger(?string $asOf = null): array
    {
        $model = SourcingReconciliation::build($asOf);
        $rows = collect($model['rows']);
        $suppliers = (int) $rows->where('kind', 'supplier')->sum('not_recognised_owed');
        $costs = (int) $rows->where('kind', 'cost')->sum('not_recognised');
        $pending = (int) $rows->where('kind', 'supplier')->sum('not_recognised_pending') + (int) $rows->where('kind', 'cost')->sum('pending_payment') + $model['unlinked_pending'];

        return [
            'suppliers' => $suppliers, 'costs' => $costs, 'pending' => $pending, 'total' => $suppliers + $costs + $pending,
            'in_ledger' => (int) $rows->sum('recognised_in_ledger'),
            'lines' => $rows->map(fn ($r) => [
                'lot' => $r['lot'], 'kind' => $r['kind'], 'ref' => $r['ref'], 'obligation' => $r['remaining_owed'] + $r['pending_payment'],
                'in_ledger' => $r['recognised_in_ledger'], 'not_in_ledger' => $r['not_recognised'] + ($r['kind'] === 'cost' ? $r['pending_payment'] : 0),
            ])->all(),
            'rows' => $model['rows'],
        ];
    }

    /**
     * The exact items behind a profit-allocation holdback. The list is computed live, so an item disappears by itself
     * the moment its cause is resolved (paid, verified or settled) — there is nothing to clear by hand.
     *
     * @return list<array{lot: string, lot_id: int, who: string, amount: int, reason: string, action: string}>
     */
    public static function details(?string $asOf = null): array
    {
        $model = SourcingReconciliation::build($asOf);
        $rows = [];
        foreach ($model['rows'] as $r) {
            if ($r['remaining_owed'] > 0) {
                $rows[] = $r['kind'] === 'supplier'
                    ? ['lot' => $r['lot'], 'lot_id' => $r['lot_id'], 'who' => $r['label'], 'amount' => $r['remaining_owed'],
                        'reason' => 'Supplier payable not yet paid', 'action' => 'Pay the supplier, then record the payment on the lot']
                    : ['lot' => $r['lot'], 'lot_id' => $r['lot_id'], 'who' => $r['label'], 'amount' => $r['remaining_owed'],
                        'reason' => 'Lot cost not yet paid ('.($r['status'] === 'awaiting_verification' ? 'recorded as a payable, awaiting verification' : 'remaining balance').')',
                        'action' => 'Pay the cost, then mark it paid on the lot'];
            }
        }
        $pending = self::pendingQuery($asOf)->get();
        foreach ($pending as $e) {
            if (in_array($e->id, $model['proposed_cost_expense_ids'], true)) {
                continue; // a proposed cost already shows above as an owed cost
            }
            $lotId = SupplierPayment::where('expense_id', $e->id)->value('sourcing_lot_id') ?? SourcingLotCost::where('expense_id', $e->id)->value('sourcing_lot_id');
            $lot = $lotId ? SourcingLot::find($lotId) : null;
            if ($asOf && $lot && $lot->created_at->toDateString() > $asOf) {
                continue;
            }
            $rows[] = ['lot' => $lot?->number ?? '—', 'lot_id' => $lot?->id ?? 0, 'who' => $e->category, 'amount' => (int) $e->amount,
                'reason' => 'Payment recorded but not yet verified', 'action' => 'A Board member verifies the expense (or ask for a standing policy for routine payments)'];
        }

        return $rows;
    }

    /** Sourcing payments recorded but not yet verified by the Board (not yet in the ledger either). */
    public static function pendingExpenses(?string $asOf = null): int
    {
        return (int) self::pendingQuery($asOf)->sum('amount');
    }

    /**
     * @return Builder<Expense>
     */
    private static function pendingQuery(?string $asOf)
    {
        return SourcingReconciliation::pendingQuery($asOf);
    }

    public static function coverageResolutionApproved(): bool
    {
        return Resolution::where('type', 'other')->where('title', self::COVERAGE_RESOLUTION)->whereIn('status', ['approved', 'paid'])->exists();
    }

    /**
     * Utilised Emergency Fund coverage not yet applied to a posted loss allocation, minus what proposed/approved
     * allocations have already claimed (so two allocations can never consume the same coverage).
     */
    public static function heldCoverage(): int
    {
        $claimed = (int) DB::table('allocation_lines as l')->join('allocations as a', 'a.id', '=', 'l.allocation_id')
            ->where('l.account', 'fund.coverage')->whereIn('a.status', ['proposed', 'approved'])->sum('l.amount');

        return max(0, Ledger::creditBalance('fund.coverage') - $claimed);
    }
}
