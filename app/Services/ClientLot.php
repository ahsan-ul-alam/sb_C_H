<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The simple client-lot workflow: one supplier product, a quantity, a unit base cost and a client budget agreed for the whole lot, moved
 * through three operational stages — Processing → In order → Completed.
 *
 * It is a thin layer over the existing sourcing engine, never a parallel one. A lot is an ordinary SourcingLot with one line; the delivery
 * charge is an ordinary lot cost; receiving goes through Receiving (stock movement, valuation, duplicate-reference guard); billing goes through
 * the normal allocation and invoice code; settlement uses the normal settlement rules. Nothing here writes a journal entry:
 *
 *  - Revenue reaches the books the way the company already books it — when the client actually pays (cash basis), or as a Board-approved
 *    revenue event (accrual revenue). Billing the client creates a receivable (an invoice), never cash or income by itself.
 *  - Cost reaches the books through the supplier payment and the delivery payment (verified expenses; under the accrual sourcing basis an
 *    advance and capitalised inventory, released by the Board's cost recognition). Completing a lot adds no cost of its own.
 *  - Profit is the calculated difference (LotFinancials), shown and reported, never posted as income.
 *
 * So completing a lot can never count revenue or cost a second time.
 */
class ClientLot
{
    /**
     * The lot's numbers. All amounts are integer paisa; quantity × unit cost is exact (Money::multiply).
     *
     * @return array{base: int, delivery: int, total: int, budget: int, profit: int}
     */
    public static function figures(string $quantity, int $unitCost, int $budget, int $delivery = 0): array
    {
        $base = Money::multiply($quantity, $unitCost);

        return ['base' => $base, 'delivery' => $delivery, 'total' => $base + $delivery, 'budget' => $budget, 'profit' => $budget - $base - $delivery];
    }

    /**
     * Create a lot in the Processing stage. The budget is the lot's agreed revenue, not money received: no payment, invoice or journal is made.
     * Pricing is approved automatically only under the Board's standing policy for ordinary lots (cost within its limit and the budget not
     * below cost); otherwise the lot waits for the normal pricing approval by someone other than its creator.
     *
     * @param  array{supplier_id: int, supplier_product_id: int, project_id: int, quantity: string, unit_cost: int, client_budget: int, purchase_order?: ?string, notes?: ?string}  $data
     */
    public static function create(array $data, User $user): SourcingLot
    {
        $quantity = trim($data['quantity']);
        if (! preg_match('/^[1-9]\d{0,9}$/', $quantity)) {
            throw new RuntimeException('Quantity must be a whole number greater than zero.');
        }
        if ($data['unit_cost'] <= 0) {
            throw new RuntimeException('Enter the unit base cost (above zero).');
        }
        if ($data['client_budget'] <= 0) {
            throw new RuntimeException('Enter the client lot budget (above zero).');
        }

        return DB::transaction(function () use ($data, $quantity, $user) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            if (! $supplier->is_active) {
                throw new RuntimeException('That supplier is archived.');
            }
            $product = SupplierProduct::where('supplier_id', $supplier->id)->find($data['supplier_product_id'])
                ?? throw new RuntimeException('Choose a product supplied by '.$supplier->name.'.');
            if (! $product->is_active) {
                throw new RuntimeException($product->label().' is inactive.');
            }
            $project = Project::with('client')->whereNull('archived_at')->find($data['project_id'])
                ?? throw new RuntimeException('Choose an active client project.');

            $lot = Sourcing::createLot([
                'title' => $product->label().' × '.$quantity.' — '.$project->name, 'allocation_basis' => 'value',
                'purchase_order' => $data['purchase_order'] ?? null, 'project_id' => $project->id, 'notes' => $data['notes'] ?? null,
            ], $user);
            $lot->update(['client_budget' => $data['client_budget']]);
            // The supplier price is snapshotted on the line; a cost different from the catalog price needs the override permission (addLine checks).
            $line = Sourcing::addLine($lot, ['supplier_id' => $supplier->id, 'supplier_product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $data['unit_cost']], $user);

            $f = self::figures($quantity, $data['unit_cost'], $data['client_budget']);
            self::approveBudget($lot, $user);
            Audit::log('sourcing.client_lot.created', 'lot '.$lot->number, 'qty '.$quantity.' unit cost '.$data['unit_cost'].' base '.$f['base'].' budget '.$data['client_budget'].' estimated profit '.$f['profit']);

            return $lot->refresh();
        });
    }

    /**
     * The client budget is a price the admin has already agreed with the client before entering it, so it is the lot's approved price from the
     * start (a budget below cost is allowed: it is an expected loss, shown as such). The admin who entered it is recorded as approver, and the
     * frozen cost/revenue snapshot is taken exactly as a normal pricing approval takes it. Lots priced per line keep their own approval.
     */
    private static function approveBudget(SourcingLot $lot, User $user): void
    {
        $line = $lot->lines()->firstOrFail();
        $unitPrice = intdiv((int) $lot->client_budget, max(1, (int) $line->quantity)); // indicative; the invoice bills the budget itself to the paisa
        $line->update(['sell_price' => $unitPrice, 'approved_price' => $unitPrice]);
        $lot->update(['pricing_method' => 'fixed', 'pricing_value' => 0, 'priced_by' => $lot->priced_by ?? $user->id, 'status' => 'approved',
            'approved_by' => $user->id, 'approved_at' => now(), 'approved_cost' => LotFinancials::for($lot)['landed'], 'approved_revenue' => (int) $lot->client_budget]);
    }

    /**
     * Processing → In order. The delivery charge is confirmed here (zero allowed for free delivery) and recorded as the lot's ordinary
     * Delivery cost, so it is counted once: in the lot's landed cost and, when paid, through its own expense. No profit is recognised.
     */
    public static function markInOrder(SourcingLot $lot, int $delivery, User $user): SourcingLot
    {
        if ($delivery < 0) {
            throw new RuntimeException('The delivery charge cannot be negative (enter 0 for free delivery).');
        }

        return DB::transaction(function () use ($lot, $delivery, $user) {
            $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            self::assertClientLot($lot);
            match ($lot->status) {
                'approved' => null,
                'priced' => self::approveBudget($lot, $user), // a client lot saved before budgets were approved on entry
                'in_progress', 'completed', 'settled' => throw new RuntimeException('This lot is already '.strtolower($lot->stageLabel()).'; nothing was changed.'),
                'cancelled' => throw new RuntimeException('This lot is cancelled.'),
                default => throw new RuntimeException('Finish pricing this lot in the detailed view first.'),
            };
            if ($delivery > 0) {
                Sourcing::addCost($lot, ['category' => 'Delivery', 'description' => 'Delivery charge (confirmed when ordered)', 'amount' => $delivery, 'kind' => 'actual',
                    'incurred_on' => today()->toDateString(), 'planned' => true], $user);
            }
            Sourcing::advance($lot->refresh(), $user); // approved → in_progress
            $lot->refresh()->update(['ordered_at' => now(), 'ordered_by' => $user->id]);
            $f = LotFinancials::for($lot);
            Audit::log('sourcing.client_lot.ordered', 'lot '.$lot->number, 'delivery '.$delivery.' total cost '.$f['landed'].' expected profit '.$f['profit']);

            return $lot;
        });
    }

    /**
     * What stops the lot from being completed now (empty = it can be). Company policy: the supplier is paid in full before goods are received.
     *
     * @return list<string>
     */
    public static function completionBlockers(SourcingLot $lot): array
    {
        $blockers = [];
        if ($lot->status !== 'in_progress') {
            $blockers[] = in_array($lot->status, ['completed', 'settled'], true) ? 'This lot is already completed.' : 'Mark the lot In order first.';

            return $blockers;
        }
        $f = LotFinancials::for($lot);
        if ($f['supplier_outstanding'] > 0) {
            $blockers[] = 'Pay the supplier in full before receiving the goods ('.Money::format($f['supplier_outstanding']).' still to pay).';
        }
        if (! $lot->project_id) {
            $blockers[] = 'Choose the client project of this lot.';
        }

        return $blockers;
    }

    /**
     * In order → Completed, in one transaction:
     *  1. the money the admin confirms has already moved is recorded through the normal payment code — the rest of the supplier price
     *     (company policy: the supplier is paid in full before goods are received) and the unpaid delivery charge — each once;
     *  2. every unit still expected is received into stock (Receiving: stock movement, valuation, a delivery reference that cannot be used twice);
     *  3. the lot is completed. Nothing is invoiced: client lots are independent of client billing. The budget entered by the admin is the
     *     lot's revenue, and the project reports take the completed lot's profit from it directly (ProjectSourcingReport, ProjectEconomics);
     *  4. the lot is financially settled when nothing is left to pay.
     * Any failure rolls everything back. Idempotent: the lot row is locked and its status re-read first, so a second or simultaneous request
     * finds it completed and changes nothing.
     *
     * @param  array{arrived_on: string, reference: string, location_id?: ?int, notes?: ?string, supplier_paid?: bool, delivery_paid?: bool, paid_on?: ?string, payment_reference?: ?string}  $data
     * @return array{lot: SourcingLot, settled: bool, notes: list<string>}
     */
    public static function complete(SourcingLot $lot, array $data, User $user): array
    {
        return DB::transaction(function () use ($lot, $data, $user) {
            $lot = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            self::assertClientLot($lot);
            if (in_array($lot->status, ['completed', 'settled'], true)) {
                throw new RuntimeException('This lot is already completed; nothing was received, billed or posted again.');
            }
            if ($lot->status !== 'in_progress') {
                throw new RuntimeException('Mark the lot In order first.');
            }
            self::recordConfirmedPayments($lot, $data, $user);
            if ($blockers = self::completionBlockers($lot)) {
                throw new RuntimeException($blockers[0]);
            }

            $locationId = ($data['location_id'] ?? null) ?: self::onlyWarehouse();
            foreach ($lot->lines()->get() as $line) {
                $left = Receiving::expected($line);
                if (bccomp($left, '0', 2) > 0) {
                    Receiving::receive($line, $left, $left, '0', $data['arrived_on'], $locationId, $data['reference'], null, $data['notes'] ?? null, $user);
                }
            }
            Sourcing::advance($lot, $user); // in_progress → completed
            $lot->refresh()->update(['completed_at' => now(), 'completed_by' => $user->id]);

            $f = LotFinancials::for($lot->refresh());
            Audit::log('sourcing.client_lot.completed', 'lot '.$lot->number, 'received; total cost '.$f['landed'].' budget '.$f['revenue'].' '.($f['profit'] < 0 ? 'loss '.(-$f['profit']) : 'profit '.$f['profit']));

            $notes = self::followUps($lot, $user);
            $lot->refresh();

            return ['lot' => $lot, 'settled' => $lot->status === 'settled', 'notes' => $notes];
        });
    }

    /**
     * Record, through the normal payment code, the payments the admin confirms have already been made: the rest of the supplier price and the
     * unpaid delivery / lot costs. Each is recorded once — a supplier payment under a key tied to this lot, a cost only while it has no live
     * payment — so retrying never pays twice.
     *
     * @param  array{supplier_paid?: bool, delivery_paid?: bool, paid_on?: ?string, payment_reference?: ?string}  $data
     */
    private static function recordConfirmedPayments(SourcingLot $lot, array $data, User $user): void
    {
        $paidOn = ($data['paid_on'] ?? null) ?: today()->toDateString();
        $reference = trim((string) ($data['payment_reference'] ?? ''));
        if ($data['supplier_paid'] ?? false) {
            $outstanding = LotFinancials::for($lot)['supplier_outstanding'];
            if ($outstanding > 0) {
                SourcingPayments::recordSupplierPayment($lot, ['supplier_id' => (int) $lot->lines()->value('supplier_id'), 'amount' => $outstanding, 'paid_on' => $paidOn,
                    'reference' => $reference ?: null, 'evidence_reference' => $reference ?: null, 'purchase_order' => $lot->purchase_order ?: $lot->number,
                    'notes' => 'Paid in full before receiving (confirmed when the lot was completed)', 'idempotency_key' => 'client-lot-'.$lot->id.'-supplier-'.$outstanding], $user);
            }
        }
        if ($data['delivery_paid'] ?? false) {
            foreach ($lot->costs()->where('kind', 'actual')->with('expense')->get() as $cost) {
                if ($cost->remainingUnpaid() > 0 && ($cost->expense_id === null || $cost->expense?->status === 'rejected')) {
                    SourcingPayments::payCost($cost, $paidOn, $user);
                }
            }
        }
    }

    /**
     * The next governed steps after completion. Each runs in its own savepoint: one that cannot happen yet is reported, not forced, and never
     * undoes the completion. Nothing here is approved on the Board's behalf.
     *
     * @return list<string>
     */
    private static function followUps(SourcingLot $lot, User $user): array
    {
        $notes = [];
        foreach (AdvanceSettlementService::advancePayments($lot)->pluck('supplier_id')->unique() as $supplierId) {
            if (AdvanceSettlementService::unsettled($lot, (int) $supplierId) <= 0 || AdvanceSettlementService::pendingSettlements($lot, (int) $supplierId) > 0) {
                continue;
            }
            try {
                if (AdvanceSettlementService::propose($lot, (int) $supplierId, $user)->isNotEmpty()) {
                    $notes[] = 'The supplier advance settlement was proposed and awaits Board verification.';
                }
            } catch (RuntimeException $e) {
                $notes[] = 'Supplier advance not settled yet: '.$e->getMessage();
            }
        }
        if (! $user->can('sourcing.settle')) {
            return $notes;
        }
        try {
            Sourcing::settle($lot, $user);
        } catch (RuntimeException $e) {
            $notes[] = 'Financial settlement waits: '.$e->getMessage();
        }

        return $notes;
    }

    /**
     * Older records the simple stages cannot vouch for: flagged for a person to review, never guessed or force-posted.
     *
     * @return list<string>
     */
    public static function reviewFlags(SourcingLot $lot): array
    {
        $flags = [];
        if ($lot->stage() === 'completed' && $lot->lines()->whereNull('received_quantity')->exists()) {
            $flags[] = 'Marked completed before the goods received were recorded.';
        }
        if ($lot->stage() !== 'processing' && $lot->stage() !== 'cancelled' && ! $lot->lines()->exists()) {
            $flags[] = 'No products on this lot.';
        }

        return $flags;
    }

    /**
     * The stock location to use when none was chosen: the only active warehouse, when there is exactly one (the first receipt creates it).
     * With several, null is returned and receiving asks for a choice, as it always has.
     */
    private static function onlyWarehouse(): ?int
    {
        $ids = StockLocation::where('is_active', true)->where('code', '!=', 'QUAR')->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private static function assertClientLot(SourcingLot $lot): void
    {
        if (! $lot->hasClientBudget()) {
            throw new RuntimeException('This lot was not made on the simple client-lot form; manage it from the detailed view.');
        }
    }
}
