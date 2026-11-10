<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceLineClassification;
use App\Services\ClientFinancials;
use App\Services\InvoiceClassificationService;
use App\Support\InvoiceCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Finance classifies invoice lines for the client reports; a different user verifies. Reporting metadata only: no invoice, payment or journal is touched.
 */
class InvoiceClassificationController extends Controller
{
    public function index(): View
    {
        $invoices = Invoice::with(['items.segment', 'project.client'])->orderByDesc('id')->get();
        $cats = ClientFinancials::lineCategories($invoices);
        $needs = [];
        foreach ($invoices as $invoice) {
            foreach ($invoice->items as $item) {
                if ($cats[$item->id]['key'] === InvoiceCategories::UNCLASSIFIED) {
                    $needs[] = ['invoice' => $invoice, 'item' => $item];
                }
            }
        }

        return view('finance.invoice-classifications', [
            'needs' => collect($needs)->take(300), 'pending' => InvoiceLineClassification::with(['item.invoice.project.client', 'proposer'])->where('status', 'proposed')->orderBy('id')->get(),
            'recent' => InvoiceLineClassification::with(['item.invoice', 'proposer'])->where('status', '!=', 'proposed')->orderByDesc('id')->limit(30)->get(), 'categories' => InvoiceCategories::LABELS,
        ]);
    }

    public function propose(Request $request): RedirectResponse
    {
        $data = $request->validate(['invoice_item_id' => ['required', 'integer', Rule::exists('invoice_items', 'id')], 'category' => ['required', 'string'], 'reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => InvoiceClassificationService::propose(InvoiceItem::findOrFail($data['invoice_item_id']), $data['category'], $data['reason'], $request->user()), 'Classification proposed. A different authorised user must verify it.');
    }

    public function decide(Request $request, InvoiceLineClassification $proposal): RedirectResponse
    {
        $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:255']]);
        $approve = $request->input('decision') === 'approve';

        return $this->attempt(fn () => InvoiceClassificationService::decide($proposal, $approve, $request->user(), $request->input('note')), $approve ? 'Classification verified.' : 'Classification rejected.');
    }
}
