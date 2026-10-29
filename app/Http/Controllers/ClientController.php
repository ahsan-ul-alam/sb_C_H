<?php

namespace App\Http\Controllers;

use App\Enums\IdentificationType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\ListFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        ListFilters::remember($request, 'clients');
        $showArchived = $request->boolean('archived');

        $clients = Client::query()
            ->search($request->string('q')->toString())
            ->when($showArchived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->withCount('projects')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('clients.index', compact('clients', 'showArchived'));
    }

    public function create(): View
    {
        return view('clients.form', ['client' => new Client]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = Client::create($this->validated($request));

        return redirect()->route('clients.show', $client)->with('status', 'Client created.');
    }

    public function show(Request $request, Client $client): View
    {
        $tab = in_array($request->query('tab'), ['projects', 'invoices', 'payments'], true) ? $request->query('tab') : 'projects';

        $projects = $client->projects()
            ->withSum('segments', 'amount')
            ->withSum('payments', 'amount')
            ->withCount('invoices')
            ->orderByRaw('archived_at is not null')
            ->latest('started_on')
            ->get();

        $invoices = Invoice::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->with('project')
            ->latest('issued_on')->latest('id')
            ->get();

        $payments = Payment::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->with(['project', 'invoice'])
            ->latest('paid_on')->latest('id')
            ->get();

        $summaries = $projects->groupBy('currency')->map(fn ($group, $currency) => [
            'currency' => $currency,
            'charges' => (int) $group->sum('segments_sum_amount'),
            'received' => (int) $group->sum('payments_sum_amount'),
            'invoiced' => (int) $invoices->where('currency', $currency)->sum('total'),
            'invoice_balance' => (int) $invoices->where('currency', $currency)->sum(fn ($invoice) => $invoice->balance()),
        ])->sortKeys()->values();

        return view('clients.show', compact('client', 'projects', 'invoices', 'payments', 'summaries', 'tab'));
    }

    public function edit(Client $client): View
    {
        return view('clients.form', compact('client'));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validated($request));

        return redirect()->route('clients.show', $client)->with('status', 'Client updated.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        if (! $client->canBeDeleted()) {
            return back()->with('error', 'This client has projects and cannot be deleted. Archive it instead.');
        }

        $client->delete();

        return redirect()->route('clients.index')->with('status', 'Client deleted.');
    }

    public function toggleArchive(Client $client): RedirectResponse
    {
        $client->archived_at = $client->isArchived() ? null : now();
        $client->save();

        return back()->with('status', $client->isArchived() ? 'Client archived.' : 'Client restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'max:100'],
            'mobile' => ['required', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],
            'mobile_alt' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'id_type' => ['nullable', Rule::enum(IdentificationType::class)],
            'id_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
