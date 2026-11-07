<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientExpense;
use App\Models\ClientOrder;
use App\Models\ClientProfitDeclaration;
use App\Services\ClientExpenses;
use App\Services\ClientProfitability;
use App\Services\ClientProfitDeclarations;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ClientProfitController extends Controller
{
    public function expenses(Client $client): View
    {
        return view('clients.expenses', [
            'client' => $client,
            'expenses' => ClientExpense::where('client_id', $client->id)->with(['allocations.project', 'events.user', 'creator'])->orderByDesc('id')->get(),
            'projects' => $client->projects()->orderBy('name')->get(),
            'orders' => ClientOrder::whereIn('project_id', $client->projects()->select('id'))->orderBy('reference')->get(['id', 'project_id', 'reference']),
        ]);
    }

    public function storeExpense(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string'], 'amount' => ['required', 'string'], 'spent_on' => ['required', 'date'],
            'responsible_party' => ['required', 'in:client,sellbees'], 'paid_status' => ['required', 'in:paid,unpaid'], 'reference' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'], 'allocation_basis' => ['nullable', 'in:direct,sales_value,units,manual'], 'allocation_note' => ['nullable', 'string', 'max:255'],
            'expense_id' => ['nullable', 'integer'], 'charge_treatment' => ['required', 'in:additional,included_in_acquisition'], 'source_invoice_id' => ['nullable', 'integer'],
            'allocations' => ['required', 'array', 'min:1'], 'allocations.*.project_id' => ['required', 'integer'], 'allocations.*.client_order_id' => ['nullable', 'integer'],
            'allocations.*.supplier_product_id' => ['nullable', 'integer'], 'allocations.*.amount' => ['required', 'string'],
        ]);
        $data['amount'] = Money::toMinor($data['amount']);
        $data['allocations'] = array_map(fn ($a) => ['project_id' => (int) $a['project_id'], 'client_order_id' => ($a['client_order_id'] ?? null) ?: null,
            'supplier_product_id' => ($a['supplier_product_id'] ?? null) ?: null, 'amount' => Money::toMinor($a['amount'])], array_values($data['allocations']));

        try {
            ClientExpenses::record($client, $data, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Client expense entered. It needs Finance review and independent approval before it counts.');
    }

    public function reviewExpense(Request $request, ClientExpense $expense): RedirectResponse
    {
        return $this->act(fn () => ClientExpenses::review($expense, $request->user()), 'Expense reviewed.');
    }

    public function decideExpense(Request $request, ClientExpense $expense): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->act(fn () => ClientExpenses::decide($expense, $data['decision'] === 'approve', $data['note'] ?? null, $request->user()), 'Decision recorded.');
    }

    public function reverseExpense(Request $request, ClientExpense $expense): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->act(fn () => ClientExpenses::reverse($expense, $data['reason'], $request->user()), 'Expense reversed. Enter the corrected one if needed.');
    }

    public function profitability(Request $request, Client $client): View
    {
        $filters = $request->validate(['project_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'currency' => ['nullable', 'string', 'size:3']]);
        $result = ClientProfitability::calculate($client, $filters['project_id'] ?? null, $filters['from'] ?? null, $filters['to'] ?? null, $filters['currency'] ?? null);

        return view('clients.profitability', [
            'client' => $client, 'currencies' => ClientProfitability::currencies($client), 'result' => $result, 'filters' => $filters, 'projects' => $client->projects()->orderBy('name')->get(), 'categories' => ClientExpense::CATEGORIES,
            'declarations' => ClientProfitDeclaration::where('client_id', $client->id)->with('declarer')->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function declare(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(['project_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'currency' => ['nullable', 'string', 'size:3'], 'zero' => ['nullable', 'array'],
            'zero.*' => ['string'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->act(fn () => ClientProfitDeclarations::declare($client, $data['project_id'] ?? null, $data['from'] ?? null, $data['to'] ?? null, $data['zero'] ?? [], $data['note'] ?? null, $request->user(), $data['currency'] ?? null),
            'Declaration recorded. It stays valid only while the underlying transactions do not change.');
    }

    public function revokeDeclaration(Request $request, ClientProfitDeclaration $declaration): RedirectResponse
    {
        return $this->act(fn () => ClientProfitDeclarations::revoke($declaration, $request->user()), 'Declaration withdrawn.');
    }

    private function act(\Closure $work, string $message): RedirectResponse
    {
        try {
            $work();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }
}
