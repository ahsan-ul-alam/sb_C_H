<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Project;
use App\Services\OrderCommission;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderCommissionController extends Controller
{
    public function index(Request $request): View
    {
        return view('finance.orders', [
            'orders' => ClientOrder::with(['project.client', 'invoice'])->latest('ordered_on')->latest('id')->paginate(40),
            'projects' => Project::with('client')->whereNull('archived_at')->orderBy('name')->get(),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'commission_bps']),
            'defaultBps' => BusinessSetting::current()->commission_bps,
            'totals' => [
                'accrued' => (int) ClientOrder::where('status', 'successful')->whereNull('billed_invoice_id')->sum('commission'),
                'billed' => (int) ClientOrder::whereNotNull('billed_invoice_id')->sum('commission'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'], 'reference' => ['required', 'string', 'max:120'], 'ordered_on' => ['required', 'date'],
            'net_value' => ['required', 'regex:'.Money::AMOUNT_REGEX], 'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $project = Project::findOrFail($data['project_id']);
        unset($data['project_id']);
        $data['net_value'] = Money::toMinor($data['net_value']);

        return $this->attempt(fn () => OrderCommission::record($project, $data, $request->user()), 'Order recorded as pending.');
    }

    public function success(ClientOrder $order): RedirectResponse
    {
        return $this->attempt(fn () => OrderCommission::markSuccessful($order), 'Order marked successful; the commission rate is now fixed for this order.');
    }

    public function cancel(ClientOrder $order): RedirectResponse
    {
        return $this->attempt(fn () => OrderCommission::cancel($order), 'Order cancelled; no commission is due.');
    }

    public function adjust(Request $request, ClientOrder $order): RedirectResponse
    {
        $data = $request->validate(['net_value' => ['required', 'regex:'.Money::AMOUNT_REGEX]]);

        return $this->attempt(fn () => OrderCommission::adjustNet($order, Money::toMinor($data['net_value'])), 'Order value reduced; commission recalculated at the original rate.');
    }

    public function reduceInvoiced(Request $request, ClientOrder $order): RedirectResponse
    {
        $data = $request->validate(['net_value' => ['nullable', 'regex:'.Money::AMOUNT_REGEX], 'reason' => ['required', 'string', 'max:200']]);
        $net = ($data['net_value'] ?? '') !== '' ? Money::toMinor($data['net_value']) : null;

        return $this->attempt(fn () => OrderCommission::reduceInvoiced($order, $net, $data['reason']), 'Invoiced commission corrected and recorded in the audit log.');
    }

    public function bill(Request $request, ClientOrder $order): RedirectResponse
    {
        $data = $request->validate(['segment_id' => ['nullable', 'integer'], 'invoice_id' => ['nullable', 'integer']]);

        return $this->attempt(fn () => OrderCommission::bill($order, $data['segment_id'] ?? null, $data['invoice_id'] ?? null), 'Commission added to the client\'s billing.');
    }

    public function rates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_percent' => ['nullable', 'regex:/^\d{1,2}(\.\d{1,2})?$|^100(\.0{1,2})?$/'],
            'client_id' => ['nullable', 'exists:clients,id'], 'client_percent' => ['nullable', 'regex:/^\d{1,2}(\.\d{1,2})?$|^100(\.0{1,2})?$/'],
        ]);

        if (isset($data['default_percent'])) {
            OrderCommission::setDefaultRate((int) bcmul($data['default_percent'], '100', 0));
        }
        if (! empty($data['client_id'])) {
            OrderCommission::setClientRate(Client::findOrFail($data['client_id']), isset($data['client_percent']) ? (int) bcmul($data['client_percent'], '100', 0) : null);
        }

        return back()->with('status', 'Commission rates saved. Existing orders keep the rate they were made successful at.');
    }
}
