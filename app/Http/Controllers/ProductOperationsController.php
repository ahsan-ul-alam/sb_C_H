<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\ClientOrderItem;
use App\Models\CommissionAdjustment;
use App\Models\FinancialAccount;
use App\Models\OrderRefund;
use App\Models\OrderRefundAllocation;
use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\StockMovement;
use App\Models\StorageCharge;
use App\Models\Supplier;
use App\Services\Audit;
use App\Services\ClientSettlementService;
use App\Services\Inventory;
use App\Services\OrderFulfilment;
use App\Services\OrderRefunds;
use App\Services\StorageBilling;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Inventory, orders & delivery and client settlements. Thin on purpose: every rule lives in Inventory, OrderFulfilment,
 * ClientSettlementService and StorageBilling, so the screens cannot disagree with the books.
 */
class ProductOperationsController extends Controller
{
    private const QTY = '/^\d{1,10}(\.\d{1,2})?$/';

    public const CHANNELS = ['website' => 'Website', 'facebook' => 'Facebook / social', 'phone' => 'Phone', 'marketplace' => 'Marketplace', 'walk_in' => 'Walk-in', 'other' => 'Other'];

    public const PAYMENT_METHODS = ['cod' => 'Cash on delivery', 'bank' => 'Bank transfer', 'mobile' => 'Mobile banking', 'prepaid' => 'Prepaid'];

    public const DELIVERY_METHODS = ['courier' => 'Courier', 'own' => 'Own delivery', 'pickup' => 'Customer pickup'];

    public function inventory(Request $request): View
    {
        $lines = SourcingLotLine::query()->whereNotNull('received_quantity')->with(['lot.project.client', 'supplier'])
            ->when($request->integer('client'), fn ($q, $id) => $q->whereHas('lot.project', fn ($p) => $p->where('client_id', $id)))
            ->when($request->integer('supplier'), fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->integer('lot'), fn ($q, $id) => $q->where('sourcing_lot_id', $id))
            ->when(trim((string) $request->query('product')) !== '', fn ($q) => $q->where('description', 'like', '%'.trim((string) $request->query('product')).'%'))
            ->orderByDesc('arrived_on')->orderByDesc('id')->get()
            ->map(fn ($l) => ['line' => $l, 'b' => Inventory::balances($l)])
            ->when($request->query('availability') === 'in_stock', fn ($c) => $c->filter(fn ($r) => bccomp($r['b']['available'], '0', 2) > 0))
            ->when($request->query('availability') === 'out', fn ($c) => $c->filter(fn ($r) => bccomp($r['b']['available'], '0', 2) <= 0))->values();

        $stockOrders = ClientOrder::where('fulfilment', 'stock')->with(['items', 'collections'])->get();
        $clients = Client::orderBy('name')->get();

        return view('product.inventory', [
            'rows' => $lines, 'clients' => $clients, 'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'cards' => [
                'available' => $lines->sum(fn ($r) => (float) $r['b']['available']),
                'pending' => $stockOrders->where('delivery_status', 'pending')->count(),
                'confirmed' => $stockOrders->whereIn('delivery_status', ['confirmed', 'dispatched'])->count(),
                'awaiting_collection' => (int) $stockOrders->where('delivery_status', 'delivered')->sum(fn ($o) => OrderFulfilment::collectionState($o)['outstanding']),
                'settlement_due' => (int) $clients->sum(fn ($c) => max(0, ClientSettlementService::fundsHeld($c))),
                'storage_due' => (int) StorageCharge::where('status', 'billed')->with('invoice')->get()->sum(fn ($s) => $s->invoice?->balance() ?? 0),
                'needs_measurement' => StorageCharge::where('status', 'needs_measurement')->count(),
                'unowned' => SourcingLot::whereNull('ownership')->whereHas('lines', fn ($l) => $l->whereNotNull('received_quantity'))->count(),
            ],
        ]);
    }

    public function inventoryShow(SourcingLotLine $line): View
    {
        $line->load(['lot.project.client', 'supplier']);

        return view('product.inventory-show', [
            'line' => $line, 'b' => Inventory::balances($line), 'reconciles' => Inventory::reconciles($line),
            'movements' => StockMovement::where('sourcing_lot_line_id', $line->id)->with('creator')->latest('id')->limit(100)->get(),
            'charges' => StorageCharge::where('sourcing_lot_id', $line->sourcing_lot_id)->with('invoice')->orderByDesc('period')->get(),
            'settings' => BusinessSetting::current(),
        ]);
    }

    public function ownership(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['ownership' => ['required', Rule::in(['client', 'company'])]]);

        return $this->attempt(function () use ($lot, $data) {
            if ($lot->ownership !== null) {
                throw new RuntimeException('Ownership of this stock is already recorded and cannot be changed here (it decides how its sales are accounted).');
            }
            if ($lot->ownershipRequests()->exists()) {
                throw new RuntimeException('Ownership of this lot is being settled through an evidence-backed request (Finance → Historical Reconciliation); it cannot be set directly.');
            }
            if ($data['ownership'] === 'client' && ! $lot->project_id) {
                throw new RuntimeException('Choose the client project this lot is for first (on the lot, under Optional details).');
            }
            $lot->update(['ownership' => $data['ownership']]);
            Audit::log('sourcing.ownership', 'lot '.$lot->number, $data['ownership']);
        }, 'Ownership recorded.');
    }

    public function area(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['storage_sqft' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/']]);

        return $this->attempt(function () use ($lot, $data) {
            $lot->update(['storage_sqft' => $data['storage_sqft'], 'storage_measured_on' => BusinessDate::today()]);
            Audit::log('storage.area', 'lot '.$lot->number, $data['storage_sqft'].' sq ft');
        }, 'Occupied area saved. It is used for storage charges from now on.');
    }

    public function adjust(Request $request, SourcingLotLine $line): RedirectResponse
    {
        $data = $request->validate(['delta' => ['required', 'regex:/^-?\d{1,10}(\.\d{1,2})?$/'], 'reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => Inventory::adjust($line, $data['delta'], $data['reason'], $request->user(), $request->boolean('damaged')), 'Stock adjusted and recorded.');
    }

    // ───────────── orders & delivery ─────────────

    public function orders(Request $request): View
    {
        $project = $request->integer('project') ? Project::with('client')->find($request->integer('project')) : null;
        $stock = $project ? Inventory::stockFor($project->client_id, null, $project->id)->groupBy(fn ($r) => $r['line']->supplier_product_id)
            ->map(fn ($g) => ['name' => $g->first()['line']->description, 'available' => $g->reduce(fn ($c, $r) => bcadd($c, $r['available'], 2), '0')]) : collect();
        $orders = ClientOrder::where('fulfilment', 'stock')->with(['project.client', 'items.line', 'collections', 'invoice'])->latest('id')->paginate(25)->withQueryString();

        return view('product.orders', [
            'orders' => $orders, 'projects' => Project::with('client:id,name')->whereNull('archived_at')->orderBy('name')->get(), 'project' => $project, 'stock' => $stock,
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function orderStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'], 'reference' => ['required', 'string', 'max:120'], 'ordered_on' => ['required', 'date'],
            'customer_name' => ['nullable', 'string', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:40'], 'customer_address' => ['nullable', 'string', 'max:255'],
            'sales_channel' => ['nullable', Rule::in(array_keys(self::CHANNELS))], 'payment_method' => ['nullable', Rule::in(array_keys(self::PAYMENT_METHODS))],
            'delivery_method' => ['nullable', Rule::in(array_keys(self::DELIVERY_METHODS))], 'discount_reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['nullable', 'integer'], 'items.*.quantity' => ['nullable', 'regex:'.self::QTY], 'items.*.price' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
            'items.*.discount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
        ]);
        $items = collect($data['items'])->filter(fn ($i) => ! empty($i['product_id']) && ($i['quantity'] ?? '') !== '')
            ->map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => $i['quantity'], 'unit_price' => Money::toMinor($i['price'] ?? 0), 'unit_discount' => Money::toMinor($i['discount'] ?? 0)])->values()->all();
        if (collect($items)->contains(fn ($i) => $i['unit_discount'] > 0) && ! $request->user()->can('orders.discount')) {
            throw ValidationException::withMessages(['discount_reason' => 'You are not authorised to give a discount; ask Finance.']);
        }

        return $this->attempt(fn () => OrderFulfilment::create(Project::findOrFail($data['project_id']), ['items' => $items] + $data, $request->user()), 'Order recorded. Confirm it to reserve the stock.');
    }

    public function orderConfirm(Request $request, ClientOrder $order): RedirectResponse
    {
        $d = $request->validate(['stock_location_id' => ['nullable', 'exists:stock_locations,id']]);

        return $this->attempt(fn () => OrderFulfilment::confirm($order, $request->user(), $d['stock_location_id'] ?? null), 'Order confirmed and the stock is reserved.');
    }

    public function orderDispatch(Request $request, ClientOrder $order): RedirectResponse
    {
        return $this->attempt(fn () => OrderFulfilment::dispatch($order, $request->user()), 'Order dispatched.');
    }

    public function orderDeliver(Request $request, ClientOrder $order): RedirectResponse
    {
        $data = $request->validate(['delivered' => ['nullable', 'array'], 'delivered.*' => ['nullable', 'regex:'.self::QTY]]);
        $delivered = collect($data['delivered'] ?? [])->filter(fn ($v) => $v !== null && $v !== '')->all();

        return $this->attempt(fn () => OrderFulfilment::deliver($order, $delivered, $request->user()), 'Delivery confirmed. Commission is calculated on what was delivered.');
    }

    public function orderFail(Request $request, ClientOrder $order): RedirectResponse
    {
        return $this->attempt(fn () => OrderFulfilment::fail($order, $request->user()), 'Marked as a failed delivery: the stock is back in available and no commission is earned.');
    }

    public function orderCancel(Request $request, ClientOrder $order): RedirectResponse
    {
        return $this->attempt(fn () => OrderFulfilment::cancel($order, $request->user()), 'Order cancelled and any reserved stock released.');
    }

    public function orderReturn(Request $request, ClientOrder $order): RedirectResponse
    {
        $data = $request->validate(['returned' => ['required', 'array'], 'returned.*' => ['nullable', 'regex:'.self::QTY]]);

        return $this->attempt(fn () => OrderFulfilment::customerReturn($order, collect($data['returned'])->filter(fn ($v) => $v !== null && $v !== '')->all(), $request->user()),
            'Return recorded. The units wait for inspection before they can be sold again.');
    }

    public function itemInspect(Request $request, ClientOrderItem $item): RedirectResponse
    {
        $data = $request->validate(['usable' => ['required', 'regex:'.self::QTY], 'damaged' => ['required', 'regex:'.self::QTY]]);

        return $this->attempt(fn () => OrderFulfilment::inspectReturn($item, $data['usable'], $data['damaged'], $request->user()), 'Inspection recorded.');
    }

    public function orderCollect(Request $request, ClientOrder $order): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'collected_on' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:255'],
            'financial_account_id' => ['nullable', 'exists:financial_accounts,id'], 'idempotency_key' => ['required', 'uuid']]);

        return $this->attempt(function () use ($order, $data, $request) {
            $saved = OrderFulfilment::collect($order, Money::toMinor($data['amount']), $data['collected_on'], $data['reference'] ?? null, $data['idempotency_key'], $data['financial_account_id'] ?? null, $request->user());
            session()->flash($saved ? 'status' : 'warning', $saved ? 'Collection recorded: the money is held for the client.' : 'That collection was already recorded; nothing was duplicated.');
        }, 'Done.');
    }

    // ───────────── customer refunds ─────────────

    public function refunds(): View
    {
        return view('product.refunds', ['refunds' => OrderRefund::with(['order.project.client', 'requester'])->latest('id')->limit(100)->get(), 'classes' => OrderRefunds::CLASSES,
            'allocations' => OrderRefundAllocation::with('collection')->get()->groupBy('order_refund_id'), 'adjustments' => CommissionAdjustment::all()->keyBy('order_refund_id'), 'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
            'refundable' => ClientOrder::where('fulfilment', 'stock')->where('delivery_status', 'delivered')->with(['collections', 'project.client'])->latest('id')->get()
                ->map(fn ($o) => ['order' => $o, 'left' => OrderRefunds::refundable($o)])->filter(fn ($r) => $r['left'] > 0)->values()]);
    }

    public function refundStore(Request $request, ClientOrder $order): RedirectResponse
    {
        $d = $request->validate(['amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'classification' => ['required', Rule::in(array_keys(OrderRefunds::CLASSES))], 'reason' => ['required', 'string', 'max:255'],
            'beneficiary' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => OrderRefunds::request($order, Money::toMinor($d['amount']), $d['classification'], $d['reason'], $d['beneficiary'] ?? null, $request->user()), 'Refund requested. Someone else must approve it before it can be paid.');
    }

    public function refundDecide(Request $request, OrderRefund $refund): RedirectResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:255'], 'treatment' => ['nullable', Rule::in(['reduce', 'keep'])],
            'responsible' => ['nullable', Rule::in(['client', 'sellbees'])]]);

        return $this->attempt(fn () => OrderRefunds::decide($refund, $d['decision'] === 'approve', $d['note'] ?? null, $request->user(), $d['treatment'] ?? null, $d['responsible'] ?? null), 'Decision recorded.');
    }

    public function refundPay(Request $request, OrderRefund $refund): RedirectResponse
    {
        $d = $request->validate(['paid_on' => ['required', 'date', 'before_or_equal:today'], 'reference' => ['required', 'string', 'max:255'], 'financial_account_id' => ['required', 'exists:financial_accounts,id'], 'idempotency_key' => ['required', 'uuid']]);

        try {
            $saved = OrderRefunds::pay($refund, $d['paid_on'], (int) $d['financial_account_id'], $d['reference'], $d['idempotency_key'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        // Returned directly so the specific message is shown (attempt() would replace it with its generic success text).
        return $saved ? back()->with('status', 'Refund payment recorded against the money held for the client.') : back()->with('warning', 'That refund payment was already recorded; nothing was duplicated.');
    }

    public function refundCancel(OrderRefund $refund): RedirectResponse
    {
        return $this->attempt(fn () => OrderRefunds::cancel($refund), 'Refund cancelled.');
    }

    // ───────────── client settlements ─────────────

    public function settlements(): View
    {
        $clients = Client::orderBy('name')->get()->map(fn ($c) => ['client' => $c, 's' => ClientSettlementService::statement($c)])
            ->filter(fn ($r) => $r['s']['collected'] > 0 || $r['s']['delivered_sales'] > 0 || $r['s']['history']->isNotEmpty())->values();

        return view('product.settlements', ['rows' => $clients]);
    }

    public function settlementShow(Client $client): View
    {
        return view('product.settlement-show', ['client' => $client, 's' => ClientSettlementService::statement($client), 'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
            'orders' => ClientOrder::where('fulfilment', 'stock')->whereHas('project', fn ($q) => $q->where('client_id', $client->id))->with(['items', 'collections'])->latest('id')->get()]);
    }

    public function settlementStore(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(['pay' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'paid_on' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:255'],
            'financial_account_id' => ['nullable', 'exists:financial_accounts,id'], 'idempotency_key' => ['required', 'uuid']]);

        return $this->attempt(function () use ($client, $data, $request) {
            $pay = ($data['pay'] ?? '') !== '' ? Money::toMinor($data['pay']) : null;
            $saved = ClientSettlementService::record($client, $pay, $data['paid_on'], $data['reference'] ?? null, $data['financial_account_id'] ?? null, $data['idempotency_key'], $request->user());
            session()->flash($saved ? 'status' : 'warning', $saved ? 'Settlement recorded. Fees were netted from the client\'s money and the rest paid as entered.' : 'That settlement was already recorded; nothing was duplicated.');
        }, 'Done.');
    }
}
