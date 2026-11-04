<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\InventoryValueMovement;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnSettlement;
use App\Services\Inventory;
use App\Services\InventoryEngine;
use App\Services\InventoryReconciliation;
use App\Services\SupplierReturnService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Supplier returns: request, the supplier's answer, Finance verification and allocation, Board approval, and the later credit applications and refunds. The accounting
 * happens in SupplierReturnService; this collects the input and shows the state, with payable, credit, receivable and cash kept as separate categories.
 */
class SupplierReturnController extends Controller
{
    public function index(): View
    {
        $lotIds = SourcingLot::where('ownership', 'company')->get()->filter(fn (SourcingLot $lot) => InventoryEngine::isValued($lot))->pluck('id');
        $lines = SourcingLotLine::with(['lot', 'supplier'])->whereIn('sourcing_lot_id', $lotIds)->whereNotNull('supplier_id')->orderBy('id')->get()
            ->map(fn (SourcingLotLine $l) => ['line' => $l, 'sellable' => bcsub(Inventory::sliceBalances($l, null)['available'], SupplierReturnService::committedUnits($l), 2),
                'rejected' => $l->received_quantity === null ? '0.00' : bcsub((string) $l->received_quantity, (string) $l->usable_quantity, 2)])
            ->filter(fn ($r) => bccomp($r['sellable'], '0', 2) > 0 || bccomp($r['rejected'], '0', 2) > 0)->values();
        $approved = SupplierReturn::with(['supplier', 'line', 'settlements'])->where('status', 'approved')->where('kind', 'accepted')->orderBy('id')->get()
            ->map(fn (SupplierReturn $r) => ['return' => $r, 'b' => SupplierReturnService::balances($r)]);

        return view('sourcing.supplier-returns.index', [
            'lines' => $lines,
            'open' => SupplierReturn::with(['supplier', 'line', 'preparer', 'verifier', 'acceptor'])->whereIn('status', SupplierReturn::ACTIVE)->orderBy('id')->get(),
            'approved' => $approved,
            'pendingSettlements' => SupplierReturnSettlement::with(['supplierReturn.supplier', 'preparer'])->where('status', 'pending')->orderBy('id')->get(),
            'history' => SupplierReturn::with(['supplier', 'line'])->whereIn('status', ['approved', 'rejected', 'cancelled'])->latest('id')->limit(100)->get(),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(),
            'reconciliation' => InventoryReconciliation::supplierReturns(),
        ]);
    }

    public function show(SupplierReturn $supplierReturn): View
    {
        $supplierReturn->load(['supplier', 'lot', 'line', 'preparer', 'acceptor', 'verifier', 'approver', 'journalEntry.lines', 'settlements.preparer', 'settlements.approver', 'settlements.journalEntry']);

        return view('sourcing.supplier-returns.show', [
            'return' => $supplierReturn, 'b' => SupplierReturnService::balances($supplierReturn),
            'movements' => InventoryValueMovement::where('source_type', 'supplier_return')->where('source_id', $supplierReturn->id)->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sourcing_lot_line_id' => ['required', 'exists:sourcing_lot_lines,id'], 'kind' => ['required', 'in:accepted,unaccepted'], 'quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:255'], 'return_reference' => ['required', 'string', 'min:3', 'max:120'], 'physical_returned_on' => ['required', 'date', 'before_or_equal:today'],
            'evidence_reference' => ['required', 'string', 'min:5', 'max:255'], 'claimed_amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'expected_method' => ['nullable', 'in:payable,credit_note,refund,mixed'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $data['claimed_amount'] = ($data['claimed_amount'] ?? '') === '' ? 0 : Money::toMinor($data['claimed_amount']);

        return $this->attempt(fn () => SupplierReturnService::propose($request->user(), SourcingLotLine::findOrFail($data['sourcing_lot_line_id']), $data, $data['idempotency_key'] ?? (string) Str::uuid()),
            'Return recorded. Nothing is posted until the supplier\'s answer is recorded, Finance verifies it and a Board member approves it.');
    }

    public function accept(Request $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $data = $request->validate(['acceptance' => ['required', 'in:accepted,partial,disputed,rejected'], 'accepted_amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'supplier_document' => ['nullable', 'string', 'max:120'], 'acceptance_note' => ['nullable', 'string', 'max:255']]);
        $amount = ($data['accepted_amount'] ?? '') === '' ? null : Money::toMinor($data['accepted_amount']);

        return $this->attempt(fn () => SupplierReturnService::recordAcceptance($supplierReturn, $request->user(), $data['acceptance'], $amount, $data['supplier_document'] ?? null, $data['acceptance_note'] ?? null), 'The supplier\'s answer is recorded.');
    }

    public function verify(Request $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $data = $request->validate(['verify_note' => ['required', 'string', 'max:255'], 'payable_reduction' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'credit_note_amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX],
            'receivable_amount' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'difference_reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => SupplierReturnService::verify($supplierReturn, $request->user(), $data['verify_note'], Money::toMinor($data['payable_reduction'] ?? 0), Money::toMinor($data['credit_note_amount'] ?? 0),
            Money::toMinor($data['receivable_amount'] ?? 0), $data['difference_reason'] ?? null), 'Verified. A Board member can now decide it.');
    }

    public function decide(Request $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => SupplierReturnService::decide($supplierReturn, $approve, $request->user(), $request->input('reason')), $approve ? 'Approved and posted.' : 'Rejected.');
    }

    public function cancel(Request $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => SupplierReturnService::cancel($supplierReturn, $request->user(), $data['reason']), 'Return cancelled.');
    }

    public function settle(Request $request, SupplierReturn $supplierReturn): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', 'in:credit_applied,cash_refund'], 'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'reference' => ['required', 'string', 'max:255'],
            'settled_on' => ['required', 'date', 'before_or_equal:today'], 'financial_account_id' => ['nullable', 'exists:financial_accounts,id'], 'idempotency_key' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => SupplierReturnService::proposeSettlement($supplierReturn, $request->user(), $data['method'], Money::toMinor($data['amount']), $data['reference'], $data['settled_on'],
            isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null, $data['idempotency_key'] ?? (string) Str::uuid()), 'Recorded. A Board member must approve it before anything is posted.');
    }

    public function decideSettlement(Request $request, SupplierReturnSettlement $settlement): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => SupplierReturnService::decideSettlement($settlement, $approve, $request->user(), $request->input('reason')), $approve ? 'Approved and posted.' : 'Rejected.');
    }
}
