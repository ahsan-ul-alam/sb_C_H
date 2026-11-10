<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLineClassification;
use App\Models\SourcingAllocation;
use App\Support\InvoiceCategories;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * What a client has been invoiced and has really paid, by category. READ-ONLY: it reads invoices, their lines and verified payments (a voided payment is already
 * out of every payments relation) and never posts, rewrites or reclassifies anything.
 *
 * Category of a line, in this order (never guessed from a description):
 *   1. an approved Finance classification of the line (latest approved row; "unclassified" reverses it),
 *   2. the invoice is fully backed by sourcing billing allocations -> Product sourcing,
 *   3. the line's segment name equals a known category label,
 *   4. the invoice has a single line and its purpose equals a known label,
 *   5. otherwise "Uncategorized / Needs classification".
 *
 * Money: an invoice's paid amount is its own stored verified paid amount (direct invoice payments plus payments recorded on its segments), so it is counted once.
 * A payment applies to the whole invoice. When an invoice's lines belong to ONE category its billed, paid and due belong to that category; when they belong to several, only the
 * billed amounts are split by line and the invoice's paid and due stay at invoice level ("category allocation pending"), never split by a guessed rule. Totals therefore
 * reconcile exactly: sum of category rows + mixed invoices = total invoiced / paid / due.
 */
class ClientFinancials
{
    /**
     * @param  Collection<int, Invoice>  $invoices  with items.segment loaded
     * @return array<int, array{key: string, source: string}> by invoice item id
     */
    public static function lineCategories(Collection $invoices): array
    {
        $itemIds = $invoices->flatMap(fn (Invoice $i) => $i->items->pluck('id'))->all();
        $effective = [];
        // Until the classification migration has been applied there are simply no Finance classifications.
        $rows = Schema::hasTable('invoice_line_classifications') ? InvoiceLineClassification::whereIn('invoice_item_id', $itemIds)->where('status', 'approved')->orderBy('id')->get() : collect();
        foreach ($rows as $c) {
            $effective[$c->invoice_item_id] = $c->category; // the latest approved row wins
        }
        $linked = SourcingAllocation::whereIn('billed_invoice_id', $invoices->pluck('id'))->where('kind', 'sale')->get()->groupBy('billed_invoice_id')->map(fn ($g) => (int) $g->sum(fn ($a) => $a->amount()));

        $out = [];
        foreach ($invoices as $invoice) {
            $backedByProduct = (int) $invoice->total > 0 && (int) ($linked[$invoice->id] ?? 0) >= (int) $invoice->total;
            $single = $invoice->items->count() === 1;
            foreach ($invoice->items as $item) {
                $out[$item->id] = match (true) {
                    isset($effective[$item->id]) => $effective[$item->id] === InvoiceCategories::UNCLASSIFIED ? ['key' => InvoiceCategories::UNCLASSIFIED, 'source' => 'none'] : ['key' => $effective[$item->id], 'source' => 'finance'],
                    $backedByProduct => ['key' => InvoiceCategories::PRODUCT, 'source' => 'sourcing'],
                    ($k = InvoiceCategories::fromText($item->segment?->name)) !== null => ['key' => $k, 'source' => 'segment'],
                    $single && ($k = InvoiceCategories::fromText($invoice->purpose)) !== null => ['key' => $k, 'source' => 'purpose'],
                    default => ['key' => InvoiceCategories::UNCLASSIFIED, 'source' => 'none'],
                };
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function sourceLabels(): array
    {
        return ['finance' => 'Classified by Finance', 'sourcing' => 'From sourcing billing', 'segment' => 'From the project segment', 'purpose' => 'From the invoice purpose', 'none' => 'Needs classification'];
    }

    /** @return array<string, mixed> */
    public static function for(Client $client): array
    {
        $invoices = Invoice::whereIn('project_id', $client->projects()->select('id'))->with(['items.segment', 'payments', 'project'])->orderBy('issued_on')->orderBy('id')->get();
        $cats = self::lineCategories($invoices);

        $totals = ['invoiced' => 0, 'paid' => 0, 'due' => 0, 'invoices' => $invoices->count()];
        $byCategory = [];
        $byLine = [];
        $mixed = ['count' => 0, 'billed' => 0, 'paid' => 0, 'due' => 0];
        $rows = [];

        foreach ($invoices as $i) {
            $lines = $i->items->map(fn ($it) => ['description' => $it->description, 'quantity' => $it->displayQuantityWithUnit(), 'amount' => (int) $it->amount] + $cats[$it->id])->all();
            $itemised = array_sum(array_column($lines, 'amount'));
            if ((int) $i->total !== $itemised) { // an invoice total that its lines do not explain stays visible and unclassified, so totals still reconcile
                $lines[] = ['description' => 'Invoice total not itemised', 'quantity' => '', 'amount' => (int) $i->total - $itemised, 'key' => InvoiceCategories::UNCLASSIFIED, 'source' => 'none'];
            }
            $keys = collect($lines)->pluck('key')->unique()->values();
            $category = $keys->count() === 1 ? $keys->first() : null;
            $paid = (int) $i->amount_paid;
            $direct = (int) $i->payments->sum('amount');
            $due = $i->balance();

            foreach ($lines as $l) {
                $byLine[$l['key']] = ($byLine[$l['key']] ?? 0) + $l['amount'];
            }
            if ($category !== null) {
                $byCategory[$category] ??= ['label' => InvoiceCategories::label($category), 'billed' => 0, 'paid' => 0, 'due' => 0, 'invoices' => 0];
                $byCategory[$category]['billed'] += (int) $i->total;
                $byCategory[$category]['paid'] += $paid;
                $byCategory[$category]['due'] += $due;
                $byCategory[$category]['invoices']++;
            } else {
                $mixed['count']++;
                $mixed['billed'] += (int) $i->total;
                $mixed['paid'] += $paid;
                $mixed['due'] += $due;
            }
            $totals['invoiced'] += (int) $i->total;
            $totals['paid'] += $paid;
            $totals['due'] += $due;

            $rows[] = ['id' => $i->id, 'number' => $i->number, 'issued_on' => $i->issued_on, 'due_on' => $i->due_on, 'purpose' => $i->purpose, 'project' => $i->project->name, 'total' => (int) $i->total, 'paid' => $paid, 'due' => $due,
                'status' => $i->isOverdue() ? 'Overdue' : $i->status->label(), 'category' => $category, 'category_label' => $category === null ? 'Mixed categories' : InvoiceCategories::label($category),
                'keys' => $keys->all(), 'lines' => $lines,
                'payments' => $i->payments->sortBy('paid_on')->map(fn ($p) => ['date' => $p->paid_on, 'amount' => (int) $p->amount, 'method' => ucfirst(str_replace('_', ' ', $p->method?->value ?? (string) $p->method))])->values()->all(),
                'segment_paid' => max(0, $paid - $direct)];
        }

        uksort($byCategory, fn ($a, $b) => self::order($a) <=> self::order($b));
        uksort($byLine, fn ($a, $b) => self::order($a) <=> self::order($b));
        $lineRows = collect($byLine)->map(fn ($billed, $k) => ['label' => InvoiceCategories::label($k), 'billed' => $billed])->all();

        $product = $byCategory[InvoiceCategories::PRODUCT] ?? ['billed' => 0, 'paid' => 0, 'due' => 0, 'invoices' => 0];
        $productInMixed = collect($rows)->filter(fn ($r) => $r['category'] === null && in_array(InvoiceCategories::PRODUCT, $r['keys'], true));
        $reconciled = array_sum(array_column($byCategory, 'billed')) + $mixed['billed'] === $totals['invoiced']
            && array_sum(array_column($byCategory, 'paid')) + $mixed['paid'] === $totals['paid']
            && array_sum(array_column($byCategory, 'due')) + $mixed['due'] === $totals['due']
            && array_sum($byLine) === $totals['invoiced'];

        return ['totals' => $totals, 'by_category' => $byCategory, 'mixed' => $mixed, 'by_line' => $lineRows, 'invoices' => $rows, 'reconciled' => $reconciled,
            'product' => ['billed' => (int) ($byLine[InvoiceCategories::PRODUCT] ?? 0), 'paid' => $product['paid'], 'due' => $product['due'], 'in_mixed_billed' => (int) $productInMixed->sum(fn ($r) => collect($r['lines'])->where('key', InvoiceCategories::PRODUCT)->sum('amount')),
                'mixed_paid_pending' => (int) $productInMixed->sum('paid')],
            'unclassified' => ['billed' => (int) ($byLine[InvoiceCategories::UNCLASSIFIED] ?? 0), 'lines' => collect($rows)->sum(fn ($r) => collect($r['lines'])->where('key', InvoiceCategories::UNCLASSIFIED)->count())]];
    }

    private static function order(string $key): int
    {
        $i = array_search($key, array_keys(InvoiceCategories::LABELS), true);

        return $i === false ? 99 : (int) $i;
    }
}
