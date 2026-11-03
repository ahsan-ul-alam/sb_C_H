<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\FinancialAccount;
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
use App\Services\Sourcing;
use App\Services\SourcingPayments;
use App\Support\Costing;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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
        return view('sourcing.lots.create', ['projects' => Project::with('client:id,name')->whereNull('archived_at')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'allocation_basis' => ['required', Rule::in(['value', 'quantity'])], 'notes' => ['nullable', 'string', 'max:5000'],
            'purchase_order' => ['nullable', 'string', 'max:191'], 'project_id' => ['nullable', 'exists:projects,id'],
        ]);
        $lot = Sourcing::createLot($data, $request->user());

        return redirect()->route('sourcing.lots.show', $lot)->with('status', 'Lot '.$lot->number.' created. Add product lines next.');
    }

    public function show(Request $request, SourcingLot $lot): View
    {
        $tab = in_array($request->query('tab'), ['overview', 'lines', 'receiving', 'pricing', 'billing', 'payments', 'emergency', 'activity'], true) ? $request->query('tab') : 'overview';
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

        return $this->attempt(function () use ($lot, $data) {
            if ($lot->isClosed()) {
                throw new \RuntimeException('This lot is '.strtolower($lot->statusLabel()).' and can no longer be changed.');
            }
            $lot->update(['procurement_status' => $data['procurement_status']]);
            Audit::log('sourcing.procurement', 'lot '.$lot->number, $data['procurement_status']);
        }, 'Procurement status updated.');
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

        return $this->attempt(fn () => SourcingPayments::payCost($cost, $data['paid_on'], $request->user(), $data['financial_account_id'] ?? null, $payable),
            $payable ? 'Recorded as a payable (cost incurred, not yet paid); a Board member verifies it, and it is paid later under Expenses.' : 'Payment recorded; a Board member verifies it before money leaves the account.');
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
