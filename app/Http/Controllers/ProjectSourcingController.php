<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Services\ProjectFinancialOverview;
use App\Services\ProjectSourcingReport;
use App\Services\SourcingArrival;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectSourcingController extends Controller
{
    public function index(): View
    {
        $report = ProjectSourcingReport::build();

        return view('sourcing.project-margins', [
            'projects' => $report['projects'], 'lots' => $report['lots'],
            'names' => Project::with('client')->whereIn('id', array_keys($report['projects']))->get()->keyBy('id'),
        ]);
    }

    public function show(Project $project): View
    {
        $lots = SourcingLot::where('status', '!=', 'cancelled')->where(fn ($q) => $q->where('project_id', $project->id)->orWhereHas('allocations', fn ($a) => $a->where('project_id', $project->id)))->orderBy('id')->get();

        return view('sourcing.project-overview', ['o' => ProjectFinancialOverview::for($project->load('client')), 'lots' => $lots]);
    }

    public function arrival(Request $request, SourcingLotLine $line): RedirectResponse
    {
        $data = $request->validate([
            'received_quantity' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'accepted_quantity' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'arrived_on' => ['required', 'date', 'before_or_equal:today'],
            'acceptance_reference' => ['nullable', 'string', 'max:191'],
        ]);

        return $this->attempt(fn () => SourcingArrival::record($line, $data['received_quantity'], $data['accepted_quantity'] ?? null, $data['arrived_on'], $request->user(), $data['acceptance_reference'] ?? null), 'Arrival recorded.');
    }
}
