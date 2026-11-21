<?php

namespace App\Http\Controllers;

use App\Models\SourcingLot;
use App\Services\ClientLot;
use App\Services\ClientLotCorrection;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The simple client-lot workflow: create (Processing) → Mark In order (delivery charge) → Mark as Completed (goods received).
 * Every rule lives in ClientLot; this controller only validates input and reports the outcome.
 */
class ClientLotController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // The form sends one row per product (items[]); a single product sent as plain fields is still accepted.
        if (! $request->has('items')) {
            $single = $request->validate([
                'supplier_product_id' => ['required', 'integer', 'exists:supplier_products,id'],
                'quantity' => ['required', 'regex:/^[1-9]\d{0,9}$/'],
                'unit_cost' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            ], [
                'quantity.regex' => 'Quantity must be a whole number greater than zero.',
                'unit_cost.regex' => 'Enter the unit base cost as an amount, e.g. 150 or 150.50.',
            ]);
            $request->merge(['items' => [$single]]);
        }
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.supplier_product_id' => ['required', 'integer', 'distinct', 'exists:supplier_products,id'],
            'items.*.quantity' => ['required', 'regex:/^[1-9]\d{0,9}$/'],
            'items.*.unit_cost' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'client_budget' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'purchase_order' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'submission_key' => ['nullable', 'uuid'],
        ], [
            'items.required' => 'Add at least one product.',
            'items.*.supplier_product_id.required' => 'Choose a product on every row.',
            'items.*.supplier_product_id.distinct' => 'Each product can be added only once; change its quantity instead.',
            'items.*.quantity.required' => 'Enter a quantity on every row.',
            'items.*.quantity.regex' => 'Quantity must be a whole number greater than zero.',
            'items.*.unit_cost.required' => 'Enter the unit base cost on every row.',
            'items.*.unit_cost.regex' => 'Enter the unit base cost as an amount, e.g. 150 or 150.50.',
            'client_budget.regex' => 'Enter the client lot budget as an amount, e.g. 20000.',
        ]);

        // A double click or a retried form must not create a second lot: the form's key is remembered for ten minutes.
        $key = 'client-lot:'.($data['submission_key'] ?? (string) str()->uuid());
        try {
            return Cache::lock($key.':lock', 10)->block(5, function () use ($data, $key, $request) {
                if ($existing = Cache::get($key)) {
                    return redirect()->route('sourcing.lots.show', $existing)->with('warning', 'This lot was already saved; nothing was duplicated.');
                }
                $lot = ClientLot::create([
                    'supplier_id' => (int) $data['supplier_id'], 'project_id' => (int) $data['project_id'],
                    'items' => array_map(fn (array $item) => [
                        'supplier_product_id' => (int) $item['supplier_product_id'], 'quantity' => $item['quantity'], 'unit_cost' => Money::toMinor($item['unit_cost']),
                    ], array_values($data['items'])),
                    'client_budget' => Money::toMinor($data['client_budget']),
                    'purchase_order' => $data['purchase_order'] ?? null, 'notes' => $data['notes'] ?? null,
                ], $request->user());
                Cache::put($key, $lot->id, 600);

                return redirect()->route('sourcing.lots.show', $lot)->with('status', 'Lot '.$lot->number.' created and is now Processing.');
            });
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function order(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['delivery_charge' => ['required', 'regex:'.Money::AMOUNT_REGEX]], [
            'delivery_charge.required' => 'Enter the delivery charge (0 for free delivery).',
            'delivery_charge.regex' => 'Enter the delivery charge as an amount, e.g. 800 or 0.',
        ]);

        return $this->attempt(fn () => ClientLot::markInOrder($lot, Money::toMinor($data['delivery_charge']), $request->user()), 'Lot is In order — awaiting product receiving.');
    }

    public function complete(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate([
            'arrived_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'max:120'],
            'location_id' => ['nullable', 'integer', 'exists:stock_locations,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['reference.required' => 'Enter the delivery note or GRN number so the goods cannot be received twice.']);

        return $this->attempt(fn () => ClientLot::complete($lot, $data, $request->user()), 'Lot completed: goods received into stock.');
    }

    /** Edit lot: an admin changes anything at any stage; the figures, project profitability and stock follow (ClientLotCorrection). */
    public function correct(Request $request, SourcingLot $lot): RedirectResponse
    {
        // One row per product (lines[line id][…]); a one-product lot may still send the plain fields.
        $perLine = $request->has('lines');
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'supplier_product_id' => [Rule::requiredIf(! $perLine), 'integer', 'exists:supplier_products,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'quantity' => [Rule::requiredIf(! $perLine), 'regex:/^[1-9]\d{0,9}$/'],
            'unit_cost' => [Rule::requiredIf(! $perLine), 'regex:'.Money::AMOUNT_REGEX],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.supplier_product_id' => ['required', 'integer', 'distinct', 'exists:supplier_products,id'],
            'lines.*.quantity' => ['required', 'regex:/^[1-9]\d{0,9}$/'],
            'lines.*.unit_cost' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'client_budget' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'delivery_charge' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'stage' => ['required', 'in:'.implode(',', array_keys(ClientLotCorrection::STAGE_STATUS))],
            'arrived_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
            'location_id' => ['nullable', 'integer', 'exists:stock_locations,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'quantity.regex' => 'Quantity must be a whole number greater than zero.',
            'lines.*.quantity.regex' => 'Quantity must be a whole number greater than zero.',
            'lines.*.unit_cost.regex' => 'Enter the unit base cost as an amount, e.g. 150 or 150.50.',
            'lines.*.supplier_product_id.distinct' => 'Each product can be on the lot only once; change its quantity instead.',
        ]);
        $products = $perLine
            ? collect($data['lines'])->map(fn (array $line) => ['supplier_product_id' => (int) $line['supplier_product_id'], 'quantity' => $line['quantity'], 'unit_cost' => Money::toMinor($line['unit_cost'])])->all()
            : null;

        try {
            $changes = ClientLotCorrection::apply($lot, [
                'supplier_id' => (int) $data['supplier_id'], 'project_id' => (int) $data['project_id'],
                ...($products !== null ? ['lines' => $products] : ['supplier_product_id' => (int) $data['supplier_product_id'], 'quantity' => $data['quantity'], 'unit_cost' => Money::toMinor($data['unit_cost'])]),
                'client_budget' => Money::toMinor($data['client_budget']),
                'delivery_charge' => Money::toMinor($data['delivery_charge']), 'stage' => $data['stage'], 'arrived_on' => $data['arrived_on'] ?? null,
                'reference' => $data['reference'] ?? null, 'location_id' => isset($data['location_id']) ? (int) $data['location_id'] : null,
                'notes' => $data['notes'] ?? null, 'reason' => $data['reason'] ?? null,
            ], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('status', 'Lot updated: '.implode('; ', $changes).'.');
    }
}
