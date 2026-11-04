<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;

/**
 * One source-based reconciliation of what sourcing owes, built from the transactions themselves: for every lot and supplier the lines
 * ordered, the payments and the settlements, and for every lot cost its own expense. Nothing is subtracted from an aggregate and nothing
 * is offset between lots, suppliers or costs: each row stands alone and is floored at zero.
 *
 * The amounts of a row, all integer minor units:
 *  - total_obligation      what was ordered from the supplier (lines), or the cost's own amount
 *  - paid_amount           paid and verified (supplier payments net of refunds; a cost's live payments)
 *  - pending_payment       paid out but not yet verified by the Board: the money has left, the ledger does not know it yet
 *  - remaining_owed        total_obligation - paid_amount - pending_payment, never below zero
 *  - verified_payable      the payable the ledger carries (verified settlement payables net of verified payments; a verified unpaid cost)
 *  - recognised_in_ledger  the part of (remaining_owed + pending_payment) that ledger liabilities already carry
 *  - not_recognised        the rest: owed, but the ledger knows nothing of it yet
 *
 * A row says what kind of claim it is: "recognised" (the ledger carries it), "awaiting_verification" (a proposed cost or payment the
 * Board has not verified: a request, not yet a liability), "not_recorded" (a known commitment with no entry at all) or "settled".
 * Projections of these rows feed SourcingObligations (what holds back profit, and what the ledger does not carry yet).
 */
class SourcingReconciliation
{
    /**
     * @return array{rows: list<array<string, mixed>>, unlinked_pending: int, proposed_cost_expense_ids: list<int>}
     */
    public static function build(?string $asOf = null): array
    {
        $rows = [];
        $accounted = []; // pending expenses already carried by a row below
        $proposed = []; // pending UNPAID cost expenses: a proposed liability, counted once as an owed cost and never again as a pending payment

        SourcingLot::where('status', '!=', 'cancelled')->when($asOf, fn ($q) => $q->whereDate('created_at', '<=', $asOf))->get()
            ->each(function (SourcingLot $lot) use (&$rows, &$accounted, &$proposed) {
                $f = LotFinancials::for($lot);

                foreach (collect($f['lines'])->groupBy(fn ($r) => $r['line']->supplier_id) as $supplierId => $lines) {
                    $supplierId = (int) $supplierId;
                    $payments = $lot->payments->where('supplier_id', $supplierId)->reject(fn (SupplierPayment $p) => $p->isVoided());
                    $ordered = (int) $lines->sum('purchase');
                    $verifiedPaid = (int) $payments->filter(fn (SupplierPayment $p) => $p->expense?->status === 'verified')->sum(fn (SupplierPayment $p) => $p->effectiveAmount());
                    $awaiting = $payments->filter(fn (SupplierPayment $p) => $p->expense?->status === 'pending' && ! $p->expense->reverses_id);
                    $pending = (int) $awaiting->sum(fn (SupplierPayment $p) => (int) $p->expense->amount);
                    array_push($accounted, ...$awaiting->pluck('expense_id')->all());

                    $returned = SupplierReturnService::payableReduced($lot, $supplierId); // what returns and applied credit notes took off the payable
                    $remaining = max(0, $ordered - $verifiedPaid - $pending - $returned);
                    $ledger = AdvanceSettlementService::payableOutstanding($lot, $supplierId);
                    // The lot's own ledger payable covers its owed amount first, then its payments awaiting verification (a pending payment of
                    // that payable is part of the same liability, not a second claim on cash). Never another lot's or supplier's.
                    $remainingLeft = max(0, $remaining - $ledger);
                    $pendingLeft = max(0, $pending - max(0, $ledger - $remaining));
                    $covered = ($remaining + $pending) - ($remainingLeft + $pendingLeft);

                    $rows[] = [
                        'lot' => $lot->number, 'lot_id' => $lot->id, 'kind' => 'supplier', 'ref' => $supplierId, 'label' => (string) $lines->first()['line']->supplier?->name,
                        'status' => self::status($remaining + $pending, $covered, $pending > 0 && $remaining === 0),
                        'total_obligation' => $ordered, 'returned_reduction' => $returned, 'paid_amount' => $verifiedPaid, 'pending_payment' => $pending, 'remaining_owed' => $remaining,
                        'verified_payable' => $ledger, 'recognised_in_ledger' => $covered, 'not_recognised' => $remainingLeft + $pendingLeft,
                        'not_recognised_owed' => $remainingLeft, 'not_recognised_pending' => $pendingLeft,
                    ];
                }

                $replaced = $lot->costs->pluck('replaces_id')->filter()->all();
                foreach ($lot->costs->where('kind', 'actual')->reject(fn (SourcingLotCost $c) => in_array($c->id, $replaced, true)) as $cost) {
                    $expense = $cost->expense;
                    $live = $expense && $expense->status !== 'rejected';
                    $remaining = $cost->remainingUnpaid();
                    $awaitingPaid = $live && $expense->status === 'pending' && $expense->payment_status !== 'unpaid' ? (int) $expense->amount : 0;
                    $recognised = $live && $expense->status === 'verified' && in_array($expense->payment_status, ['unpaid', 'partial'], true) ? $expense->outstanding() : 0;
                    if ($live && $expense->status === 'pending') {
                        $accounted[] = $expense->id;
                        if ($expense->payment_status === 'unpaid') {
                            $proposed[] = $expense->id;
                        }
                    }
                    if ($remaining === 0 && $awaitingPaid === 0 && $recognised === 0 && $cost->paidAmount() === (int) $cost->amount) {
                        continue; // paid and verified: nothing owed, and nothing to show as an open obligation
                    }
                    $rows[] = [
                        'lot' => $lot->number, 'lot_id' => $lot->id, 'kind' => 'cost', 'ref' => $cost->id, 'label' => (string) ($cost->payee ?: $cost->category),
                        'status' => $remaining > 0 && ! $live ? 'not_recorded' : ($remaining > 0 && $expense->status === 'pending' ? 'awaiting_verification' : self::status($remaining + $awaitingPaid, $recognised, $awaitingPaid > 0 && $remaining === 0)),
                        'total_obligation' => (int) $cost->amount, 'paid_amount' => $live && $expense->status === 'verified' ? $cost->paidAmount() : 0, 'pending_payment' => $awaitingPaid,
                        'remaining_owed' => $remaining, 'verified_payable' => $recognised, 'recognised_in_ledger' => $recognised, 'not_recognised' => max(0, $remaining - $recognised),
                        'not_recognised_owed' => max(0, $remaining - $recognised), 'not_recognised_pending' => 0,
                    ];
                }
            });

        // Anything else awaiting verification that no row above carries (payments of cancelled lots, expenses linked to no live line).
        $unlinked = (int) self::pendingQuery($asOf)->when($accounted !== [], fn ($q) => $q->whereNotIn('id', $accounted))->sum('amount');

        return ['rows' => $rows, 'unlinked_pending' => $unlinked, 'proposed_cost_expense_ids' => $proposed];
    }

    /** @return Builder<Expense> */
    public static function pendingQuery(?string $asOf): Builder
    {
        $lotIds = SourcingLot::when($asOf, fn ($q) => $q->whereDate('created_at', '<=', $asOf))->select('id');

        return Expense::where('status', 'pending')->where('category', 'like', 'Sourcing:%')->whereNull('reverses_id')
            ->when($asOf, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('id', SupplierPayment::whereIn('sourcing_lot_id', $lotIds)->select('expense_id'))
                ->orWhereIn('id', SourcingLotCost::whereIn('sourcing_lot_id', $lotIds)->select('expense_id'))));
    }

    private static function status(int $claim, int $covered, bool $onlyAwaiting): string
    {
        return match (true) {
            $claim <= 0 => 'settled',
            $covered >= $claim => 'recognised',
            $covered > 0 => 'part_recognised',
            $onlyAwaiting => 'awaiting_verification',
            default => 'not_recorded',
        };
    }
}
