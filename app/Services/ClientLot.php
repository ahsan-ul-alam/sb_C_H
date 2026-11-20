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
 * The simple client lot, controlled by the admin: one supplier product, a quantity, a unit base cost and a client budget agreed for the whole
 * lot, moved through exactly three stages — Processing → In order → Completed. Changes afterwards go through ClientLotCorrection ("Edit lot").
 *
 * It is a thin layer over the existing sourcing records, never a parallel engine. A lot is an ordinary SourcingLot with one line; the delivery
 * charge is an ordinary lot cost; receiving goes through Receiving (stock movement, duplicate-reference guard). The workflow is independent of
 * payments, advances, approvals, invoices and settlement: no step requires, records or proposes any of them, and nothing here writes a journal
 * entry, an expense, an invoice or a cash movement. Payments are entered separately by the admins in the existing financial screens.
 *
 *   Total base cost = quantity × unit base cost; total lot cost = base cost + delivery charge; profit or loss = client budget − total lot cost.
 * Profit is calculated (LotFinancials), shown and reported, never posted as income.
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
     * Create a lot in the Processing stage, with the unit base cost the admin entered. The budget is the lot's agreed revenue, not money
     * received: no payment, invoice or journal is made, and nothing waits for an approval.
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
            // The admin's unit base cost is the lot's cost as entered (the catalog price is only suggested on the form).
            $lot->lines()->create(['supplier_id' => $supplier->id, 'supplier_product_id' => $product->id, 'description' => $product->label(), 'unit' => $product->unit ?: 'pcs',
                'quantity' => $quantity, 'usable_quantity' => $quantity, 'unit_price' => $data['unit_cost'], 'reference_price' => (int) $product->reference_price, 'position' => 1]);

            $f = self::figures($quantity, $data['unit_cost'], $data['client_budget']);
            self::approveBudget($lot, $user);
            Audit::log('sourcing.client_lot.created', 'lot '.$lot->number, 'qty '.$quantity.' unit cost '.$data['unit_cost'].' base '.$f['base'].' budget '.$data['client_budget'].' estimated profit '.$f['profit']);

            return $lot->refresh();
        });
    }

    /**
     * The client budget is a price the admin already agreed with the client, so it is the lot's price from the start — no approval step (a budget
     * below cost is allowed: it is an expected loss, shown as such). The status stays in the Processing stage; the cost/revenue snapshot is kept
     * so the existing reports read the lot like any other.
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
     * What stops the lot from being completed now (empty = it can be). Only the stage matters: payments, advances and approvals never do.
     *
     * @return list<string>
     */
    public static function completionBlockers(SourcingLot $lot): array
    {
        if ($lot->status !== 'in_progress') {
            return [in_array($lot->status, ['completed', 'settled'], true) ? 'This lot is already completed.' : 'Mark the lot In order first.'];
        }

        return $lot->project_id ? [] : ['Choose the client project of this lot.'];
    }

    /**
     * In order → Completed, in one transaction: every unit still expected is received into stock through the existing receiving (stock movement,
     * a delivery reference that cannot be used twice), and the lot is completed. Nothing else happens: no payment, advance, settlement, invoice,
     * expense or journal entry is created, and none is required. The final profit is the client budget minus the total lot cost.
     * Any failure rolls everything back. Idempotent: the lot row is locked and its status re-read first, so a second or simultaneous request
     * finds it completed and changes nothing.
     *
     * @param  array{arrived_on: string, reference: string, location_id?: ?int, notes?: ?string}  $data
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

            return ['lot' => $lot->refresh(), 'settled' => false, 'notes' => []];
        });
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
