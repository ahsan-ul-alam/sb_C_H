<?php

namespace App\Http\Controllers;

use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\InventoryValueMovement;
use App\Models\SourcingAllocation;
use App\Models\SourcingLot;
use App\Services\InventoryEngine;
use App\Services\InventoryHandoverService;
use App\Services\InventoryReconciliation;
use App\Services\InventoryValuation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Client handover (transfer of control): request, evidence, approval, history and returns. The accounting happens in InventoryHandoverService; this only
 * collects the input and shows the state. A request needs inventory.handover.propose and a decision needs inventory.handover.approve (Board), and no one can
 * decide their own request.
 */
class InventoryHandoverController extends Controller
{
    public function index(): View
    {
        $lotIds = SourcingLot::where('ownership', 'company')->get()->filter(fn (SourcingLot $lot) => InventoryEngine::isValued($lot))->pluck('id');
        $allocations = SourcingAllocation::with(['lot', 'project.client', 'line'])->where('kind', 'sale')->whereIn('sourcing_lot_id', $lotIds)->orderBy('id')->get()
            ->map(fn (SourcingAllocation $a) => ['allocation' => $a, 'eligible' => InventoryHandoverService::eligibleQuantity($a), 'valued' => InventoryValuation::lineFigures($a->line)['quantity']])
            ->filter(fn ($r) => bccomp($r['eligible'], '0', 2) > 0)->values();

        return view('sourcing.handovers.index', [
            'eligible' => $allocations,
            'pending' => InventoryHandover::with(['project.client', 'lot', 'line', 'preparer'])->where('status', 'pending')->orderBy('id')->get(),
            'pendingReturns' => InventoryHandoverReturn::with(['handover.project.client', 'handover.line', 'preparer'])->where('status', 'pending')->orderBy('id')->get(),
            'history' => InventoryHandover::with(['project.client', 'lot', 'line', 'returns'])->whereIn('status', ['approved', 'rejected', 'cancelled'])->latest('id')->limit(100)->get(),
            'reconciliation' => InventoryReconciliation::handovers(),
        ]);
    }

    public function show(InventoryHandover $handover): View
    {
        $handover->load(['project.client', 'lot', 'line', 'preparer', 'approver', 'returns.preparer', 'journalEntry.lines']);

        return view('sourcing.handovers.show', [
            'handover' => $handover,
            'movements' => InventoryValueMovement::where(fn ($q) => $q->where('source_type', 'inventory_handover')->where('source_id', $handover->id)
                ->orWhere(fn ($r) => $r->where('source_type', 'inventory_handover_return')->whereIn('source_id', $handover->returns->pluck('id'))))->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sourcing_allocation_id' => ['required', 'exists:sourcing_allocations,id'],
            'quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'proposed_on' => ['required', 'date'],
            'transferred_on' => ['required', 'date', 'before_or_equal:today'],
            'evidence_reference' => ['required', 'string', 'min:5', 'max:255'],
            'evidence_note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        return $this->attempt(fn () => InventoryHandoverService::propose(SourcingAllocation::findOrFail($data['sourcing_allocation_id']), $data['quantity'], $data['proposed_on'], $data['transferred_on'],
            $data['evidence_reference'], $data['evidence_note'] ?? null, $data['idempotency_key'] ?? (string) Str::uuid(), $request->user()), 'Handover request recorded; a Board member must approve it. Nothing is posted until then.');
    }

    public function cancel(Request $request, InventoryHandover $handover): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => InventoryHandoverService::cancel($handover, $request->user(), $data['reason']), 'Request cancelled.');
    }

    public function decide(Request $request, InventoryHandover $handover): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => InventoryHandoverService::decide($handover, $approve, $request->user(), $request->input('reason')), $approve ? 'Approved: control has passed, the cost of goods sold is posted and the stock is released.' : 'Rejected.');
    }

    public function storeReturn(Request $request, InventoryHandover $handover): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'condition' => ['required', 'in:resalable,damaged'],
            'returned_on' => ['required', 'date', 'before_or_equal:today'],
            'evidence_reference' => ['required', 'string', 'min:5', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        return $this->attempt(fn () => InventoryHandoverService::proposeReturn($handover, $data['quantity'], $data['condition'], $data['returned_on'], $data['evidence_reference'], $data['reason'],
            $data['idempotency_key'] ?? (string) Str::uuid(), $request->user()), 'Return recorded; a Board member must approve it. Nothing is posted until then.');
    }

    public function cancelReturn(Request $request, InventoryHandoverReturn $handoverReturn): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => InventoryHandoverService::cancelReturn($handoverReturn, $request->user(), $data['reason']), 'Return cancelled.');
    }

    public function decideReturn(Request $request, InventoryHandoverReturn $handoverReturn): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => InventoryHandoverService::decideReturn($handoverReturn, $approve, $request->user(), $request->input('reason')), $approve ? 'Approved.' : 'Rejected.');
    }
}
