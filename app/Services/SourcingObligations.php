<?php

namespace App\Services;

use App\Models\Allocation;
use App\Models\Expense;
use App\Models\Resolution;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SupplierPayment;
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
        $lotIds = SourcingLot::when($asOf, fn ($q) => $q->whereDate('created_at', '<=', $asOf))->select('id');

        return (int) Expense::where('status', 'pending')->where('category', 'like', 'Sourcing:%')->whereNull('reverses_id')
            ->when($asOf, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('id', SupplierPayment::whereIn('sourcing_lot_id', $lotIds)->select('expense_id'))
                ->orWhereIn('id', SourcingLotCost::whereIn('sourcing_lot_id', $lotIds)->select('expense_id'))))
            ->sum('amount');
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
