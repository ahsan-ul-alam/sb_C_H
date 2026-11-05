<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InventoryEngineEnrolment;
use App\Models\SourcingLot;
use App\Services\EnrolmentReadiness;
use App\Services\InventoryEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Bringing a Sellbees-owned lot into inventory valuation. Thin: the proposal, the independent Board decision, duplicate protection and valuation are all InventoryEngine's;
 * this screen adds only the facts a person needs and refuses a lot whose ownership evidence is missing. Enrolment posts no journal entry and moves no stock.
 */
class InventoryEnrolmentController extends Controller
{
    public function index(): View
    {
        return view('sourcing.enrolment', ['rows' => EnrolmentReadiness::lots(), 'engineFrom' => InventoryEngine::engineFrom(),
            'history' => AuditLog::with('user')->where('action', 'like', 'inventory.engine_%')->latest('id')->limit(100)->get()]);
    }

    public function store(Request $request, SourcingLot $lot): RedirectResponse
    {
        $d = $request->validate(['classification_source' => ['required', 'string', 'min:10', 'max:255'], 'effective_on' => ['required', 'date']]);

        return $this->attempt(function () use ($lot, $d, $request) {
            $readiness = EnrolmentReadiness::for($lot);
            if ($readiness['blockers']) {
                throw new RuntimeException($readiness['blockers'][0]);
            }
            InventoryEngine::propose($lot, 'company', $d['classification_source'], $d['effective_on'], $request->user());
        }, 'Enrolment requested. A Board member other than you must approve it; nothing is valued or posted until then.');
    }

    public function decide(Request $request, InventoryEngineEnrolment $enrolment): RedirectResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:255']]);
        $approve = $d['decision'] === 'approve';

        return $this->attempt(fn () => InventoryEngine::decide($enrolment, $approve, $request->user(), $d['note'] ?? null),
            $approve ? 'Approved: the lot is now valued as Sellbees-owned stock as goods are accepted and settled.' : 'Rejected. Finance can request it again with better evidence.');
    }
}
