<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Audit;
use App\Services\LedgerStatements;
use App\Services\StorageBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The ledger-derived statements in one place: trial balance, operational profit with its bridge, cash flow, supplier and customer aging, and the list of everything that
 * does not reconcile or is waiting. Every figure comes from LedgerStatements, which reads the immutable ledger.
 */
class LedgerReportController extends Controller
{
    public function storageContract(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(['storage_contract_reference' => ['required', 'string', 'min:5', 'max:255'], 'storage_contract_from' => ['required', 'date']]);
        $client->forceFill($data)->save();
        Audit::log('storage.contract', 'client '.$client->id, $data['storage_contract_reference'].' from '.$data['storage_contract_from']);

        return back()->with('success', 'Storage contract recorded.');
    }

    public function index(Request $request): View
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = $data['to'] ?? today()->toDateString();
        $from = $data['from'] ?? substr($to, 0, 8).'01';

        return view('finance.ledger-reports', [
            'from' => $from, 'to' => $to, 'trial' => LedgerStatements::trialBalance($to), 'profit' => LedgerStatements::operationalProfit($from, $to), 'cash' => LedgerStatements::cashFlow($from, $to),
            'supplierAging' => LedgerStatements::supplierAging($to), 'receivableAging' => LedgerStatements::receivableAging($to), 'exceptions' => LedgerStatements::exceptions(),
            'labels' => LedgerStatements::INVENTORY_RESULT_ACCOUNTS, 'storage' => StorageBilling::configurationExceptions(),
        ]);
    }
}
