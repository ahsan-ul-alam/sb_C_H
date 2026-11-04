<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\ImpairedStockEvent;
use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\InventoryLossCase;
use App\Models\InventoryRevaluation;
use App\Models\InventoryValueMovement;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Services\ImpairedStockService;
use App\Services\Inventory;
use App\Services\InventoryCustody;
use App\Services\InventoryEngine;
use App\Services\InventoryLossService;
use App\Services\InventoryReconciliation;
use App\Services\InventoryRevaluationService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Damage, impairment and write-off cases, the ownership and custody position, and return inspection findings. The accounting happens in InventoryLossService;
 * this collects the input and shows the state. Proposing needs inventory.loss.propose (Inventory/Operations), the review inventory.loss.review (Finance) and the
 * decision inventory.loss.approve (Board); the service refuses a person who proposed or reviewed the case.
 */
class InventoryLossController extends Controller
{
    public function index(): View
    {
        $lotIds = SourcingLot::where('ownership', 'company')->get()->filter(fn (SourcingLot $lot) => InventoryEngine::isValued($lot))->pluck('id');
        $handovers = InventoryHandover::with(['project.client', 'line', 'lot'])->where('status', 'approved')->orderBy('id')->get();

        return view('sourcing.losses.index', [
            'lines' => SourcingLotLine::with('lot')->whereIn('sourcing_lot_id', $lotIds)->orderBy('id')->get()
                ->map(fn (SourcingLotLine $l) => ['line' => $l, 'own' => Inventory::sliceBalances($l, null)])->filter(fn ($r) => bccomp($r['own']['available'], '0', 2) > 0 || bccomp($r['own']['damaged'], '0', 2) > 0)->values(),
            'returns' => InventoryHandoverReturn::with(['handover.project.client', 'handover.line'])->where('status', 'approved')->where('condition', 'damaged')->orderBy('id')->get()
                ->filter(fn (InventoryHandoverReturn $r) => bccomp((string) $r->quantity, (string) InventoryLossCase::where('inventory_handover_return_id', $r->id)->whereIn('status', [...InventoryLossCase::ACTIVE, 'approved'])->sum('quantity'), 2) > 0)->values(),
            'custody' => $handovers->where('custody_mode', 'warehouse')->values(),
            'ownership' => $handovers->map(fn (InventoryHandover $h) => ['handover' => $h, 'check' => InventoryCustody::check($h)])->values(),
            'open' => InventoryLossCase::with(['line', 'preparer', 'reviewer', 'handover.project.client'])->whereIn('status', InventoryLossCase::ACTIVE)->orderBy('id')->get(),
            'history' => InventoryLossCase::with(['line', 'approver'])->whereIn('status', ['approved', 'rejected', 'cancelled'])->latest('id')->limit(100)->get(),
            'reconciliation' => InventoryReconciliation::losses(),
            'impaired' => InventoryLossCase::with('line')->where('status', 'approved')->whereIn('outcome', ['impaired', 'repairable'])->where('scope', '!=', 'custody')->get()
                ->map(fn (InventoryLossCase $c) => ['case' => $c, 'left' => bcsub((string) $c->quantity, ImpairedStockService::used($c), 2)])->filter(fn ($r) => bccomp($r['left'], '0', 2) > 0)->values(),
            'impairedPending' => ImpairedStockEvent::with(['lossCase.line', 'preparer'])->where('status', 'pending')->orderBy('id')->get(),
            'revaluations' => InventoryRevaluation::with(['line', 'preparer', 'reviewer'])->latest('id')->limit(50)->get(),
            'valuedLines' => SourcingLotLine::with('lot')->whereIn('sourcing_lot_id', $lotIds)->orderBy('id')->get(),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(InventoryLossCase $case): View
    {
        $case->load(['line', 'lot', 'handover.project.client', 'handoverReturn', 'preparer', 'reviewer', 'approver', 'journalEntry.lines']);

        return view('sourcing.losses.show', [
            'case' => $case,
            'movements' => InventoryValueMovement::where('source_type', 'inventory_loss_case')->where('source_id', $case->id)->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:owned,return,custody'],
            'sourcing_lot_line_id' => ['nullable', 'integer'], 'from_bucket' => ['nullable', 'in:available,damaged'],
            'inventory_handover_return_id' => ['nullable', 'integer'], 'inventory_handover_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'outcome' => ['nullable', 'in:repairable,impaired,write_off'], 'classification' => ['nullable', 'in:normal,abnormal'],
            'cause' => ['required', 'string', 'max:255'], 'evidence_reference' => ['required', 'string', 'min:5', 'max:255'], 'assessment_note' => ['nullable', 'string', 'max:255'],
            'recoverable_value' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'valuation_basis' => ['nullable', 'string', 'max:255'],
            'event_on' => ['nullable', 'date', 'before_or_equal:today'], 'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $data['recoverable_value'] = ($data['recoverable_value'] ?? '') === '' ? null : Money::toMinor($data['recoverable_value']);

        return $this->attempt(fn () => InventoryLossService::propose($request->user(), $data, $data['idempotency_key'] ?? (string) Str::uuid()),
            'Case recorded. Finance must review it and a Board member decide it; nothing is posted until then.');
    }

    public function review(Request $request, InventoryLossCase $case): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'max:255'], 'recoverable_value' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'valuation_basis' => ['nullable', 'string', 'max:255']]);
        $value = ($data['recoverable_value'] ?? '') === '' ? null : Money::toMinor($data['recoverable_value']);

        return $this->attempt(fn () => InventoryLossService::review($case, $request->user(), $data['review_note'], $value, $data['valuation_basis'] ?? null), 'Reviewed. A Board member can now decide it.');
    }

    public function decide(Request $request, InventoryLossCase $case): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => InventoryLossService::decide($case, $approve, $request->user(), $request->input('reason')), $approve ? 'Approved and posted.' : 'Rejected.');
    }

    public function cancel(Request $request, InventoryLossCase $case): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => InventoryLossService::cancel($case, $request->user(), $data['reason']), 'Case cancelled.');
    }

    public function impairedStore(Request $request, InventoryLossCase $case): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:release,dispose'], 'quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'], 'event_on' => ['required', 'date', 'before_or_equal:today'], 'reference' => ['required', 'string', 'min:5', 'max:255'],
            'evidence_reference' => ['required', 'string', 'min:5', 'max:255'], 'proceeds' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'financial_account_id' => ['nullable', 'exists:financial_accounts,id'], 'idempotency_key' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => ImpairedStockService::propose($request->user(), $case, $data['action'], $data['quantity'], $data['event_on'], $data['reference'], $data['evidence_reference'], Money::toMinor($data['proceeds'] ?? 0),
            isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null, $data['idempotency_key'] ?? (string) Str::uuid()), 'Recorded. A Board member must approve it before anything is posted.');
    }

    public function impairedDecide(Request $request, ImpairedStockEvent $event): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => ImpairedStockService::decide($event, $approve, $request->user(), $request->input('reason')), $approve ? 'Approved and posted.' : 'Rejected.');
    }

    public function revalStore(Request $request): RedirectResponse
    {
        $data = $request->validate(['sourcing_lot_line_id' => ['required', 'exists:sourcing_lot_lines,id'], 'direction' => ['required', 'in:write_down,reversal'], 'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'basis' => ['required', 'string', 'min:5', 'max:255'], 'evidence_reference' => ['required', 'string', 'min:5', 'max:255'], 'effective_on' => ['required', 'date', 'before_or_equal:today'], 'idempotency_key' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => InventoryRevaluationService::propose($request->user(), SourcingLotLine::findOrFail($data['sourcing_lot_line_id']), $data['direction'], Money::toMinor($data['amount']), $data['basis'], $data['evidence_reference'],
            $data['effective_on'], $data['idempotency_key'] ?? (string) Str::uuid()), 'Recorded. Finance must review it and a Board member decide it; nothing is posted until then.');
    }

    public function revalReview(Request $request, InventoryRevaluation $revaluation): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => InventoryRevaluationService::review($revaluation, $request->user(), $data['review_note']), 'Reviewed.');
    }

    public function revalDecide(Request $request, InventoryRevaluation $revaluation): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => InventoryRevaluationService::decide($revaluation, $approve, $request->user(), $request->input('reason')), $approve ? 'Approved and posted.' : 'Rejected.');
    }
}
