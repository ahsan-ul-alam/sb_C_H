<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\LotOwnershipRequest;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ReceiptReview;
use App\Models\ReconciliationException;
use App\Models\SourcingLot;
use App\Services\AccountActivation;
use App\Services\HistoricalReconciliation;
use App\Services\OwnershipRequests;
use App\Services\ReceiptReviews;
use App\Services\ReconciliationExceptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Historical reconciliation preparation. Thin on purpose: every rule is in a service, and nothing here posts to the ledger, edits a payment or changes a posted journal.
 */
class HistoricalReconciliationController extends Controller
{
    public function index(): View
    {
        $receipts = HistoricalReconciliation::receipts();

        return view('finance.historical', ['requirements' => HistoricalReconciliation::accountRequirements(), 'receipts' => $receipts, 'summary' => HistoricalReconciliation::summary($receipts),
            'sequence' => HistoricalReconciliation::cutoverSequence(), 'cashLines' => HistoricalReconciliation::cashLines(), 'cash' => HistoricalReconciliation::cashPosition(), 'lots' => HistoricalReconciliation::unresolvedLots(),
            'voids' => ReconciliationExceptions::voidedPayments(), 'exceptions' => ReconciliationException::orderBy('status')->orderBy('id')->get(), 'natures' => ReceiptReviews::NATURES,
            'projects' => Project::with('client:id,name')->orderBy('name')->get(['id', 'name', 'client_id']), 'accountTypes' => FinancialAccount::TYPES]);
    }

    public function accountPrepare(Request $request): RedirectResponse
    {
        $d = $request->validate(['type' => ['required', Rule::in(array_keys(FinancialAccount::TYPES))], 'name' => ['required', 'string', 'max:100']]);

        return $this->attempt(fn () => AccountActivation::prepare($d['type'], $d['name'], $request->user()), 'Account prepared. It stays inactive until its identity, opening date and statement are confirmed.');
    }

    public function accountConfirm(Request $request, FinancialAccount $account): RedirectResponse
    {
        $d = $request->validate(['identity_reference' => ['required', 'string', 'max:255'], 'opening_date' => ['required', 'date', 'before_or_equal:today']]);

        return $this->attempt(fn () => AccountActivation::confirmIdentity($account, $d['identity_reference'], $d['opening_date'], $request->user()), 'Identity and opening date recorded. A verified statement and a second person are still needed to activate it.');
    }

    public function accountActivate(Request $request, FinancialAccount $account): RedirectResponse
    {
        return $this->attempt(fn () => AccountActivation::activate($account, $request->user()), 'Account activated.');
    }

    /** Propose classifications for the receipts Finance ticked; each needs the owner's confirmation reference. */
    public function receiptPropose(Request $request): RedirectResponse
    {
        $d = $request->validate(['rows' => ['required', 'array', 'min:1'], 'rows.*.payment_id' => ['required', 'exists:payments,id'], 'rows.*.nature' => ['nullable', Rule::in(array_keys(ReceiptReviews::NATURES))],
            'rows.*.selected' => ['nullable'], 'owner_confirmed_on' => ['required', 'date', 'before_or_equal:today'], 'owner_reference' => ['required', 'string', 'max:255']]);
        $chosen = collect($d['rows'])->filter(fn ($r) => ! empty($r['selected']) && ! empty($r['nature']));

        return $this->attempt(function () use ($chosen, $d, $request) {
            if ($chosen->isEmpty()) {
                throw new \RuntimeException('Tick at least one receipt and choose its proposed classification.');
            }
            foreach ($chosen as $r) {
                ReceiptReviews::propose(Payment::findOrFail($r['payment_id']), $r['nature'], $d['owner_confirmed_on'], $d['owner_reference'], $request->user());
            }
        }, 'Proposals recorded. They need a review and an approval by different people before anything is applied.');
    }

    public function receiptReview(Request $request, ReceiptReview $review): RedirectResponse
    {
        return $this->attempt(fn () => ReceiptReviews::review($review, $request->user()), 'Reviewed.');
    }

    public function receiptApprove(Request $request, ReceiptReview $review): RedirectResponse
    {
        return $this->attempt(fn () => ReceiptReviews::approve($review, $request->user()), 'Approved. It can now be applied; applying posts nothing to the ledger.');
    }

    public function receiptApply(Request $request, ReceiptReview $review): RedirectResponse
    {
        return $this->attempt(fn () => ReceiptReviews::apply($review, $request->user()), 'Classification applied. No ledger entry was made and the payment was not changed.');
    }

    public function receiptReject(Request $request, ReceiptReview $review): RedirectResponse
    {
        $d = $request->validate(['note' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => ReceiptReviews::reject($review, $d['note'], $request->user()), 'Proposal rejected.');
    }

    public function exceptionsRegister(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request) {
            $n = ReconciliationExceptions::register($request->user());
            session()->flash('status', $n === 0 ? 'Every open item is already registered.' : $n.' open item(s) registered. Nothing was posted or created.');
        }, 'Done.');
    }

    public function exceptionResolve(Request $request, ReconciliationException $exception): RedirectResponse
    {
        $d = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => ReconciliationExceptions::resolve($exception, $d['note'], $request->user()), 'Marked resolved with your note.');
    }

    public function ownershipPropose(Request $request, SourcingLot $lot): RedirectResponse
    {
        $d = $request->validate(['ownership' => ['required', Rule::in(['company', 'client'])], 'project_id' => ['nullable', 'exists:projects,id'], 'evidence_note' => ['required', 'string', 'max:500']]);

        return $this->attempt(fn () => OwnershipRequests::propose($lot, $d['ownership'], $d['project_id'] ?? null, $d['evidence_note'], $request->user()), 'Request recorded. A Board member must approve it.');
    }

    public function ownershipDecide(Request $request, LotOwnershipRequest $ownershipRequest): RedirectResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => OwnershipRequests::decide($ownershipRequest, $d['decision'] === 'approve', $d['note'] ?? null, $request->user()), 'Decision recorded.');
    }
}
