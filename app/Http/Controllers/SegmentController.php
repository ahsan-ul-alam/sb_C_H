<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Segment;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SegmentController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $project->segments()->create($this->validated($request));

        return redirect()->route('projects.show', $project)->with('status', 'Segment added.');
    }

    public function edit(Segment $segment): View
    {
        return view('segments.edit', ['segment' => $segment, 'project' => $segment->project]);
    }

    public function update(Request $request, Segment $segment): RedirectResponse
    {
        $data = $this->validated($request);
        $received = $segment->receivedAmount();

        if ($data['amount'] < $received) {
            return back()->withInput()->withErrors([
                'amount' => 'Amount cannot be less than what was already received ('.Money::format($received, $segment->project->currency).').',
            ]);
        }

        $segment->update($data);

        return redirect()->route('projects.show', $segment->project_id)->with('status', 'Segment updated.');
    }

    public function destroy(Segment $segment): RedirectResponse
    {
        if (! $segment->canBeDeleted()) {
            return back()->with('error', 'This segment has payments or invoice items and cannot be deleted.');
        }

        $segment->delete();

        return redirect()->route('projects.show', $segment->project_id)->with('status', 'Segment deleted.');
    }

    /**
     * @return array{name: string, description: ?string, amount: int}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX, 'not_regex:/^0+(\.0+)?$/'],
        ], ['amount.not_regex' => 'The amount must be greater than zero.']);

        $data['amount'] = Money::toMinor($data['amount']);

        return $data;
    }
}
