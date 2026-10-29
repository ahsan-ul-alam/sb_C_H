<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\Project;
use App\Support\ListFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        ListFilters::remember($request, 'projects');
        $search = trim((string) $request->query('q'));

        $projects = Project::query()
            ->with('client')
            ->withSum('segments', 'amount')
            ->withSum('payments', 'amount')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->when(ProjectStatus::tryFrom((string) $request->query('status')), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('currency'), fn ($q, $currency) => $q->where('currency', $currency))
            ->when($request->integer('client'), fn ($q, $clientId) => $q->where('client_id', $clientId))
            ->when($request->boolean('archived'), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->latest('started_on')->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('projects.index', [
            'projects' => $projects,
            'currencies' => Project::query()->distinct()->orderBy('currency')->pluck('currency'),
            'filterClient' => $request->integer('client') ? Client::find($request->integer('client')) : null,
        ]);
    }

    /**
     * Entry point for "New project" without a client: pick one, then continue to the form.
     */
    public function start(Request $request): View|RedirectResponse
    {
        if ($client = Client::find($request->integer('client_id'))) {
            return redirect()->route('clients.projects.create', $client);
        }

        return view('projects.start', ['clients' => Client::whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'mobile'])]);
    }

    public function create(Client $client): View
    {
        $project = new Project([
            'status' => ProjectStatus::Active,
            'currency' => BusinessSetting::current()->default_currency,
            'started_on' => today(),
        ]);

        return view('projects.form', compact('client', 'project'));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $project = $client->projects()->create($this->validated($request));

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    public function show(Project $project): View
    {
        $project->load('client');

        $segments = $project->segments()
            ->withSum('allocations', 'amount')
            ->with(['allocations.payment.invoice'])
            ->orderBy('id')
            ->get();

        $payments = $project->payments()
            ->with(['invoice', 'allocations.segment'])
            ->latest('paid_on')
            ->latest('id')
            ->get();

        $invoices = $project->invoices()->latest('issued_on')->latest('id')->get();

        return view('projects.show', [
            'project' => $project,
            'segments' => $segments,
            'payments' => $payments,
            'invoices' => $invoices,
            'totals' => $project->totals(),
        ]);
    }

    public function edit(Project $project): View
    {
        return view('projects.form', ['client' => $project->client, 'project' => $project]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $data = $this->validated($request);

        if ($data['currency'] !== $project->currency && ! $project->canBeDeleted()) {
            return back()->withInput()->withErrors(['currency' => 'Currency cannot be changed after invoices or payments exist.']);
        }

        $project->update($data);

        return redirect()->route('projects.show', $project)->with('status', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        if (! $project->canBeDeleted()) {
            return back()->with('error', 'This project has invoices or payments and cannot be deleted. Archive it instead.');
        }

        $client = $project->client;
        $project->segments()->delete();
        $project->delete();

        return redirect()->route('clients.show', $client)->with('status', 'Project deleted.');
    }

    public function toggleArchive(Project $project): RedirectResponse
    {
        $project->archived_at = $project->isArchived() ? null : now();
        $project->save();

        return back()->with('status', $project->isArchived() ? 'Project archived.' : 'Project restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'started_on' => ['required', 'date'],
        ]);
    }
}
