<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Segment;
use App\Support\DateRange;
use App\Support\ListFilters;
use App\Support\Money;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        ListFilters::remember($request, 'invoices');
        $search = trim((string) $request->query('q'));
        $range = $request->filled('range') ? DateRange::fromRequest($request) : null;
        $status = (string) $request->query('status');

        $invoices = Invoice::query()
            ->with('project.client')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                ->orWhereHas('project.client', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->when($request->integer('client'), fn ($q, $id) => $q->whereHas('project', fn ($p) => $p->where('client_id', $id)))
            ->when($request->integer('project'), fn ($q, $id) => $q->where('project_id', $id))
            ->when($status === 'open', fn ($q) => $q->open())
            ->when(InvoiceStatus::tryFrom($status), fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->query('currency'), fn ($q, $currency) => $q->where('currency', $currency))
            ->when($range, fn ($q) => $q->whereDate('issued_on', '>=', $range->from)->whereDate('issued_on', '<=', $range->to))
            ->latest('issued_on')->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('invoices.index', [
            'invoices' => $invoices,
            'range' => $range,
            'currencies' => Invoice::query()->distinct()->orderBy('currency')->pluck('currency'),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'filterProject' => $request->integer('project') ? Project::find($request->integer('project')) : null,
        ]);
    }

    /**
     * Entry point for "New invoice" without a project: pick one (optionally within a client), then continue.
     */
    public function start(Request $request): View|RedirectResponse
    {
        if ($project = Project::find($request->integer('project_id'))) {
            return redirect()->route('projects.invoices.create', $project);
        }

        $client = Client::find($request->integer('client'));
        $projects = Project::query()
            ->with('client:id,name')
            ->whereNull('archived_at')
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->orderBy('name')
            ->get(['id', 'client_id', 'name', 'currency']);

        return view('invoices.start', compact('client', 'projects'));
    }

    public function create(Request $request, Project $project): View
    {
        $invoice = new Invoice([
            'number' => Invoice::nextNumber(),
            'issued_on' => today(),
            'currency' => $project->currency,
        ]);

        $items = [];
        $segment = $request->integer('segment') ? $project->segments()->find($request->integer('segment')) : null;
        if ($segment) {
            $items[] = ['segment_id' => $segment->id, 'description' => $segment->name, 'quantity' => '1', 'unit' => '', 'unit_price' => Money::toDecimal($segment->amount)];
        }

        return view('invoices.form', $this->formData($project, $invoice, $items));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        [$data, $items] = $this->validated($request, $project);

        $invoice = DB::transaction(function () use ($project, $data, $items): Invoice {
            $invoice = $project->invoices()->make($data);
            $invoice->currency = $project->currency;
            $invoice->status = InvoiceStatus::Unpaid;
            $invoice->save();
            $this->syncItems($invoice, $items);

            return $invoice;
        });

        return $this->afterSave($request, $invoice, 'Invoice created.');
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['project.client', 'items', 'payments' => fn ($q) => $q->with('allocations.segment')->latest('paid_on')->latest('id')]);

        return view('invoices.show', compact('invoice'));
    }

    public function edit(Invoice $invoice): View
    {
        $items = $invoice->items->map(fn ($item) => [
            'segment_id' => $item->segment_id,
            'description' => $item->description,
            'quantity' => $item->displayQuantity(),
            'unit' => $item->unit,
            'unit_price' => Money::toDecimal($item->unit_price),
        ])->all();

        return view('invoices.form', $this->formData($invoice->project, $invoice, $items));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        [$data, $items] = $this->validated($request, $invoice->project, $invoice);

        DB::transaction(function () use ($invoice, $data, $items): void {
            $invoice->update($data);
            $this->syncItems($invoice, $items);
        });

        return $this->afterSave($request, $invoice, 'Invoice saved.');
    }

    /**
     * "Save" keeps the admin in the editor; "Save & preview" opens the invoice with its PDF preview.
     */
    private function afterSave(Request $request, Invoice $invoice, string $message): RedirectResponse
    {
        return $request->input('after') === 'preview'
            ? redirect()->route('invoices.show', $invoice)->with('status', $message)
            : redirect()->route('invoices.edit', $invoice)->with('status', $message);
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        if ($invoice->payments()->exists()) {
            return back()->with('error', 'This invoice has payments and cannot be deleted. Remove the payments first.');
        }

        $project = $invoice->project;
        $segmentIds = $invoice->items()->whereNotNull('segment_id')->pluck('segment_id')->unique()->values()->all();

        DB::transaction(function () use ($invoice, $segmentIds): void {
            $invoice->delete();
            Invoice::refreshBillingSegments($segmentIds);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Invoice deleted.');
    }

    /**
     * Render the PDF from the current saved data so the stamp always reflects the latest payments.
     */
    public function pdf(Request $request, Invoice $invoice): Response
    {
        $invoice->load(['project.client', 'items', 'payments' => fn ($q) => $q->orderBy('paid_on')->orderBy('id')]);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->setChroot(base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('invoices.pdf', ['invoice' => $invoice, 'settings' => BusinessSetting::current()])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$invoice->number.'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function formData(Project $project, Invoice $invoice, array $items): array
    {
        $project->loadMissing('client');

        return [
            'project' => $project,
            'invoice' => $invoice,
            'items' => old('items', $items ?: [['segment_id' => null, 'description' => '', 'quantity' => '1', 'unit' => '', 'unit_price' => '']]),
            'segments' => $project->segments()->orderBy('id')->get(),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function validated(Request $request, Project $project, ?Invoice $invoice = null): array
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:32', Rule::unique('invoices', 'number')->ignore($invoice?->id)],
            'issued_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.segment_id' => ['nullable', 'integer', Rule::exists('segments', 'id')->where('project_id', $project->id)],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'items.*.unit_price' => ['required', 'regex:'.Money::AMOUNT_REGEX],
        ], [
            'items.required' => 'Add at least one line item.',
            'items.*.description.required' => 'Each item needs a description.',
            'items.*.quantity.*' => 'Quantity must be a positive number (max 2 decimals).',
            'items.*.unit_price.*' => 'Unit price must be a valid amount (max 2 decimals).',
        ]);

        $items = collect($data['items'])->values()->map(function (array $item, int $index): array {
            $unitPrice = Money::toMinor($item['unit_price']);

            return [
                'segment_id' => $item['segment_id'] ?: null,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => ($item['unit'] ?? null) ?: null,
                'unit_price' => $unitPrice,
                'amount' => Money::multiply($item['quantity'], $unitPrice),
                'position' => $index,
            ];
        })->all();

        $total = array_sum(array_column($items, 'amount'));

        if ($total <= 0) {
            throw ValidationException::withMessages(['items' => 'The invoice total must be greater than zero.']);
        }

        if ($invoice && $total < $invoice->amount_paid) {
            throw ValidationException::withMessages([
                'items' => 'The total cannot be less than the amount already paid ('.Money::format($invoice->amount_paid, $invoice->currency).').',
            ]);
        }

        unset($data['items']);

        return [$data, $items];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(Invoice $invoice, array $items): void
    {
        $segmentIds = $invoice->items()->whereNotNull('segment_id')->pluck('segment_id')
            ->merge(array_column($items, 'segment_id'))->filter()->unique()->values()->all();

        Segment::whereIn('id', $segmentIds)->orderBy('id')->lockForUpdate()->get('id');

        $invoice->items()->delete();
        $invoice->items()->createMany($items);
        $invoice->total = array_sum(array_column($items, 'amount'));
        $invoice->refreshPaymentStatus();

        Invoice::refreshBillingSegments($segmentIds, $invoice->id);
    }
}
