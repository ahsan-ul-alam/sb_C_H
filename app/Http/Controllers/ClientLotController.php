<?php

namespace App\Http\Controllers;

use App\Models\SourcingLot;
use App\Services\ClientLot;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The simple client-lot workflow: create (Processing) → Mark In order (delivery charge) → Mark as Completed (goods received).
 * Every rule lives in ClientLot; this controller only validates input and reports the outcome.
 */
class ClientLotController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'supplier_product_id' => ['required', 'integer', 'exists:supplier_products,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'quantity' => ['required', 'regex:/^[1-9]\d{0,9}$/'],
            'unit_cost' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'client_budget' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'purchase_order' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'submission_key' => ['nullable', 'uuid'],
        ], [
            'quantity.regex' => 'Quantity must be a whole number greater than zero.',
            'unit_cost.regex' => 'Enter the unit base cost as an amount, e.g. 150 or 150.50.',
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
                    'supplier_id' => (int) $data['supplier_id'], 'supplier_product_id' => (int) $data['supplier_product_id'], 'project_id' => (int) $data['project_id'],
                    'quantity' => $data['quantity'], 'unit_cost' => Money::toMinor($data['unit_cost']), 'client_budget' => Money::toMinor($data['client_budget']),
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
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ], ['reference.required' => 'Enter the delivery note or GRN number so the goods cannot be received twice.']);

        try {
            $result = ClientLot::complete($lot, $data + ['supplier_paid' => $request->boolean('supplier_paid'), 'delivery_paid' => $request->boolean('delivery_paid')], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
        $message = 'Lot completed: goods received into stock'.($result['settled'] ? ' and financially settled.' : '.');

        $response = back()->with('status', $message);

        return $result['notes'] ? $response->with('warning', implode(' ', $result['notes'])) : $response;
    }
}
