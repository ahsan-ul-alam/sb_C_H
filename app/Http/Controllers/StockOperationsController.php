<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Document;
use App\Models\Project;
use App\Models\SourcingArrivalEvent;
use App\Models\SourcingLotLine;
use App\Models\StockAllocation;
use App\Models\StockMovement;
use App\Models\StockThreshold;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Audit;
use App\Services\InventoryExceptions;
use App\Services\InventoryReports;
use App\Services\ProductImages;
use App\Services\ProjectInventory;
use App\Services\ProjectStockAllocation;
use App\Services\Receiving;
use App\Services\SalesDashboard;
use App\Services\SalesStatus;
use App\Services\StockLocations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Inventory and Sales screens. Thin on purpose: the figures come from ProjectInventory, InventoryExceptions, SalesDashboard and InventoryReports,
 * and every write goes through Receiving, StockLocations or the existing Inventory and OrderFulfilment services.
 */
class StockOperationsController extends Controller
{
    private const QTY = '/^\d{1,10}(\.\d{1,2})?$/';

    /** @return array<string, mixed> */
    private function filters(Request $r): array
    {
        return ['project' => $r->integer('project') ?: null, 'client' => $r->integer('client') ?: null, 'supplier' => $r->integer('supplier') ?: null, 'lot' => $r->integer('lot') ?: null,
            'location' => $r->integer('location') ?: null, 'product' => $r->query('product'), 'ownership' => $r->query('ownership') ?: null, 'status' => $r->query('status') ?: null,
            'from' => $r->query('from') ?: null, 'to' => $r->query('to') ?: null];
    }

    public function overview(): View
    {
        $rows = ProjectInventory::positions();
        $pending = SourcingLotLine::query()->whereHas('lot', fn ($q) => $q->where('status', '!=', 'cancelled'))->with('lot')->get()->filter(fn ($l) => bccomp(Receiving::expected($l), '0', 2) > 0);

        return view('stock.overview', ['totals' => ProjectInventory::totals($rows), 'positions' => $rows->count(), 'pendingReceiving' => $pending->count(), 'exceptions' => InventoryExceptions::all(),
            'lowStock' => InventoryExceptions::lowStock()->where('state', '!=', 'ok'), 'sales' => SalesDashboard::figures()]);
    }

    public function projects(Request $request): View
    {
        $rows = ProjectInventory::positions($this->filters($request));
        $visible = $request->user()->accessibleProjectIds();

        return view('stock.projects', ['rows' => $rows->groupBy(fn ($r) => $r['project']?->id ?? ($r['attribution'] === 'shared' ? 'shared' : 'none')), 'totals' => ProjectInventory::totals($rows),
            'projects' => Project::when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->orderBy('name')->get(['id', 'name']),
            'clients' => Client::when($visible !== null, fn ($q) => $q->whereHas('projects', fn ($p) => $p->whereIn('id', $visible)))->orderBy('name')->get(['id', 'name']), 'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'locations' => InventoryExceptions::locationList(), 'products' => SupplierProduct::orderBy('name')->get(['id', 'name'])]);
    }

    /** Hand part of a lot line from its home project to another project of the same client (audited; see ProjectStockAllocation). */
    public function allocationStore(Request $request): RedirectResponse
    {
        $d = $request->validate(['line_id' => ['required', 'exists:sourcing_lot_lines,id'], 'project_id' => ['required', 'exists:projects,id'], 'quantity' => ['required', 'regex:'.self::QTY], 'reason' => ['required', 'string', 'max:255']]);
        $line = SourcingLotLine::with('lot')->findOrFail($d['line_id']);
        $to = Project::findOrFail($d['project_id']);
        abort_unless($request->user()->canAccessProject($line->lot->project_id) && $request->user()->canAccessProject($to), 403, 'You do not have access to this project.');

        return $this->attempt(fn () => ProjectStockAllocation::allocate($line, $to, $d['quantity'], $d['reason'], $request->user()), 'Stock allocated to the project.');
    }

    public function allocationCancel(Request $request, StockAllocation $allocation): RedirectResponse
    {
        abort_unless($request->user()->canAccessProject($allocation->line->lot->project_id) && $request->user()->canAccessProject($allocation->project_id), 403, 'You do not have access to this project.');

        return $this->attempt(fn () => ProjectStockAllocation::cancel($allocation, $request->user()), 'Allocation cancelled.');
    }

    public function movements(Request $request): View
    {
        $f = $this->filters($request);
        $q = StockMovement::with(['line.lot.project', 'creator', 'location'])->when($f['lot'], fn ($q, $v) => $q->whereHas('line', fn ($l) => $l->where('sourcing_lot_id', $v)))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))->when($f['from'], fn ($q, $v) => $q->whereDate('occurred_on', '>=', $v))->when($f['to'], fn ($q, $v) => $q->whereDate('occurred_on', '<=', $v));

        return view('stock.movements', ['movements' => $q->latest('id')->paginate(50)->withQueryString(), 'types' => StockMovement::distinct()->orderBy('type')->pluck('type')]);
    }

    public function receiving(): View
    {
        $expected = SourcingLotLine::query()->whereHas('lot', fn ($q) => $q->whereNotIn('status', ['cancelled', 'settled']))->with(['lot.project', 'supplier'])->orderBy('sourcing_lot_id')->get()
            ->filter(fn ($l) => bccomp(Receiving::expected($l), '0', 2) > 0);

        $history = SourcingArrivalEvent::with(['line.lot', 'location', 'creator'])->latest('id')->limit(100)->get();

        return view('stock.receiving', ['expected' => $expected, 'history' => $history, 'locations' => InventoryExceptions::locationList(), 'kinds' => self::EVIDENCE_KINDS,
            'evidence' => Document::where('subject_type', 'stock_arrival')->whereIn('subject_id', $history->pluck('id'))->orderBy('id')->get()->groupBy('subject_id')]);
    }

    public function receive(Request $request, SourcingLotLine $line): RedirectResponse
    {
        $d = $request->validate(['reference' => ['required', 'string', 'max:80'], 'received_quantity' => ['required', 'regex:'.self::QTY], 'accepted_quantity' => ['required', 'regex:'.self::QTY],
            'damaged_quantity' => ['nullable', 'regex:'.self::QTY], 'arrived_on' => ['required', 'date', 'before_or_equal:today'], 'stock_location_id' => ['nullable', 'exists:stock_locations,id'],
            'evidence_reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => Receiving::receive($line, $d['received_quantity'], $d['accepted_quantity'], $d['damaged_quantity'] ?? '0', $d['arrived_on'], $d['stock_location_id'] ?? null,
            $d['reference'], $d['evidence_reference'] ?? null, $d['notes'] ?? null, $request->user()), 'Arrival recorded. The accepted units are now stock.');
    }

    public const EVIDENCE_KINDS = ['challan' => 'Supplier delivery challan', 'grn' => 'Goods receiving note', 'invoice' => 'Invoice / packing list', 'inspection' => 'Inspection evidence', 'photo' => 'Product condition photo'];

    /** Evidence is append-only: a file is added to an arrival, never replaced or removed, and the same file cannot be added twice. Stock is never touched here. */
    public function evidenceStore(Request $request, SourcingArrivalEvent $event): RedirectResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(array_keys(self::EVIDENCE_KINDS))], 'file' => ['required', 'file', 'max:8192', 'mimes:pdf,jpg,jpeg,png']]);
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        if (Document::where('subject_type', 'stock_arrival')->where('subject_id', $event->id)->where('sha256', $hash)->exists()) {
            return back()->with('error', 'That exact file is already attached to this arrival.');
        }
        $document = Document::create(['subject_type' => 'stock_arrival', 'subject_id' => $event->id, 'name' => self::EVIDENCE_KINDS[$d['kind']], 'path' => $file->store('receiving-evidence'),
            'uploaded_by' => $request->user()->id, 'kind' => $d['kind'], 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $hash]);
        Audit::log('inventory.evidence', 'arrival '.$event->id, $d['kind'].' sha256 '.$hash.' (document '.$document->id.')');

        return back()->with('status', 'Evidence attached to the arrival. It cannot be replaced; add another file if needed.');
    }

    public function evidenceDownload(Document $document): StreamedResponse
    {
        abort_unless($document->subject_type === 'stock_arrival' && Storage::exists($document->path), 404);

        return Storage::download($document->path, $document->name.'.'.pathinfo($document->path, PATHINFO_EXTENSION));
    }

    public function productImageStore(Request $request, SupplierProduct $product): RedirectResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=50,min_height=50,max_width=4000,max_height=4000']]);

        return $this->attempt(fn () => ProductImages::replace($product, $request->file('image')), 'Product image saved.');
    }

    public function productImage(SupplierProduct $product): StreamedResponse
    {
        abort_unless($product->image_path && Storage::exists($product->image_path), 404);

        return Storage::response($product->image_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function reservations(): View
    {
        $orders = ClientOrder::where('fulfilment', 'stock')->whereIn('delivery_status', ['confirmed', 'dispatched'])->with(['project.client', 'items.line.lot'])->orderBy('updated_at')->get();

        return view('stock.reservations', ['orders' => $orders, 'stale' => now()->subDays(InventoryExceptions::STALE_RESERVATION_DAYS)]);
    }

    public function transfers(): View
    {
        $stocked = SourcingLotLine::whereNotNull('received_quantity')->with('lot')->orderBy('sourcing_lot_id')->get();

        return view('stock.transfers', ['transfers' => StockTransfer::with(['line.lot', 'from', 'to'])->latest('id')->limit(100)->get(), 'locations' => InventoryExceptions::locationList(), 'lines' => $stocked,
            'thresholds' => StockThreshold::with(['product', 'project', 'location'])->get(), 'products' => SupplierProduct::orderBy('name')->get(['id', 'name']), 'projects' => Project::orderBy('name')->get(['id', 'name'])]);
    }

    public function locationStore(Request $request): RedirectResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => StockLocations::create($d['code'], $d['name']), 'Location added.');
    }

    public function transferStore(Request $request): RedirectResponse
    {
        $d = $request->validate(['sourcing_lot_line_id' => ['required', 'exists:sourcing_lot_lines,id'], 'from_location_id' => ['nullable', 'exists:stock_locations,id'], 'to_location_id' => ['required', 'exists:stock_locations,id'],
            'quantity' => ['required', 'regex:'.self::QTY], 'reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => StockLocations::request(SourcingLotLine::findOrFail($d['sourcing_lot_line_id']), null, $d['from_location_id'] ?? null, (int) $d['to_location_id'], $d['quantity'], $d['reason'], $request->user()), 'Transfer requested. Nothing has moved yet.');
    }

    public function transferComplete(Request $request, StockTransfer $transfer): RedirectResponse
    {
        return $this->attempt(fn () => StockLocations::complete($transfer, $request->user()), 'Transfer completed. The line total, ownership and value are unchanged.');
    }

    public function transferCancel(StockTransfer $transfer): RedirectResponse
    {
        return $this->attempt(fn () => StockLocations::cancel($transfer), 'Transfer cancelled.');
    }

    public function thresholdStore(Request $request): RedirectResponse
    {
        $d = $request->validate(['supplier_product_id' => ['required', 'exists:supplier_products,id'], 'project_id' => ['nullable', 'exists:projects,id'], 'stock_location_id' => ['nullable', 'exists:stock_locations,id'],
            'minimum_quantity' => ['required', 'regex:'.self::QTY]]);
        StockThreshold::updateOrCreate(['supplier_product_id' => $d['supplier_product_id'], 'project_id' => $d['project_id'] ?? null, 'stock_location_id' => $d['stock_location_id'] ?? null], ['minimum_quantity' => $d['minimum_quantity']]);

        return back()->with('status', 'Low-stock level saved. It only warns; it never orders anything.');
    }

    public function exceptions(): View
    {
        return view('stock.exceptions', ['exceptions' => InventoryExceptions::all()->groupBy('type'), 'lowStock' => InventoryExceptions::lowStock()]);
    }

    public function sales(Request $request): View
    {
        $f = $this->filters($request);
        $orders = ClientOrder::where('fulfilment', 'stock')->with(['project.client', 'items', 'collections'])->latest('id')->limit(50)->get();

        return view('stock.sales', ['f' => SalesDashboard::figures($f['from'], $f['to']), 'orders' => $orders->map(fn ($o) => ['order' => $o, 's' => SalesStatus::of($o)])]);
    }

    public function reports(): View
    {
        return view('stock.reports', ['types' => InventoryReports::TYPES]);
    }

    public function report(Request $request, string $type): View|StreamedResponse|Response
    {
        abort_unless(array_key_exists($type, InventoryReports::TYPES), 404);
        $report = InventoryReports::build($type, $this->filters($request));
        if ($request->query('export') === 'csv') {
            Gate::authorize('inventory.view');

            return response()->streamDownload(function () use ($report) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $report['headers']);
                foreach ($report['rows'] as $row) {
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
                }
                fclose($out);
            }, $type.'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('stock.report', ['type' => $type, 'report' => $report]);
    }
}
