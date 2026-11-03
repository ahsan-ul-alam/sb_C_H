<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\SourcingAllocation;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SourcingLotLine;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierProduct;
use App\Services\Audit;
use App\Services\EmergencyFund;
use App\Services\LotFinancials;
use App\Services\LotStatus;
use App\Services\PaymentRecorder;
use App\Services\QuickLot;
use App\Services\Sourcing;
use App\Services\SourcingPayments;
use App\Support\Costing;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SourcingLotController extends Controller
{
    private const QTY = '/^\d{1,10}(\.\d{1,2})?$/';

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $lots = SourcingLot::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%")))
            ->when(isset(SourcingLot::STATUSES[(string) $request->query('status')]), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->integer('supplier'), fn ($query, $id) => $query->whereHas('lines', fn ($l) => $l->where('supplier_id', $id)))
            ->latest('id')->paginate(25)->withQueryString();

        return view('sourcing.lots.index', [
            'lots' => $lots,
            'figures' => $lots->getCollection()->mapWithKeys(fn ($lot) => [$lot->id => LotFinancials::for($lot)]),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('sourcing.lots.create', [
            'projects' => Project::with('client:id,name')->whereNull('archived_at')->orderBy('name')->get(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The simple form's live numbers: the SAME calculation the save uses (QuickLot), so the screen can never disagree with the stored lot. */
    public function preview(Request $request): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail((int) $request->input('supplier_id'));
            $items = $this->quickItems($request, lenient: true);
            if ($items === []) {
                return response()->json(['rows' => [], 'totals' => null]);
            }
            $delivery = $this->money($request->input('delivery_cost'));
            $calc = QuickLot::calculate($supplier, $items, $delivery);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $fmt = fn (int $v) => Money::format($v);
        $rows = collect($calc['rows'])->map(fn ($r) => [
            'product_id' => $r['product']->id, 'base' => $fmt($r['base_price']), 'purchase' => $fmt($r['purchase']), 'delivery' => $fmt($r['delivery']),
            'unit_cost' => $fmt((int) bcadd($r['unit_cost'], '0.5', 0)), 'price' => $fmt($r['price']), 'selling' => $fmt($r['selling']), 'profit' => $fmt($r['expected_profit']),
            'minor' => ['purchase' => $r['purchase'], 'delivery' => $r['delivery'], 'landed' => $r['landed'], 'price' => $r['price'], 'selling' => $r['selling'], 'profit' => $r['expected_profit']],
        ])->all();

        return response()->json(['rows' => $rows, 'totals' => [
            'purchase' => $fmt($calc['purchase']), 'delivery' => $fmt($calc['delivery']), 'landed' => $fmt($calc['landed']), 'selling' => $fmt($calc['selling']), 'profit' => $fmt($calc['profit']),
            'minor' => ['purchase' => $calc['purchase'], 'delivery' => $calc['delivery'], 'landed' => $calc['landed'], 'selling' => $calc['selling'], 'profit' => $calc['profit']],
        ]]);
    }

    /** Active products of one supplier, with their current base price (loaded by the lot form when a supplier is chosen). */
    public function supplierProducts(Supplier $supplier): JsonResponse
    {
        return response()->json($supplier->products()->where('is_active', true)->orderBy('name')->get()->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->label(), 'sku' => $p->sku, 'unit' => $p->unit, 'price' => Money::format((int) $p->reference_price), 'price_minor' => (int) $p->reference_price,
        ])->values());
    }

    private function money(mixed $value): int
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match(Money::AMOUNT_REGEX, $value) ? Money::toMinor($value) : 0;
    }

    /** @return list<array{product_id: int, quantity: string, profit: int}> */
    private function quickItems(Request $request, bool $lenient = false): array
    {
        $items = [];
        foreach ((array) $request->input('items', []) as $row) {
            $quantity = trim((string) ($row['quantity'] ?? ''));
            if ($lenient && (empty($row['product_id']) || $quantity === '' || ! preg_match(self::QTY, $quantity) || bccomp($quantity, '0', 2) <= 0)) {
                continue; // a half-typed row is simply not priced yet
            }
            $items[] = ['product_id' => (int) ($row['product_id'] ?? 0), 'quantity' => $quantity, 'profit' => $this->money($row['profit'] ?? '')];
        }

        return $items;
    }

    private function storeQuick(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'], 'title' => ['nullable', 'string', 'max:255'], 'purchase_order' => ['nullable', 'string', 'max:191'],
            'project_id' => ['nullable', 'exists:projects,id'], 'notes' => ['nullable', 'string', 'max:5000'], 'delivery_cost' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
            'submission_key' => ['nullable', 'uuid'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'regex:'.self::QTY], 'items.*.profit' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
        ], ['items.required' => 'Add at least one product.', 'items.*.quantity.*' => 'Enter a valid quantity for every product.']);
        $supplier = Supplier::findOrFail($data['supplier_id']);
        if (! $supplier->is_active) {
            return back()->with('error', 'That supplier is archived.')->withInput();
        }

        // A double click or a retry with the same form must not create a second lot: the form's key is remembered for ten minutes.
        $key = 'quick-lot:'.($data['submission_key'] ?? (string) str()->uuid());
        try {
            return Cache::lock($key.':lock', 10)->block(5, function () use ($request, $data, $supplier, $key) {
                if ($existing = Cache::get($key)) {
                    return redirect()->route('sourcing.lots.show', $existing)->with('warning', 'This lot was already saved; nothing was duplicated.');
                }
                $lot = Sourcing::createQuick($supplier, $this->quickItems($request), $this->money($data['delivery_cost'] ?? ''), [
                    'title' => $data['title'] ?? null, 'purchase_order' => $data['purchase_order'] ?? null, 'project_id' => $data['project_id'] ?? null, 'notes' => $data['notes'] ?? null,
                ], $request->user());
                Cache::put($key, $lot->id, 600);

                return redirect()->route('sourcing.lots.show', $lot)->with('status', $lot->status === 'approved' ? 'Lot '.$lot->number.' saved and approved under the standing policy.' : 'Lot '.$lot->number.' saved. It waits for pricing approval before it can be billed.');
            });
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /** Put everything in the lot on one invoice of a client project, using the normal allocation and billing code. */
    public function billClient(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['project_id' => ['required', 'exists:projects,id']]);

        return $this->attempt(function () use ($lot, $data, $request) {
            $invoice = Sourcing::billWholeLot($lot, (int) $data['project_id'], $request->user());
            $lot->project_id ??= (int) $data['project_id'];
            $lot->save();
            session()->flash('status', 'Client billed on invoice '.$invoice->number.'.');
        }, 'Client billed.');
    }

    /**
     * Record a REAL client receipt against the lot's invoice, through the normal payment recorder (so the invoice, the segment credit and
     * company income all update exactly once). Blank amount = the whole remaining balance. The same form submitted twice records one payment.
     */
    public function clientPayment(Request $request, SourcingLot $lot, PaymentRecorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date'], 'method' => ['required', Rule::enum(PaymentMethod::class)], 'reference' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'invoice_id' => ['nullable', 'integer'], 'idempotency_key' => ['required', 'uuid'],
        ]);
        $invoices = Invoice::whereIn('id', $lot->allocations()->whereNotNull('billed_invoice_id')->pluck('billed_invoice_id'))->get()->filter(fn ($i) => $i->balance() > 0);
        $invoice = $data['invoice_id'] ?? null ? $invoices->firstWhere('id', (int) $data['invoice_id']) : $invoices->first();
        if (! $invoice) {
            return back()->with('error', 'There is no unpaid client invoice for this lot.');
        }
        $amount = ($data['amount'] ?? '') !== '' ? Money::toMinor($data['amount']) : $invoice->balance();

        return $this->attempt(function () use ($recorder, $invoice, $data, $amount) {
            $saved = $recorder->save(new Payment, $invoice->project, $invoice, ['amount' => $amount, 'paid_on' => $data['paid_on'], 'method' => $data['method'],
                'reference' => $data['reference'] ?? null, 'idempotency_key' => $data['idempotency_key']], null);
            session()->flash($saved ? 'status' : 'warning', $saved ? 'Client payment recorded.' : 'That payment was already recorded; nothing was duplicated.');
        }, 'Done.');
    }

    /** Finish a fully paid lot: completes and settles it in one step (the normal settlement rules still apply). */
    public function close(Request $request, SourcingLot $lot): RedirectResponse
    {
        return $this->attempt(fn () => Sourcing::close($lot, $request->user()), 'Lot closed and financially settled.');
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->has('items')) {
            return $this->storeQuick($request);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'allocation_basis' => ['required', Rule::in(['value', 'quantity'])], 'notes' => ['nullable', 'string', 'max:5000'],
            'purchase_order' => ['nullable', 'string', 'max:191'], 'project_id' => ['nullable', 'exists:projects,id'],
        ]);
        $lot = Sourcing::createLot($data, $request->user());

        return redirect()->route('sourcing.lots.show', $lot)->with('status', 'Lot '.$lot->number.' created. Add product lines next.');
    }

    public function show(Request $request, SourcingLot $lot): View
    {
        // No tab = the simple one-page summary; the detailed tabs are the Advanced view.
        $tab = in_array($request->query('tab'), ['overview', 'lines', 'receiving', 'pricing', 'billing', 'payments', 'emergency', 'activity'], true) ? $request->query('tab') : null;
        $f = LotFinancials::for($lot);

        $preview = null;
        if ($request->boolean('preview') && in_array($request->query('method'), ['fixed', 'markup'], true)) {
            $method = $request->query('method');
            $raw = (string) $request->query('value', '0');
            $raw = preg_match(Money::AMOUNT_REGEX, $raw) ? $raw : '0';
            $value = $method === 'markup' ? (int) bcmul($raw, '100', 0) : Money::toMinor($raw);
            $preview = ['method' => $method, 'value' => $value, 'raw' => $raw];
            foreach ($f['lines'] as $id => $line) {
                $price = Costing::price($line['unit_cost'], $method, $value);
                $preview['prices'][$id] = $price;
            }
        }

        return view('sourcing.lots.show', [
            'lot' => $lot, 'f' => $f, 'tab' => $tab, 'preview' => $preview,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'products' => SupplierProduct::with('supplier')->where('is_active', true)->whereHas('supplier', fn ($q) => $q->where('is_active', true))->orderBy('name')->get(),
            'projects' => Project::with('client:id,name')->whereNull('archived_at')->orderBy('name')->get(),
            'activity' => AuditLog::with('user')->where('subject', 'lot '.$lot->number)->orderByDesc('id')->limit(60)->get(),
            'balance' => EmergencyFund::balance(),
            'documents' => Document::where('subject_type', 'sourcing_lot')->where('subject_id', $lot->id)->latest('id')->get(),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /** Operational status only (where the goods are); the financial position is shown separately and is never changed by this. */
    public function procurement(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['procurement_status' => ['required', Rule::in(array_keys(SourcingLot::PROCUREMENT))]]);

        return $this->attempt(fn () => LotStatus::moveProcurement($lot, $data['procurement_status']), 'Procurement status updated.');
    }

    /** A Board member confirms a transport service paid in advance has been done: the prepayment becomes product acquisition cost. */
    public function confirmService(Request $request, SourcingLotCost $cost): RedirectResponse
    {
        $data = $request->validate(['service_on' => ['required', 'date', 'before_or_equal:today']]);

        return $this->attempt(fn () => SourcingPayments::completeService($cost, $data['service_on'], $request->user()), 'Service confirmed: the prepayment is now product acquisition cost (no cash moved).');
    }

    /** Purchase order number and planned client project, for a lot that does not have them yet (or needs correcting before it closes). */
    public function details(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['purchase_order' => ['nullable', 'string', 'max:191'], 'project_id' => ['nullable', 'exists:projects,id']]);

        return $this->attempt(function () use ($lot, $data) {
            if ($lot->isClosed()) {
                throw new RuntimeException('This lot is '.strtolower($lot->statusLabel()).' and can no longer be changed.');
            }
            $lot->update(['purchase_order' => ($data['purchase_order'] ?? null) ?: null, 'project_id' => ($data['project_id'] ?? null) ?: null]);
            Audit::log('sourcing.lot.details', 'lot '.$lot->number, 'PO '.($lot->purchase_order ?? '-').' project '.($lot->project_id ?? '-'));
        }, 'Purchase order and project saved.');
    }

    /** Supporting evidence (supplier invoice, payment slip, delivery note, inspection record) kept on the private disk. */
    public function uploadDocument(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'file' => ['required', 'file', 'max:8192', 'mimes:pdf,jpg,jpeg,png']]);
        $document = Document::create(['subject_type' => 'sourcing_lot', 'subject_id' => $lot->id, 'name' => $data['name'], 'path' => $request->file('file')->store('finance-documents'), 'uploaded_by' => $request->user()->id]);
        Audit::log('document.uploaded', 'lot '.$lot->number, $document->name);

        return back()->with('status', 'Document attached to the lot.');
    }

    public function downloadDocument(Document $document): StreamedResponse
    {
        abort_unless($document->subject_type === 'sourcing_lot' && Storage::exists($document->path), 404);

        return Storage::download($document->path, $document->name.'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }

    public function addLine(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'], 'supplier_product_id' => ['nullable', 'exists:supplier_products,id'],
            'description' => ['nullable', 'string', 'max:255'], 'unit' => ['nullable', 'string', 'max:20'],
            'quantity' => ['required', 'regex:'.self::QTY], 'usable_quantity' => ['nullable', 'regex:'.self::QTY],
            'unit_price' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
        ]);
        $data['supplier_id'] = ($data['supplier_product_id'] ?? null)
            ? SupplierProduct::findOrFail($data['supplier_product_id'])->supplier_id
            : ($data['supplier_id'] ?? null);
        if (! $data['supplier_id']) {
            return back()->with('error', 'Choose a catalog product or a supplier.')->withInput();
        }
        $data['unit_price'] = isset($data['unit_price']) && $data['unit_price'] !== '' ? Money::toMinor($data['unit_price']) : null;
        $data['usable_quantity'] = ($data['usable_quantity'] ?? null) ?: $data['quantity'];

        return $this->attempt(fn () => Sourcing::addLine($lot, $data, $request->user()), 'Line added with a snapshot of the supplier price.');
    }

    public function updateLine(Request $request, SourcingLotLine $line): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'regex:'.self::QTY], 'usable_quantity' => ['required', 'regex:'.self::QTY], 'unit_price' => ['required', 'regex:'.Money::AMOUNT_REGEX],
        ]);
        $data['unit_price'] = Money::toMinor($data['unit_price']);

        return $this->attempt(fn () => Sourcing::updateLine($line, $data, $request->user()), 'Line updated.');
    }

    public function removeLine(SourcingLotLine $line): RedirectResponse
    {
        return $this->attempt(fn () => Sourcing::removeLine($line), 'Line removed.');
    }

    public function addCost(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:255'], 'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'kind' => ['required', Rule::in(['estimate', 'actual'])], 'incurred_on' => ['required', 'date'], 'line_id' => ['nullable', 'integer'],
            'payee' => ['nullable', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:255'], 'replaces_id' => ['nullable', 'integer'],
        ]);
        $data['amount'] = Money::toMinor($data['amount']);
        $data['is_unexpected'] = $request->boolean('is_unexpected');

        return $this->attempt(function () use ($lot, $data, $request) {
            $cost = Sourcing::addCost($lot, $data, $request->user());
            if ($cost->is_unexpected) {
                session()->flash('warning', 'Unexpected cost recorded. Check the Emergency tab if the lot is now loss-making.');
            }
        }, 'Cost recorded.');
    }

    public function removeCost(SourcingLotCost $cost): RedirectResponse
    {
        return $this->attempt(fn () => Sourcing::removeCost($cost), 'Cost removed.');
    }

    public function payCost(Request $request, SourcingLotCost $cost): RedirectResponse
    {
        $data = $request->validate(['paid_on' => ['required', 'date'], 'financial_account_id' => ['nullable', 'exists:financial_accounts,id']]);
        $payable = $request->input('mode') === 'payable';
        $prepaid = $request->input('mode') === 'prepaid';

        return $this->attempt(fn () => SourcingPayments::payCost($cost, $data['paid_on'], $request->user(), $data['financial_account_id'] ?? null, $payable, $prepaid),
            match (true) {
                $prepaid => 'Recorded as transport paid in advance (a prepayment); a Board member verifies it, then confirms the service when it is done.',
                $payable => 'Recorded as a payable (cost incurred, not yet paid); a Board member verifies it, and it is paid later under Expenses.',
                default => 'Payment recorded; a Board member verifies it before money leaves the account.',
            });
    }

    public function savePricing(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['fixed', 'markup'])], 'value' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'overrides' => ['nullable', 'array'], 'overrides.*' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
        ]);
        $value = $data['method'] === 'markup' ? (int) bcmul($data['value'], '100', 0) : Money::toMinor($data['value']);
        $overrides = collect($data['overrides'] ?? [])->map(fn ($v) => $v === null || $v === '' ? null : Money::toMinor($v))->all();

        return $this->attempt(fn () => Sourcing::savePricing($lot, $data['method'], $value, $overrides, $request->user()), 'Pricing saved.');
    }

    public function advance(Request $request, SourcingLot $lot): RedirectResponse
    {
        return $this->attempt(fn () => Sourcing::advance($lot, $request->user()), 'Lot status updated.');
    }

    public function approve(Request $request, SourcingLot $lot): RedirectResponse
    {
        return $this->attempt(fn () => Sourcing::approvePricing($lot, $request->user()), 'Pricing approved and frozen as a snapshot.');
    }

    public function cancel(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => Sourcing::cancel($lot, $data['reason']), 'Lot cancelled.');
    }

    public function settle(Request $request, SourcingLot $lot): RedirectResponse
    {
        return $this->attempt(fn () => Sourcing::settle($lot, $request->user()), 'Lot financially settled.');
    }

    public function reopen(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => Sourcing::reopen($lot, $data['reason']), 'Lot reopened for an audited adjustment.');
    }

    public function allocate(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['sale', 'recovery'])], 'project_id' => ['required', 'exists:projects,id'], 'line_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'regex:'.self::QTY], 'unit_price' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'description' => ['nullable', 'string', 'max:255'],
        ]);
        $data['unit_price'] = isset($data['unit_price']) && $data['unit_price'] !== '' ? Money::toMinor($data['unit_price']) : null;
        if ($data['kind'] === 'sale' && empty($data['line_id'])) {
            return back()->with('error', 'Choose the product line to allocate.')->withInput();
        }

        return $this->attempt(fn () => Sourcing::allocate($lot, $data, $request->user()), 'Allocation saved.');
    }

    public function removeAllocation(SourcingAllocation $allocation): RedirectResponse
    {
        return $this->attempt(fn () => Sourcing::removeAllocation($allocation), 'Allocation removed.');
    }

    public function bill(Request $request, SourcingAllocation $allocation): RedirectResponse
    {
        $data = $request->validate(['segment_id' => ['nullable', 'integer'], 'invoice_id' => ['nullable', 'integer']]);

        return $this->attempt(fn () => Sourcing::bill($allocation, $data['segment_id'] ?? null, $data['invoice_id'] ?? null, $request->user()), 'Added to the client\'s billing.');
    }

    public function recordPayment(Request $request, SourcingLot $lot): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'], 'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'paid_on' => ['required', 'date'],
            'method' => ['nullable', 'string', 'max:30'], 'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:255'], 'idempotency_key' => ['nullable', 'string', 'max:64'],
            'purchase_order' => ['nullable', 'string', 'max:191'], 'evidence_reference' => ['nullable', 'string', 'max:191'], 'financial_account_id' => ['nullable', 'exists:financial_accounts,id'],
        ]);
        $data['amount'] = Money::toMinor($data['amount']);
        $data['purchase_order'] = ($data['purchase_order'] ?? null) ?: $lot->purchase_order; // the lot's purchase order is the default

        return $this->attempt(function () use ($lot, $data, $request) {
            if (SourcingPayments::recordSupplierPayment($lot, $data, $request->user()) === null) {
                session()->flash('warning', 'That payment was already recorded; nothing was duplicated.');
            }
        }, 'Supplier payment recorded; its expense awaits Board verification.');
    }

    public function voidPayment(Request $request, SupplierPayment $payment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'], 'type' => ['nullable', Rule::in(array_keys(SourcingPayments::CORRECTIONS))],
            'amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'reference' => ['nullable', 'string', 'max:120'], 'refund_account_id' => ['nullable', 'exists:financial_accounts,id'],
        ]);
        $amount = ($data['amount'] ?? '') !== '' ? Money::toMinor($data['amount']) : null;

        return $this->attempt(fn () => SourcingPayments::voidSupplierPayment($payment, $data['reason'], $request->user(), $data['type'] ?? 'recording_error', $amount, $data['reference'] ?? null, $data['refund_account_id'] ?? null),
            'Done. A payment that was already verified now has a correction awaiting Board verification; nothing in the ledger changed yet.');
    }
}
