<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Segment;
use App\Services\PaymentRecorder;
use App\Support\DateRange;
use App\Support\ListFilters;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private PaymentRecorder $recorder) {}

    public function index(Request $request): View
    {
        ListFilters::remember($request, 'payments');
        $search = trim((string) $request->query('q'));
        $range = $request->filled('range') ? DateRange::fromRequest($request) : null;

        $query = Payment::query()
            ->with(['project.client', 'invoice', 'allocations.segment'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('reference', 'like', "%{$search}%")
                ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', "%{$search}%"))
                ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                ->orWhereHas('project.client', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->when(PaymentMethod::tryFrom((string) $request->query('method')), fn ($q, $method) => $q->where('method', $method))
            ->when($request->query('currency'), fn ($q, $currency) => $q->whereHas('project', fn ($p) => $p->where('currency', $currency)))
            ->when($request->integer('client'), fn ($q, $id) => $q->whereHas('project', fn ($p) => $p->where('client_id', $id)))
            ->when($request->integer('project'), fn ($q, $id) => $q->where('project_id', $id))
            ->when($range, fn ($q) => $q->whereDate('paid_on', '>=', $range->from)->whereDate('paid_on', '<=', $range->to));

        $totalsByCurrency = (clone $query)
            ->setEagerLoads([])
            ->join('projects', 'projects.id', '=', 'payments.project_id')
            ->selectRaw('projects.currency as currency, SUM(payments.amount) as total, COUNT(*) as count')
            ->groupBy('projects.currency')
            ->reorder()
            ->get();

        return view('payments.index', [
            'payments' => $query->latest('paid_on')->latest('id')->paginate(25)->withQueryString(),
            'totalsByCurrency' => $totalsByCurrency,
            'range' => $range,
            'currencies' => Project::query()->distinct()->orderBy('currency')->pluck('currency'),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * "Record payment" from a project: choose the open invoice or segment the money is for.
     */
    public function chooseForProject(Project $project): View
    {
        return view('payments.choose', [
            'project' => $project->load('client'),
            'invoices' => $project->invoices()->open()->latest('issued_on')->get(),
            'segments' => $project->segments()->withSum('allocations', 'amount')->orderBy('id')->get(),
        ]);
    }

    public function createForSegment(Segment $segment): View
    {
        $payment = new Payment(['paid_on' => today(), 'amount' => max(0, $segment->balance())]);

        return view('payments.form', [
            'payment' => $payment,
            'project' => $segment->project,
            'segment' => $segment,
            'invoice' => null,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storeForSegment(Request $request, Segment $segment): RedirectResponse
    {
        $saved = $this->recorder->save(new Payment, $segment->project, null, $this->validated($request), null, $segment);

        return redirect()->route('projects.show', $segment->project_id)
            ->with('status', $saved ? 'Payment recorded.' : 'This payment was already recorded.');
    }

    public function createForInvoice(Invoice $invoice): View
    {
        $payment = new Payment(['paid_on' => today(), 'amount' => $invoice->balance()]);

        return view('payments.form', [
            'payment' => $payment,
            'project' => $invoice->project,
            'segment' => null,
            'invoice' => $invoice,
            'segments' => $invoice->project->segments()->orderBy('id')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storeForInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        $saved = $this->recorder->save(new Payment, $invoice->project, $invoice, $this->validated($request), $this->allocations($request));

        return redirect()->route('invoices.show', $invoice)
            ->with('status', $saved ? 'Payment recorded.' : 'This payment was already recorded.');
    }

    /**
     * Record a payment for exactly the remaining balance.
     */
    public function markPaid(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        if ($invoice->balance() <= 0) {
            return back()->with('status', 'This invoice is already paid.');
        }

        $data['amount'] = $invoice->balance();
        $saved = $this->recorder->save(new Payment, $invoice->project, $invoice, $data, null);

        return redirect()->route('invoices.show', $invoice)
            ->with('status', $saved ? 'Invoice marked as paid.' : 'This payment was already recorded.');
    }

    public function edit(Payment $payment): View
    {
        $payment->load(['project', 'invoice', 'allocations']);

        return view('payments.form', [
            'payment' => $payment,
            'project' => $payment->project,
            'segment' => $payment->invoice ? null : $payment->allocations->first()?->segment,
            'invoice' => $payment->invoice,
            'segments' => $payment->project->segments()->orderBy('id')->get(),
            'idempotencyKey' => null,
        ]);
    }

    public function update(Request $request, Payment $payment): RedirectResponse
    {
        $segment = $payment->invoice ? null : $payment->allocations()->first()?->segment;
        $allocations = $payment->invoice ? $this->allocations($request) : null;

        $this->recorder->save($payment, $payment->project, $payment->invoice, $this->validated($request), $allocations, $segment);

        return $this->redirectFor($payment)->with('status', 'Payment updated.');
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $redirect = $this->redirectFor($payment);
        $this->recorder->delete($payment);

        return $redirect->with('status', 'Payment removed. Balances recalculated.');
    }

    private function redirectFor(Payment $payment): RedirectResponse
    {
        return $payment->invoice_id
            ? redirect()->route('invoices.show', $payment->invoice_id)
            : redirect()->route('projects.show', $payment->project_id);
    }

    /**
     * @return array{amount: int, paid_on: string, method: string, reference: ?string, notes: ?string, idempotency_key: ?string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX, 'not_regex:/^0+(\.0+)?$/'],
            'paid_on' => ['required', 'date'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['nullable', 'uuid'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
        ], [
            'amount.regex' => 'Enter a valid amount with up to 2 decimals.',
            'amount.not_regex' => 'The amount must be greater than zero.',
            'allocations.*.regex' => 'Allocations must be valid amounts.',
        ]);

        $data['amount'] = Money::toMinor($data['amount']);
        $data['idempotency_key'] ??= null;
        unset($data['allocations']);

        return $data;
    }

    /**
     * Manual allocations if any field was filled in; null means auto-allocate.
     *
     * @return array<int, int>|null
     */
    private function allocations(Request $request): ?array
    {
        $raw = collect($request->input('allocations', []))->filter(fn ($value) => trim((string) $value) !== '');

        if ($raw->isEmpty()) {
            return null;
        }

        return $raw->mapWithKeys(fn ($value, $segmentId) => [(int) $segmentId => Money::toMinor($value)])->all();
    }
}
