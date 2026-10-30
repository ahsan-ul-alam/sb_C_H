<?php

namespace App\Services;

use App\Models\EmergencyFundRequest;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Segment;
use App\Models\SourcingAllocation;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SourcingLotLine;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Support\Costing;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lot operations: lines, cost segments, pricing, client allocation/billing and lifecycle.
 * Every mutation runs in a transaction under a lock on the lot row, rejects closed lots, and is audited.
 */
class Sourcing
{
    /**
     * @param  Closure(SourcingLot): mixed  $work
     */
    private static function locked(SourcingLot $lot, Closure $work, bool $allowClosed = false): mixed
    {
        return DB::transaction(function () use ($lot, $work, $allowClosed) {
            $fresh = SourcingLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (! $allowClosed && $fresh->isClosed()) {
                throw new RuntimeException('This lot is '.strtolower($fresh->statusLabel()).' and can no longer be changed.');
            }

            return $work($fresh);
        });
    }

    /** Editing numbers under a priced lot sends it back to costing so the price is re-confirmed. */
    private static function reopenPricing(SourcingLot $lot): void
    {
        if ($lot->status === 'priced') {
            $lot->update(['status' => 'costing', 'priced_by' => null]);
        }
    }

    private static function log(SourcingLot $lot, string $action, ?string $details = null): void
    {
        Audit::log('sourcing.'.$action, 'lot '.$lot->number, $details);
    }

    /**
     * @param  array{title: string, allocation_basis?: ?string, notes?: ?string}  $data
     */
    public static function createLot(array $data, User $user): SourcingLot
    {
        return DB::transaction(function () use ($data, $user) {
            $lot = SourcingLot::create([
                'number' => SourcingLot::nextNumber(), 'title' => $data['title'],
                'allocation_basis' => $data['allocation_basis'] ?? 'value', 'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            self::log($lot, 'lot.created');

            return $lot;
        });
    }

    /**
     * Add a product line. The supplier price is SNAPSHOT here: later catalog changes never touch it.
     *
     * @param  array{supplier_id: int, supplier_product_id?: ?int, description?: ?string, unit?: ?string, quantity: string, usable_quantity?: ?string, unit_price?: ?int}  $data
     */
    public static function addLine(SourcingLot $lot, array $data, User $user): SourcingLotLine
    {
        return self::locked($lot, function (SourcingLot $lot) use ($data, $user) {
            self::assertLinesEditable($lot);
            $product = ! empty($data['supplier_product_id']) ? SupplierProduct::findOrFail($data['supplier_product_id']) : null;
            if ($product && $product->supplier_id !== (int) $data['supplier_id']) {
                throw new RuntimeException('That product belongs to a different supplier.');
            }
            if ($product && ! $product->is_active) {
                throw new RuntimeException('That catalog product is inactive.');
            }
            $reference = $product?->reference_price ?? 0;
            $price = $data['unit_price'] ?? null;
            if ($price !== null && $price !== $reference && ! $user->can('sourcing.override')) {
                throw new RuntimeException('You are not allowed to override the supplier price for a lot.');
            }
            $price ??= $reference;
            $description = $data['description'] ?? null ?: $product?->label();
            if (! $description) {
                throw new RuntimeException('Choose a catalog product or enter a description.');
            }
            self::assertQuantity($data['quantity'], $data['usable_quantity'] ?? $data['quantity']);

            $line = $lot->lines()->create([
                'supplier_id' => $data['supplier_id'], 'supplier_product_id' => $product?->id, 'description' => $description,
                'unit' => ($data['unit'] ?? null) ?: $product?->unit ?: 'pcs', 'quantity' => $data['quantity'],
                'usable_quantity' => $data['usable_quantity'] ?? $data['quantity'], 'unit_price' => $price,
                'reference_price' => $reference, 'position' => (int) $lot->lines()->max('position') + 1,
            ]);
            self::reopenPricing($lot);
            self::log($lot, 'line.added', $description.' x'.$data['quantity'].' @'.$price);

            return $line;
        });
    }

    /**
     * @param  array{quantity: string, usable_quantity: string, unit_price?: ?int}  $data
     */
    public static function updateLine(SourcingLotLine $line, array $data, User $user): void
    {
        self::locked($line->lot, function (SourcingLot $lot) use ($line, $data, $user) {
            self::assertLinesEditable($lot);
            self::assertQuantity($data['quantity'], $data['usable_quantity']);
            $line = $lot->lines()->findOrFail($line->id);
            $price = $data['unit_price'] ?? $line->unit_price;
            if ($price !== $line->unit_price && ! $user->can('sourcing.override')) {
                throw new RuntimeException('You are not allowed to override the supplier price for a lot.');
            }
            if (bccomp($data['usable_quantity'], (string) $line->usable_quantity, 2) < 0
                && bccomp((string) $lot->allocations()->where('sourcing_lot_line_id', $line->id)->sum('quantity'), $data['usable_quantity'], 2) > 0) {
                throw new RuntimeException('Usable quantity cannot drop below the quantity already allocated to clients.');
            }
            $line->update(['quantity' => $data['quantity'], 'usable_quantity' => $data['usable_quantity'], 'unit_price' => $price]);
            self::reopenPricing($lot);
            self::log($lot, 'line.updated', $line->description.' x'.$data['quantity'].' usable '.$data['usable_quantity'].' @'.$price);
        });
    }

    public static function removeLine(SourcingLotLine $line): void
    {
        self::locked($line->lot, function (SourcingLot $lot) use ($line) {
            self::assertLinesEditable($lot);
            $line = $lot->lines()->findOrFail($line->id);
            if ($lot->allocations()->where('sourcing_lot_line_id', $line->id)->exists() || $lot->costs()->where('sourcing_lot_line_id', $line->id)->exists()
                || $lot->payments()->where('supplier_id', $line->supplier_id)->whereNull('voided_at')->exists()) {
                throw new RuntimeException('This line has costs, client allocations or supplier payments and cannot be removed.');
            }
            $line->delete();
            self::reopenPricing($lot);
            self::log($lot, 'line.removed', $line->description);
        });
    }

    private static function assertLinesEditable(SourcingLot $lot): void
    {
        if (! $lot->linesEditable()) {
            throw new RuntimeException('Quantities and supplier prices are locked once pricing is approved. Add an actual cost or an adjustment instead.');
        }
    }

    private static function assertQuantity(string $quantity, string $usable): void
    {
        if (bccomp($quantity, '0', 2) <= 0 || bccomp($usable, '0', 2) <= 0) {
            throw new RuntimeException('Quantities must be greater than zero.');
        }
        if (bccomp($usable, $quantity, 2) > 0) {
            throw new RuntimeException('Usable quantity cannot exceed the purchased quantity.');
        }
    }

    /**
     * Add a cost segment. A cost added after pricing approval is automatically flagged unexpected.
     * With $replacesId it records the ACTUAL for an estimate, which then stops counting (never both).
     *
     * @param  array{category: string, description?: ?string, amount: int, kind: string, incurred_on: string, line_id?: ?int, payee?: ?string, reference?: ?string, is_unexpected?: bool, replaces_id?: ?int}  $data
     */
    public static function addCost(SourcingLot $lot, array $data, User $user): SourcingLotCost
    {
        return self::locked($lot, function (SourcingLot $lot) use ($data, $user) {
            if ($data['amount'] <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            if (! empty($data['line_id']) && ! $lot->lines()->whereKey($data['line_id'])->exists()) {
                throw new RuntimeException('That product line is not part of this lot.');
            }
            $replacesId = $data['replaces_id'] ?? null;
            if ($replacesId) {
                $estimate = $lot->costs()->where('kind', 'estimate')->findOrFail($replacesId);
                if ($lot->costs()->where('replaces_id', $estimate->id)->exists()) {
                    throw new RuntimeException('This estimate already has an actual cost recorded.');
                }
                if ($data['kind'] !== 'actual') {
                    throw new RuntimeException('Only an actual cost can replace an estimate.');
                }
                $data['category'] = $estimate->category;
                $data['line_id'] ??= $estimate->sourcing_lot_line_id;
            }
            $afterApproval = in_array($lot->status, ['approved', 'in_progress', 'completed'], true);

            $cost = $lot->costs()->create([
                'sourcing_lot_line_id' => $data['line_id'] ?? null, 'replaces_id' => $replacesId, 'category' => $data['category'],
                'description' => $data['description'] ?? null, 'amount' => $data['amount'], 'kind' => $data['kind'],
                'is_unexpected' => ($data['is_unexpected'] ?? false) || ($afterApproval && ! $replacesId && $data['kind'] === 'actual'),
                'incurred_on' => $data['incurred_on'], 'payee' => $data['payee'] ?? null, 'reference' => $data['reference'] ?? null,
                'created_by' => $user->id,
            ]);
            self::reopenPricing($lot);
            self::log($lot, 'cost.added', $cost->kind.' '.$cost->category.' '.$cost->amount.($cost->is_unexpected ? ' (unexpected)' : ''));

            return $cost;
        });
    }

    public static function removeCost(SourcingLotCost $cost): void
    {
        self::locked($cost->lot, function (SourcingLot $lot) use ($cost) {
            $cost = $lot->costs()->findOrFail($cost->id);
            if ($cost->isPaid()) {
                throw new RuntimeException('This cost has been paid and is part of the books; it cannot be removed.');
            }
            if ($lot->costs()->where('replaces_id', $cost->id)->exists()) {
                throw new RuntimeException('Remove the actual cost that replaced this estimate first.');
            }
            $cost->delete();
            self::reopenPricing($lot);
            self::log($lot, 'cost.removed', $cost->category.' '.$cost->amount);
        });
    }

    /**
     * Save pricing for every line: the method applies to the true landed unit cost, with optional per-line manual
     * overrides (minor units). Prices below break-even are allowed to be PROPOSED but cannot be approved without
     * the negative-margin permission.
     *
     * @param  array<int, ?int>  $overrides  line id => manual unit price
     */
    public static function savePricing(SourcingLot $lot, string $method, int $value, array $overrides, User $user): void
    {
        self::locked($lot, function (SourcingLot $lot) use ($method, $value, $overrides, $user) {
            if (! $lot->linesEditable()) {
                throw new RuntimeException('Prices are approved and locked. Billing uses the approved snapshot.');
            }
            if (array_filter($overrides, fn ($p) => $p !== null) && ! $user->can('sourcing.override')) {
                throw new RuntimeException('You are not allowed to set manual selling prices.');
            }
            $figures = LotFinancials::for($lot);
            foreach ($figures['lines'] as $id => $f) {
                $price = $overrides[$id] ?? Costing::price($f['unit_cost'], $method, $value);
                $f['line']->update(['sell_price' => $price]);
            }
            $lot->update(['pricing_method' => $method, 'pricing_value' => $value]);
            self::reopenPricing($lot);
            self::log($lot, 'pricing.saved', $method.' '.$value);
        });
    }

    /**
     * Move one step forward. Approval and settlement have their own guarded methods.
     */
    public static function advance(SourcingLot $lot, User $user): void
    {
        self::locked($lot, function (SourcingLot $lot) use ($user) {
            $next = match ($lot->status) {
                'draft' => 'costing',
                'costing' => 'priced',
                'approved' => 'in_progress',
                'in_progress' => 'completed',
                default => throw new RuntimeException('Use the approval or settlement action for this step.'),
            };
            if ($next === 'costing' && ! $lot->lines()->exists()) {
                throw new RuntimeException('Add at least one product line first.');
            }
            if ($next === 'priced') {
                if ($lot->lines()->whereNull('sell_price')->exists()) {
                    throw new RuntimeException('Save pricing so every line has a client price first.');
                }
                $lot->priced_by = $user->id;
            }
            $lot->status = $next;
            $lot->save();
            self::log($lot, 'status', $next);
        });
    }

    /**
     * Approve client pricing: freezes each line's price and the cost/revenue snapshot used for variance.
     */
    public static function approvePricing(SourcingLot $lot, User $user): void
    {
        self::locked($lot, function (SourcingLot $lot) use ($user) {
            if ($lot->status !== 'priced') {
                throw new RuntimeException('Only a priced lot can be approved.');
            }
            if ($lot->priced_by === $user->id) {
                throw new RuntimeException('You cannot approve pricing you submitted.');
            }
            $figures = LotFinancials::for($lot);
            $below = collect($figures['lines'])->filter(fn ($f) => $f['price'] < $f['break_even']);
            if ($below->isNotEmpty() && ! $user->can('sourcing.negative_margin')) {
                throw new RuntimeException('A price is below break-even; only a Board member may approve a negative margin.');
            }
            foreach ($figures['lines'] as $f) {
                $f['line']->update(['approved_price' => $f['price']]);
            }
            $lot->update([
                'status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(),
                'approved_cost' => $figures['landed'], 'approved_revenue' => $figures['sales_value'],
            ]);
            self::log($lot, 'pricing.approved', 'cost '.$figures['landed'].' sales '.$figures['sales_value'].($below->isNotEmpty() ? ' NEGATIVE MARGIN' : ''));
        });
    }

    /**
     * Agree quantities (or a cost recovery) with a client project. Cannot exceed the usable quantity of a line.
     *
     * @param  array{project_id: int, line_id?: ?int, kind: string, quantity?: ?string, unit_price?: ?int, description?: ?string}  $data
     */
    public static function allocate(SourcingLot $lot, array $data, User $user): SourcingAllocation
    {
        return self::locked($lot, function (SourcingLot $lot) use ($data, $user) {
            if (! in_array($lot->status, ['approved', 'in_progress', 'completed'], true)) {
                throw new RuntimeException('Pricing must be approved before a lot is allocated to a client.');
            }
            $project = Project::findOrFail($data['project_id']);
            if ($data['kind'] === 'recovery') {
                $amount = (int) $data['unit_price'];
                if ($amount <= 0) {
                    throw new RuntimeException('Enter the agreed recovery amount.');
                }

                return $lot->allocations()->create([
                    'project_id' => $project->id, 'kind' => 'recovery', 'description' => $data['description'] ?: 'Additional sourcing cost recovery',
                    'quantity' => 1, 'unit_price' => $amount, 'created_by' => $user->id,
                ]);
            }
            $line = $lot->lines()->findOrFail($data['line_id']);
            $quantity = $data['quantity'];
            if (bccomp($quantity, '0', 2) <= 0) {
                throw new RuntimeException('Quantity must be greater than zero.');
            }
            $already = (string) $lot->allocations()->where('kind', 'sale')->where('sourcing_lot_line_id', $line->id)->sum('quantity');
            if (bccomp(bcadd($already, $quantity, 2), (string) $line->usable_quantity, 2) > 0) {
                throw new RuntimeException('Only '.bcsub((string) $line->usable_quantity, $already, 2).' '.$line->unit.' of '.$line->description.' remain unallocated.');
            }
            $price = $data['unit_price'] ?? $line->approved_price;
            if ($price !== $line->approved_price && ! $user->can('sourcing.price.approve')) {
                throw new RuntimeException('Only a pricing approver can agree a price different from the approved one.');
            }
            self::log($lot, 'allocated', $line->description.' x'.$quantity.' to project '.$project->id);

            return $lot->allocations()->create([
                'sourcing_lot_line_id' => $line->id, 'project_id' => $project->id, 'kind' => 'sale',
                'description' => $line->description, 'quantity' => $quantity, 'unit_price' => $price, 'created_by' => $user->id,
            ]);
        });
    }

    public static function removeAllocation(SourcingAllocation $allocation): void
    {
        self::locked($allocation->lot, function (SourcingLot $lot) use ($allocation) {
            $allocation = $lot->allocations()->findOrFail($allocation->id);
            if ($allocation->billed_invoice_id) {
                throw new RuntimeException('This allocation is already on an invoice; adjust it through the invoice.');
            }
            $allocation->delete();
            self::log($lot, 'allocation.removed', $allocation->description);
        });
    }

    /**
     * Put an allocation on the client's EXISTING billing: a segment (new, or an existing one of the project) and an
     * invoice item (on a new invoice, or an open invoice of the project). Only the client-facing description,
     * quantity and price are copied; supplier prices, costs and margins never leave this module. Idempotent.
     */
    public static function bill(SourcingAllocation $allocation, ?int $segmentId, ?int $invoiceId, User $user): Invoice
    {
        return self::locked($allocation->lot, function (SourcingLot $lot) use ($allocation, $segmentId, $invoiceId) {
            $allocation = $lot->allocations()->lockForUpdate()->findOrFail($allocation->id);
            if ($allocation->billed_invoice_id) {
                throw new RuntimeException('This allocation has already been billed.');
            }
            $project = Project::findOrFail($allocation->project_id);
            $amount = $allocation->amount();

            [$invoice, $segment] = ClientBilling::addCharge($project, 'Product sourcing — '.$lot->number, $lot->title, $segmentId, $invoiceId, 'Product sourcing',
                $allocation->description, (string) $allocation->quantity, $allocation->line?->unit, $allocation->unit_price, $amount);

            $allocation->update(['segment_id' => $segment->id, 'billed_invoice_id' => $invoice->id, 'billed_at' => now()]);
            self::log($lot, 'billed', $allocation->description.' '.$amount.' on '.$invoice->number);

            return $invoice;
        });
    }

    public static function cancel(SourcingLot $lot, string $reason): void
    {
        self::locked($lot, function (SourcingLot $lot) use ($reason) {
            $blocked = $lot->payments()->whereNull('voided_at')->exists()
                || $lot->allocations()->whereNotNull('billed_invoice_id')->exists()
                || $lot->fundRequests()->whereIn('status', EmergencyFundRequest::LIVE)->exists()
                || $lot->costs()->whereNotNull('expense_id')->get()->contains(fn ($c) => $c->isPaid());
            if ($blocked) {
                throw new RuntimeException('A lot with supplier payments, paid costs, invoices or fund requests cannot be cancelled. Reverse those first.');
            }
            $lot->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            self::log($lot, 'cancelled', $reason);
        });
    }

    /**
     * Financial settlement: costs and supplier bills are paid and no Emergency Fund request is open.
     */
    public static function settle(SourcingLot $lot, User $user): void
    {
        self::locked($lot, function (SourcingLot $lot) use ($user) {
            if ($lot->status !== 'completed') {
                throw new RuntimeException('Complete the lot before settling it.');
            }
            $f = LotFinancials::for($lot);
            if ($f['supplier_outstanding'] > 0 || $f['costs_unpaid'] > 0 || $f['estimates_open'] > 0) {
                throw new RuntimeException('Supplier payables, unpaid costs and open estimates must be cleared before settlement.');
            }
            if ($lot->fundRequests()->whereIn('status', ['pending', 'approved'])->exists()) {
                throw new RuntimeException('Resolve the open Emergency Fund request first.');
            }
            $lot->update(['status' => 'settled', 'settled_by' => $user->id, 'settled_at' => now()]);
            self::log($lot, 'settled', 'profit '.$f['profit']);
        });
    }

    /** Audited correction path for a settled lot: reopen with a reason, adjust, settle again. */
    public static function reopen(SourcingLot $lot, string $reason): void
    {
        self::locked($lot, function (SourcingLot $lot) use ($reason) {
            if ($lot->status !== 'settled') {
                throw new RuntimeException('Only a settled lot can be reopened.');
            }
            $lot->update(['status' => 'completed', 'settled_by' => null, 'settled_at' => null]);
            self::log($lot, 'reopened', $reason);
        }, true);
    }
}
