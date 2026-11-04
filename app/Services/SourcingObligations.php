<?php

namespace App\Services;

use App\Models\Allocation;
use App\Models\Expense;
use App\Models\Resolution;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only views that keep Main Finance honest about sourcing: money owed but not yet in the ledger, and
 * Emergency Fund coverage waiting to be applied to a partner loss. Nothing here posts anything.
 */
class SourcingObligations
{
    /** A Board resolution (type "Other") with exactly this title authorises coverage to reduce partner loss charges. */
    public const COVERAGE_RESOLUTION = 'Apply Emergency Fund coverage to partner loss allocation';

    /**
     * Sourcing money not yet in the ledger as an expense: supplier payables, unpaid actual costs of live lots, and
     * sourcing payments awaiting Board verification — all created by $asOf (everything when null).
     *
     * @return array{suppliers: int, costs: int, pending: int, total: int}
     */
    public static function outstanding(?string $asOf = null): array
    {
        $suppliers = 0;
        $costs = 0;
        SourcingLot::where('status', '!=', 'cancelled')
            ->when($asOf, fn ($q) => $q->whereDate('created_at', '<=', $asOf))
            ->get()
            ->each(function (SourcingLot $lot) use (&$suppliers, &$costs) {
                $f = LotFinancials::for($lot);
                $suppliers += $f['supplier_outstanding'];
                $costs += $f['costs_unpaid'];
            });

        $pending = self::pendingExpenses($asOf);

        return ['suppliers' => $suppliers, 'costs' => $costs, 'pending' => $pending, 'total' => $suppliers + $costs + $pending];
    }

    /**
     * The part of the sourcing obligations the general ledger does not carry yet. Under the accrual sourcing basis, accepted goods not yet
     * paid for (a payable settlement) and incurred-but-unpaid acquisition costs are already ledger liabilities, so a free-cash figure that adds
     * the ledger's liabilities and the obligations above would count them twice. outstanding() stays the complete list that holds back profit
     * (a payable the ledger knows is still an open obligation); this view is what is left once what the ledger already carries is taken out.
     *
     * It is worked out from the source transactions, never by subtracting one aggregate from another: per lot and supplier the ledger payable
     * of THAT lot and supplier absorbs that lot's supplier obligation (never another lot's, and never below zero), and a lot cost is already in
     * the ledger only when its own expense is verified. Under the cash basis nothing is in the ledger yet, so the result equals outstanding().
     *
     * @return array{suppliers: int, costs: int, pending: int, total: int, in_ledger: int, lines: list<array{lot: string, kind: string, ref: int, obligation: int, in_ledger: int, not_in_ledger: int}>}
     */
    public static function notInLedger(?string $asOf = null): array
    {
        $suppliers = 0;
        $costs = 0;
        $pending = 0;
        $inLedger = 0;
        $lines = [];
        $accounted = []; // pending expenses already counted inside a supplier or cost line below

        SourcingLot::where('status', '!=', 'cancelled')->when($asOf, fn ($q) => $q->whereDate('created_at', '<=', $asOf))->get()
            ->each(function (SourcingLot $lot) use (&$suppliers, &$costs, &$pending, &$inLedger, &$lines, &$accounted) {
                $f = LotFinancials::for($lot);
                foreach (collect($f['lines'])->groupBy(fn ($r) => $r['line']->supplier_id) as $supplierId => $rows) {
                    $payments = $lot->payments->where('supplier_id', (int) $supplierId);
                    $owed = max(0, (int) $rows->sum('purchase') - SourcingPayments::paidTotal($payments));
                    $awaiting = $payments->filter(fn ($p) => ! $p->isVoided() && $p->expense?->status === 'pending' && ! $p->expense->reverses_id);
                    $awaitingAmount = (int) $awaiting->sum(fn ($p) => (int) $p->expense->amount);
                    $accounted = [...$accounted, ...$awaiting->pluck('expense_id')->all()];

                    // The ledger payable of this lot and supplier covers its obligation first, then its payments awaiting verification
                    // (a pending payment of that payable is part of the same liability, not a second claim on cash).
                    $ledger = AdvanceSettlementService::payableOutstanding($lot, (int) $supplierId);
                    $owedLeft = max(0, $owed - $ledger);
                    $awaitingLeft = max(0, $awaitingAmount - max(0, $ledger - $owed));
                    $suppliers += $owedLeft;
                    $pending += $awaitingLeft;
                    $covered = ($owed + $awaitingAmount) - ($owedLeft + $awaitingLeft);
                    $inLedger += $covered;
                    $lines[] = ['lot' => $lot->number, 'kind' => 'supplier', 'ref' => (int) $supplierId, 'obligation' => $owed + $awaitingAmount, 'in_ledger' => $covered, 'not_in_ledger' => $owedLeft + $awaitingLeft];
                }

                $replaced = $lot->costs->pluck('replaces_id')->filter()->all();
                foreach ($lot->costs->where('kind', 'actual')->reject(fn ($c) => in_array($c->id, $replaced, true) || $c->isPaid()) as $cost) {
                    $expense = $cost->expense;
                    if ($expense?->status === 'verified') { // incurred, unpaid and verified: Dr inventory / Cr liabilities is already in the ledger
                        $inLedger += $expense->outstanding();
                        $lines[] = ['lot' => $lot->number, 'kind' => 'cost', 'ref' => $cost->id, 'obligation' => (int) $cost->amount, 'in_ledger' => $expense->outstanding(), 'not_in_ledger' => 0];

                        continue;
                    }
                    $costs += (int) $cost->amount;
                    if ($expense?->status === 'pending') {
                        $accounted[] = $expense->id;
                    }
                    $lines[] = ['lot' => $lot->number, 'kind' => 'cost', 'ref' => $cost->id, 'obligation' => (int) $cost->amount, 'in_ledger' => 0, 'not_in_ledger' => (int) $cost->amount];
                }
            });

        // Anything else awaiting verification (paid lot costs, payments of cancelled lots, expenses linked to no live line).
        $pending += (int) self::pendingQuery($asOf)->when($accounted !== [], fn ($q) => $q->whereNotIn('id', $accounted))->sum('amount');

        return ['suppliers' => $suppliers, 'costs' => $costs, 'pending' => $pending, 'total' => $suppliers + $costs + $pending, 'in_ledger' => $inLedger, 'lines' => $lines];
    }

    /**
     * The exact items behind a profit-allocation holdback. The list is computed live, so an item disappears by itself
     * the moment its cause is resolved (paid, verified or settled) — there is nothing to clear by hand.
     *
     * @return list<array{lot: string, lot_id: int, who: string, amount: int, reason: string, action: string}>
     */
    public static function details(?string $asOf = null): array
    {
        $rows = [];
        SourcingLot::where('status', '!=', 'cancelled')->when($asOf, fn ($q) => $q->whereDate('created_at', '<=', $asOf))->get()->each(function (SourcingLot $lot) use (&$rows) {
            $f = LotFinancials::for($lot);
            foreach (collect($f['lines'])->groupBy(fn ($r) => $r['line']->supplier_id) as $supplierId => $lines) {
                $paid = SourcingPayments::paidTotal($lot->payments->where('supplier_id', $supplierId));
                $owed = max(0, (int) $lines->sum('purchase') - $paid);
                if ($owed > 0) {
                    $rows[] = ['lot' => $lot->number, 'lot_id' => $lot->id, 'who' => $lines->first()['line']->supplier->name, 'amount' => $owed,
                        'reason' => 'Supplier payable not yet paid', 'action' => 'Pay the supplier, then record the payment on the lot'];
                }
            }
            foreach ($lot->costs->where('kind', 'actual')->reject(fn ($c) => $c->isPaid() || $lot->costs->contains('replaces_id', $c->id)) as $cost) {
                $rows[] = ['lot' => $lot->number, 'lot_id' => $lot->id, 'who' => $cost->payee ?: $cost->category, 'amount' => $cost->amount,
                    'reason' => 'Lot cost not yet paid ('.$cost->category.')', 'action' => 'Pay the cost, then mark it paid on the lot'];
            }
        });
        $pending = Expense::where('status', 'pending')->where('category', 'like', 'Sourcing:%')->whereNull('reverses_id')->get();
        foreach ($pending as $e) {
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
    private static function pendingQuery(?string $asOf): Builder
    {
        $lotIds = SourcingLot::when($asOf, fn ($q) => $q->whereDate('created_at', '<=', $asOf))->select('id');

        return Expense::where('status', 'pending')->where('category', 'like', 'Sourcing:%')->whereNull('reverses_id')
            ->when($asOf, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('id', SupplierPayment::whereIn('sourcing_lot_id', $lotIds)->select('expense_id'))
                ->orWhereIn('id', SourcingLotCost::whereIn('sourcing_lot_id', $lotIds)->select('expense_id'))));
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
