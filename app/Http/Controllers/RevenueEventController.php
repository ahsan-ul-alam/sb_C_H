<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RevenueEvent;
use App\Services\AccrualRevenue;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Earned revenue, credit notes, refunds and invoice voids for the accrual engine, plus the principal/agent classification of each project. The accounting is in
 * AccrualRevenue; this collects the input and shows the state. Finance proposes (revenue.event.propose), a different Board member decides (revenue.event.approve).
 */
class RevenueEventController extends Controller
{
    public function index(): View
    {
        $projects = Project::with('client:id,name')->whereNull('archived_at')->orderBy('name')->get()->map(fn (Project $p) => ['project' => $p, 'b' => AccrualRevenue::balances($p)]);

        return view('finance.revenue-events', [
            'cutover' => AccrualRevenue::cutover(), 'projects' => $projects,
            'recognitions' => RevenueEvent::where('status', 'approved')->where('kind', 'recognition')->with('project')->latest('id')->limit(100)->get(),
            'invoices' => Invoice::with('project')->whereNull('voided_at')->latest('id')->limit(200)->get(),
            'open' => RevenueEvent::with(['project', 'preparer'])->where('status', 'pending')->orderBy('id')->get(),
            'history' => RevenueEvent::with(['project', 'approver'])->whereIn('status', ['approved', 'rejected'])->latest('id')->limit(100)->get(),
            'accounts' => FinancialAccount::where('is_active', true)->orderBy('name')->get(), 'reconciliation' => AccrualRevenue::reconcile(),
        ]);
    }

    public function classify(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate(['arrangement' => ['required', 'in:principal,agent'], 'evidence' => ['required', 'string', 'min:10', 'max:255']]);

        return $this->attempt(fn () => AccrualRevenue::setArrangement($project, $data['arrangement'], $data['evidence'], $request->user()), 'Classified.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['project_id' => ['required', 'exists:projects,id'], 'kind' => ['required', 'in:recognition,bill,credit_note,refund'], 'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'effective_on' => ['required', 'date', 'before_or_equal:today'], 'basis' => ['nullable', 'in:sale,service,commission,service_fee'], 'invoice_id' => ['nullable', 'exists:invoices,id'],
            'related_event_id' => ['nullable', 'exists:revenue_events,id'], 'inventory_handover_id' => ['nullable', 'exists:inventory_handovers,id'], 'inventory_handover_return_id' => ['nullable', 'exists:inventory_handover_returns,id'],
            'financial_account_id' => ['nullable', 'exists:financial_accounts,id'], 'evidence_reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:255'], 'idempotency_key' => ['nullable', 'uuid']]);
        $data['amount'] = Money::toMinor($data['amount']);

        return $this->attempt(fn () => AccrualRevenue::propose($request->user(), Project::findOrFail($data['project_id']), $data['kind'], array_filter($data, fn ($v) => $v !== null && $v !== ''), $data['idempotency_key'] ?? (string) Str::uuid()),
            'Recorded. A Board member must approve it; nothing is posted until then.');
    }

    public function decide(Request $request, RevenueEvent $event): RedirectResponse
    {
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => AccrualRevenue::decide($event, $approve, $request->user(), $request->input('reason')), $approve ? 'Approved and posted.' : 'Rejected.');
    }

    public function voidInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return $this->attempt(fn () => AccrualRevenue::voidInvoice($invoice, $data['reason'], $request->user()), 'Invoice voided.');
    }
}
