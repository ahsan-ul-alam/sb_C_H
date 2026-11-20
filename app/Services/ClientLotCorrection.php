<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\StockMovement;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A super administrator's correction of a client lot at ANY stage, closed or settled included: the client budget, quantity, unit base cost,
 * delivery charge, project and stage. Everything the system shows is derived from these records (LotFinancials, ProjectEconomics, the project
 * report, the client portal), so a correction is reflected everywhere at once. Records that would otherwise disagree are re-synced in the same
 * transaction, and only those:
 *
 *   - the lot's approval snapshot (approved cost / approved revenue) and the line's indicative unit price follow the new figures;
 *   - received stock follows the new quantity: the receipt movements are brought to the accepted quantity (a reduction is refused when the
 *     units are no longer in stock);
 *   - money already posted is REWRITTEN to the new amounts, as the owner decided: a lot that was paid in full stays paid in full at the new
 *     cost (supplier payments are rewritten, newest first), a supplier is never shown paid more than the new cost, and a delivery charge that
 *     was paid is rewritten with it. Posted journal entries stay immutable: the ledger receives one adjusting entry for the difference (sourced
 *     from the audit row of the rewrite), so cash and the expense accounts end exactly at the new amount. An amount rewritten to zero withdraws
 *     the payment (its expense rejected, the payment voided) and its adjustment takes the whole amount back out of the accounts.
 *     A month that is closed is never rewritten, and a payment that has corrections, advance settlements or an accrual classification is
 *     refused with a reason (those positions are owned by their own reviewed workflows).
 *   - the stage is set exactly as chosen; stock and money are not moved by a stage change.
 *
 * Every field's before → after and the reason are written to the audit log.
 */
class ClientLotCorrection
{
    /** Positions owned by the accrual workflows (advances, payables, capitalised inventory, prepayments): never rewritten here. */
    private const ACCRUAL_CLASSIFICATIONS = ['supplier_advance', 'supplier_payable', 'acquisition', 'transport_prepayment', 'prepaid'];

    public const STAGE_STATUS = ['processing' => 'approved', 'in_order' => 'in_progress', 'completed' => 'completed', 'settled' => 'settled', 'cancelled' => 'cancelled'];

    /**
     * @param  array{client_budget: int, quantity: string, unit_cost: int, delivery_charge: int, stage: string, project_id: int, reason: string}  $data
     * @return list<string> what was changed, in plain words
     */
    public static function apply(SourcingLot $lot, array $data, User $user): array
    {
        if (! $user->hasRole('super_admin')) {
            throw new RuntimeException('Only a super administrator can correct a lot after it was created.');
        }
        $reason = trim($data['reason']);
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Give the reason for the correction (at least 5 characters).');
        }
        if (! preg_match('/^[1-9]\d{0,9}$/', $data['quantity'])) {
            throw new RuntimeException('Quantity must be a whole number greater than zero.');
        }
        if ($data['unit_cost'] <= 0 || $data['client_budget'] <= 0) {
            throw new RuntimeException('The unit base cost and the client budget must be above zero.');
        }
        if ($data['delivery_charge'] < 0) {
            throw new RuntimeException('The delivery charge cannot be negative (enter 0 for free delivery).');
        }
        if (! array_key_exists($data['stage'], self::STAGE_STATUS)) {
            throw new RuntimeException('Choose one of the stages.');
        }

        return DB::transaction(function () use ($lot, $data, $user, $reason) {
            $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (! $lot->hasClientBudget()) {
                throw new RuntimeException('Only a lot made on the simple client-lot form can be corrected here; manage this lot from the detailed view.');
            }
            $line = $lot->lines()->firstOrFail();
            $before = LotFinancials::for($lot);
            $changes = [];

            if ((int) $data['project_id'] !== (int) $lot->project_id) {
                $project = Project::whereKey($data['project_id'])->whereNull('archived_at')->first() ?? throw new RuntimeException('Choose an active client project.');
                $changes[] = 'project '.($lot->project?->name ?? '—').' → '.$project->name;
                $lot->update(['project_id' => $project->id]);
            }

            // Quantity and unit cost: the line, and the stock already received.
            $oldQty = (string) $line->quantity;
            $newQty = bcadd($data['quantity'], '0', 2);
            if (bccomp($oldQty, $newQty, 2) !== 0 || (int) $line->unit_price !== $data['unit_cost']) {
                $shortfall = bcsub($oldQty, (string) $line->usable_quantity, 2); // units accepted short or damaged at arrival stay short
                $usable = bccomp(bcsub($newQty, $shortfall, 2), '0', 2) > 0 ? bcsub($newQty, $shortfall, 2) : $newQty;
                $attributes = ['quantity' => $newQty, 'usable_quantity' => $usable, 'unit_price' => $data['unit_cost']];
                if ($line->received_quantity !== null) {
                    $attributes['received_quantity'] = $newQty;
                }
                if (bccomp($oldQty, $newQty, 2) !== 0) {
                    $changes[] = 'quantity '.self::qty($oldQty).' → '.self::qty($newQty);
                }
                if ((int) $line->unit_price !== $data['unit_cost']) {
                    $changes[] = 'unit base cost '.Money::format((int) $line->unit_price).' → '.Money::format($data['unit_cost']);
                }
                $line->update($attributes);
                if ($line->received_quantity !== null) {
                    $location = StockMovement::where('sourcing_lot_line_id', $line->id)->where('type', 'receipt')->whereNotNull('stock_location_id')->latest('id')->value('stock_location_id');
                    Inventory::syncReceipt($line, $user, null, $location);
                }
            }

            if ((int) $lot->client_budget !== $data['client_budget']) {
                $changes[] = 'client budget '.Money::format((int) $lot->client_budget).' → '.Money::format($data['client_budget']);
                $lot->update(['client_budget' => $data['client_budget']]);
            }
            $unit = intdiv((int) $lot->client_budget, max(1, (int) $line->refresh()->quantity));
            $line->update(['sell_price' => $unit, 'approved_price' => $unit]);

            $delivery = self::syncDelivery($lot, $data['delivery_charge'], $user, $reason);
            if ($delivery !== null) {
                $changes[] = $delivery;
            }
            $supplier = self::syncSupplierPayments($lot->refresh(), $before, $reason);
            if ($supplier !== null) {
                $changes[] = $supplier;
            }

            $status = self::STAGE_STATUS[$data['stage']];
            if ($status !== $lot->status) {
                $changes[] = 'stage '.$lot->stageLabel().($lot->status === 'settled' ? ' (books closed)' : '').' → '.SourcingLot::STAGES[$data['stage'] === 'settled' ? 'completed' : $data['stage']].($status === 'settled' ? ' (books closed)' : '');
                $lot->update(self::stageAttributes($lot, $status, $user, $reason));
            }

            $after = LotFinancials::for($lot->refresh());
            $lot->update(['approved_cost' => $after['landed'], 'approved_revenue' => (int) $lot->client_budget]);
            InventoryValuation::syncLot($lot->id);

            if ($changes === []) {
                throw new RuntimeException('Nothing was changed.');
            }
            Audit::log('sourcing.client_lot.corrected', 'lot '.$lot->number, implode('; ', $changes).' | profit '.Money::format($before['profit']).' → '.Money::format($after['profit']).' | reason: '.$reason);

            return $changes;
        });
    }

    /**
     * The stage exactly as chosen. Reaching a stage for the first time stamps it; leaving "settled" or "cancelled" clears what only that state means.
     * Earlier timestamps (ordered, completed) are history and are kept.
     *
     * @return array<string, mixed>
     */
    private static function stageAttributes(SourcingLot $lot, string $status, User $user, string $reason): array
    {
        $attributes = ['status' => $status];
        if (in_array($status, ['in_progress', 'completed', 'settled'], true) && $lot->ordered_at === null) {
            $attributes += ['ordered_at' => now(), 'ordered_by' => $user->id];
        }
        if (in_array($status, ['completed', 'settled'], true) && $lot->completed_at === null) {
            $attributes += ['completed_at' => now(), 'completed_by' => $user->id];
        }
        $attributes += $status === 'settled' ? ['settled_at' => $lot->settled_at ?? now(), 'settled_by' => $lot->settled_by ?? $user->id] : ['settled_at' => null, 'settled_by' => null];
        $attributes += $status === 'cancelled' ? ['cancelled_at' => now(), 'cancel_reason' => $reason] : ['cancelled_at' => null, 'cancel_reason' => null];

        return $attributes;
    }

    /** The delivery charge: the lot's current Delivery cost is set to the new amount (created, changed or removed), its paid expense rewritten with it. */
    private static function syncDelivery(SourcingLot $lot, int $amount, User $user, string $reason): ?string
    {
        $replaced = $lot->costs()->whereNotNull('replaces_id')->pluck('replaces_id')->all();
        $costs = $lot->costs()->with('expense')->where('kind', 'actual')->where('category', 'Delivery')->whereNotIn('id', $replaced)->orderBy('id')->get();
        $old = (int) $costs->sum('amount');
        if ($old === $amount) {
            return null;
        }
        $last = $costs->last();
        $others = $old - (int) ($last?->amount ?? 0);
        if ($last === null) {
            // Created directly: the normal cost entry refuses closed lots and re-opens pricing, and a correction must do neither.
            SourcingLotCost::create(['sourcing_lot_id' => $lot->id, 'category' => 'Delivery', 'description' => 'Delivery charge (set by correction)', 'amount' => $amount,
                'kind' => 'actual', 'is_unexpected' => false, 'incurred_on' => today()->toDateString(), 'created_by' => $user->id]);
        } elseif ($amount < $others) {
            throw new RuntimeException('This lot has several delivery costs; the new delivery charge cannot be below the earlier ones ('.Money::format($others).'). Correct them in the detailed view.');
        } else {
            $newLast = $amount - $others;
            if ($last->expense) {
                self::rewriteExpense($last->expense, $newLast, $reason);
            }
            $newLast === 0 ? $last->delete() : $last->update(['amount' => $newLast]);
        }

        return 'delivery charge '.Money::format($old).' → '.Money::format($amount);
    }

    /**
     * Supplier payments after a cost change: a lot that was paid in full stays paid in full at the new cost, and no lot stays paid more than its cost.
     * The difference is applied to the newest live payments first.
     *
     * @param  array<string, mixed>  $before
     */
    private static function syncSupplierPayments(SourcingLot $lot, array $before, string $reason): ?string
    {
        $purchase = LotFinancials::for($lot)['purchase'];
        $payments = SupplierPayment::where('sourcing_lot_id', $lot->id)->whereNull('voided_at')->with(['expense', 'corrections'])->orderByDesc('id')->get();
        $paid = SourcingPayments::paidTotal($payments);
        $wasPaidInFull = $before['purchase'] > 0 && $before['supplier_paid'] >= $before['purchase'];
        $target = $wasPaidInFull ? $purchase : min($paid, $purchase);
        if ($target === $paid || $payments->isEmpty()) {
            return null;
        }
        $difference = $target - $paid;
        foreach ($payments as $payment) {
            if ($difference === 0) {
                break;
            }
            $current = (int) $payment->amount;
            $new = max(0, $current + $difference);
            self::rewriteExpense($payment->expense, $new, $reason, $payment);
            $difference -= $new - $current;
        }

        return 'supplier paid '.Money::format($paid).' → '.Money::format($target).' (payments rewritten)';
    }

    /**
     * Rewrite a posted (or pending) expense to a new amount. Its one journal entry is rebuilt from the same rules that posted it, so it stays balanced;
     * zero withdraws it (the expense is rejected, the payment voided and the entry removed).
     */
    private static function rewriteExpense(Expense $expense, int $amount, string $reason, ?SupplierPayment $payment = null): void
    {
        $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
        if ((int) $expense->amount === $amount) {
            return;
        }
        if ($expense->reverses_id !== null || Expense::where('reverses_id', $expense->id)->where('status', '!=', 'rejected')->exists()) {
            throw new RuntimeException('Expense #'.$expense->id.' has a correction recorded against it; resolve that correction first.');
        }
        if (in_array($expense->classification, self::ACCRUAL_CLASSIFICATIONS, true) || in_array($expense->payment_status, ['unpaid', 'partial'], true)) {
            throw new RuntimeException('Expense #'.$expense->id.' is an accrual advance or payable; correct it through its own settlement workflow.');
        }
        if ($payment !== null && AdvanceSettlementService::settledAmount($payment) > 0) {
            throw new RuntimeException('Supplier payment #'.$payment->id.' is already settled against accepted goods; it cannot be rewritten.');
        }
        AccountingService::assertOpen($expense->spent_on->toDateString(), 'the payment being rewritten');

        $old = (int) $expense->amount;
        $entry = JournalEntry::where('source_type', 'expense')->where('source_id', $expense->id)->with('lines')->first();
        if ($entry !== null && $amount > $old) {
            AccountingService::assertCanSpend(AccountingService::resolveAccountId($expense->financial_account_id), $amount - $old);
        }
        // The audit row is the source of the ledger adjustment, so each rewrite is posted once and traceable to its reason.
        $audit = AuditLog::create(['user_id' => auth()->id(), 'action' => 'expense.rewritten', 'subject' => 'expense '.$expense->id,
            'details' => Money::format($old).' → '.Money::format($amount).' (lot correction: '.$reason.')']);

        if ($entry !== null) {
            Ledger::post($entry->entry_date->toDateString(), ($amount === 0 ? 'Withdrawn' : 'Rewritten').' by lot correction: expense #'.$expense->id.' '.Money::format($old).' → '.Money::format($amount).' — '.$reason,
                'expense_rewrite', $audit->id, self::adjustmentLines($entry, $old, $amount), auth()->id());
        }
        if ($amount === 0) {
            $expense->update(['status' => 'rejected']);
            $payment?->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => 'Lot correction: '.$reason]);
        } else {
            $expense->update(['amount' => $amount] + ($expense->payment_status === 'paid' ? ['paid_amount' => $amount] : []));
            $payment?->update(['amount' => $amount]);
        }
        if ($entry !== null) {
            InventoryValuation::onExpensePosted($expense);
        }
    }

    /**
     * The entry that turns an expense currently standing at $old (its posting plus earlier rewrites) into one of $new: every line of the original
     * posting moves by the difference on its own side (a reduction moves to the opposite side), so posted entries are never edited and the
     * accounts end exactly where a posting of $new would have put them. Only a plain posting (every line the same amount) is adjusted this way.
     *
     * @return list<array{0: string, 1: int|null, 2: int, 3: int, 4: int|null}>
     */
    private static function adjustmentLines(JournalEntry $entry, int $old, int $new): array
    {
        if ($entry->isReversed() || $entry->lines->map(fn (JournalLine $l) => (int) $l->debit + (int) $l->credit)->unique()->count() !== 1) {
            throw new RuntimeException('Journal entry '.$entry->reference().' is split or already reversed; correct this payment through the expense correction workflow.');
        }
        $delta = $new - $old;

        return $entry->lines->map(fn (JournalLine $l) => (int) $l->debit > 0
            ? [$l->account, $l->partner_id, max(0, $delta), max(0, -$delta), $l->financial_account_id]
            : [$l->account, $l->partner_id, max(0, -$delta), max(0, $delta), $l->financial_account_id])->all();
    }

    private static function qty(string $q): string
    {
        return rtrim(rtrim($q, '0'), '.');
    }
}
