<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\SourcingAllocation;
use App\Models\SourcingArrivalEvent;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY reconciliation of historical (legacy) sourcing lots against the simplified client-lot workflow (Processing → In order → Completed).
 *
 * It answers one question per lot: can this lot sit next to the simple workflow without its stock or money being counted again? It never
 * writes, never guesses a missing quantity and never treats a record as wrong merely because it was made on the older workflow. Every finding
 * is one of:
 *
 *  - confirmed: two records that must agree do not (e.g. the stock ledger holds more units than were ever received). The numbers are certain;
 *    the cause and the correction still need a person.
 *  - review:    the records are consistent but ambiguous for the simple workflow (e.g. goods received while the status still says Approved, a
 *    lot reopened after settlement, manual invoices that may already cover the lot). Needs manual investigation.
 *  - info:      context worth seeing, no action required.
 *
 * Legacy lots are the ones without a client budget (client_budget NULL, or the column not migrated yet). The new code keys every new
 * behaviour on that budget, so for a legacy lot the "after" figures must equal the "before" figures; this class recomputes both and reports
 * any difference as a confirmed regression.
 */
class ClientLotReconciliation
{
    /** The columns added by 2026_12_17_100000_add_client_lot_workflow_fields. */
    public const WORKFLOW_COLUMNS = ['client_budget', 'ordered_at', 'ordered_by', 'completed_at', 'completed_by'];

    public const CATEGORIES = [
        'status' => 'Status / stage mapping', 'quantity' => 'Quantity mismatch', 'missing_receipt' => 'Missing receiving record',
        'duplicate_receipt' => 'Duplicate receiving risk', 'supplier_payment' => 'Supplier payment difference', 'delivery' => 'Delivery charge',
        'budget' => 'Client budget', 'invoice' => 'Invoice duplication risk', 'profitability' => 'Project profitability', 'orphan' => 'Orphaned / inconsistent reference',
        'data_quality' => 'Data quality', 'migration' => 'Migration compatibility',
    ];

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    /**
     * @return array{schema: array<string, mixed>, lots: list<array<string, mixed>>, lines: list<array<string, mixed>>, projects: list<array<string, mixed>>, findings: list<array<string, mixed>>, summary: array<string, int>}
     */
    public static function run(): array
    {
        return (new self)->build();
    }

    /**
     * A fingerprint of every table the workflow or this report could touch. Taken before and after a run to prove nothing was written.
     *
     * @return array<string, string>
     */
    public static function fingerprint(): array
    {
        $tables = [
            'sourcing_lots', 'sourcing_lot_lines', 'sourcing_lot_costs', 'sourcing_allocations', 'sourcing_arrival_events', 'stock_movements',
            'supplier_payments', 'expenses', 'invoices', 'invoice_items', 'payments', 'payment_allocations', 'journal_entries', 'journal_lines',
            'inventory_value_movements', 'audit_logs', 'client_orders', 'client_order_items',
        ];
        $print = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $row = DB::table($table)->selectRaw('COUNT(*) c, COALESCE(MAX(id),0) m')->first();
            $updated = Schema::hasColumn($table, 'updated_at') ? (string) DB::table($table)->max('updated_at') : '';
            $print[$table] = $row->c.'|'.$row->m.'|'.$updated;
        }
        $sums = [
            'lot_figures' => ['sourcing_lots', ['approved_cost', 'approved_revenue', 'pricing_value']],
            'line_figures' => ['sourcing_lot_lines', ['quantity', 'received_quantity', 'usable_quantity', 'unit_price', 'approved_price']],
            'stock_figures' => ['stock_movements', ['d_available', 'd_reserved', 'd_dispatched', 'd_delivered', 'd_returned', 'd_damaged']],
            'journal_figures' => ['journal_lines', ['debit', 'credit']],
            'payment_figures' => ['supplier_payments', ['amount']],
            'expense_figures' => ['expenses', ['amount', 'paid_amount']],
        ];
        foreach ($sums as $key => [$table, $columns]) {
            $row = (array) DB::table($table)->selectRaw(implode(', ', array_map(fn ($c) => "COALESCE(SUM($c),0) as $c", $columns)))->first();
            $print[$key] = implode('|', array_map(fn ($v) => bcadd((string) $v, '0', 2), $row));
        }

        return $print;
    }

    /**
     * @return array{schema: array<string, mixed>, lots: list<array<string, mixed>>, lines: list<array<string, mixed>>, projects: list<array<string, mixed>>, findings: list<array<string, mixed>>, summary: array<string, int>}
     */
    private function build(): array
    {
        $schema = $this->schema();
        $lotRows = [];
        $lineRows = [];
        $lots = SourcingLot::with(['lines.supplier', 'lines.product', 'project.client'])->orderBy('id')->get();
        foreach ($lots as $lot) {
            [$lotRow, $lines] = $this->lot($lot);
            $lotRows[] = $lotRow;
            array_push($lineRows, ...$lines);
        }
        $this->unlinkedSourcingExpenses();
        $this->orphans();
        $projects = $this->projects($schema['columns_present']);

        // A lot is reconciled when nothing about it needs a person; info alone does not count.
        $open = collect($this->findings)->whereIn('class', ['confirmed', 'review'])->groupBy('lot_id');
        foreach ($lotRows as &$row) {
            $row['verdict'] = $open->has($row['lot_id']) ? 'needs_review' : 'reconciled';
            $row['open_findings'] = $open->has($row['lot_id']) ? $open[$row['lot_id']]->count() : 0;
        }
        unset($row);

        return ['schema' => $schema, 'lots' => $lotRows, 'lines' => $lineRows, 'projects' => $projects, 'findings' => $this->findings, 'summary' => $this->summary($lotRows)];
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $present = collect(self::WORKFLOW_COLUMNS)->every(fn ($c) => Schema::hasColumn('sourcing_lots', $c));
        $ran = DB::table('migrations')->pluck('migration')->all();
        $pending = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getFilenameWithoutExtension())->reject(fn ($m) => in_array($m, $ran, true))->sort()->values()->all();
        $nonNull = [];
        if ($present) {
            foreach (self::WORKFLOW_COLUMNS as $column) {
                $nonNull[$column] = DB::table('sourcing_lots')->whereNotNull($column)->count();
            }
            // A budget can only be set by the simple form (ClientLot::create, which writes a 'sourcing.client_lot.created' audit row). A budget on
            // any other lot means a historical lot was pushed into the new workflow by hand.
            $made = DB::table('audit_logs')->where('action', 'sourcing.client_lot.created')->pluck('subject')->map(fn ($s) => substr((string) $s, 4))->all();
            foreach (DB::table('sourcing_lots')->whereNotNull('client_budget')->whereNotIn('number', $made)->get(['id', 'number', 'client_budget']) as $lot) {
                $this->add('confirmed', 'migration', 'BUDGET_ON_LEGACY_LOT', $lot->id, $lot->number, '', '', '', Money::format((int) $lot->client_budget), 'NULL', null,
                    'A client budget is set on a lot that was not created on the simple client-lot form.', 'Find who set it (audit log, database access). The budget changes how this lot\'s revenue is computed; restore NULL only through a reviewed correction.');
            }
        }

        return ['columns_present' => $present, 'non_null' => $nonNull, 'pending_migrations' => $pending, 'driver' => DB::connection()->getDriverName()];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function lot(SourcingLot $lot): array
    {
        $legacy = $lot->getAttribute('client_budget') === null;
        $f = LotFinancials::for($lot);
        $project = $lot->project ? $lot->project->name.' (#'.$lot->project_id.')' : '';
        $suppliers = $lot->lines->pluck('supplier.name')->filter()->unique()->implode(', ');
        $ctx = fn (?SourcingLotLine $line = null) => [$lot->id, $lot->number, $project, $line?->supplier?->name ?? $suppliers, $line?->description ?? $lot->lines->pluck('description')->implode(', ')];

        $lines = [];
        $received = ['none' => 0, 'partial' => 0, 'full' => 0];
        $lineIssues = false;
        foreach ($lot->lines as $line) {
            [$row, $state, $issue] = $this->line($lot, $line, $ctx($line));
            $lines[] = $row;
            $received[$state]++;
            $lineIssues = $lineIssues || $issue;
        }

        $this->money($lot, $f, $ctx(), $legacy);
        $evidence = $this->status($lot, $f, $received, $ctx());
        $this->billing($lot, $f, $ctx(), $legacy);

        if ($lot->lines->contains(fn ($l) => preg_match('/^\d+$/', (string) $l->unit))) {
            $units = $lot->lines->filter(fn ($l) => preg_match('/^\d+$/', (string) $l->unit))->map(fn ($l) => $l->description.' unit "'.$l->unit.'"')->implode('; ');
            $this->add('info', 'data_quality', 'NUMERIC_UNIT', ...[...$ctx(), $units, 'a unit name such as pcs', null,
                'The unit of measure holds a number, probably a quantity or price typed into the wrong field. It is a label only and changes no figure.', 'Correct the label when convenient; no financial effect.']);
        }

        return [[
            'lot_id' => $lot->id, 'lot' => $lot->number, 'title' => $lot->title, 'project' => $project, 'supplier' => $suppliers,
            'workflow' => $legacy ? 'legacy' : 'client_lot', 'status' => $lot->status, 'shown_as' => $lot->stageLabel(), 'evidence_stage' => $evidence,
            'ownership' => $lot->ownership, 'purchase' => $f['purchase'], 'additional' => $f['additional'], 'landed' => $f['landed'],
            'revenue' => $f['revenue'], 'profit' => $f['profit'], 'approved_cost' => $lot->approved_cost, 'approved_revenue' => $lot->approved_revenue,
            'supplier_paid' => $f['supplier_paid'], 'supplier_outstanding' => $f['supplier_outstanding'], 'costs_unpaid' => $f['costs_unpaid'],
            'billed' => $f['billed'], 'client_budget' => $lot->getAttribute('client_budget'), 'line_issues' => $lineIssues,
        ], $lines];
    }

    /**
     * Quantities of one line, from every source that records them: ordered, the received/accepted totals on the line, the arrival history and
     * the stock ledger. "Safe to receive" is the quantity a future receipt may add without counting stock twice — only when nothing is ambiguous.
     *
     * @param  array{0: int, 1: string, 2: string, 3: string, 4: string}  $ctx
     * @return array{0: array<string, mixed>, 1: string, 2: bool}
     */
    private function line(SourcingLot $lot, SourcingLotLine $line, array $ctx): array
    {
        $before = count($this->findings);
        $ordered = bcadd((string) $line->quantity, '0', 2);
        $receivedTotal = $line->received_quantity === null ? null : bcadd((string) $line->received_quantity, '0', 2);
        $accepted = $receivedTotal === null ? '0.00' : bcadd((string) $line->usable_quantity, '0', 2);
        $b = Inventory::balances($line);
        $receipts = $this->receipts($line->id);
        $arrivals = SourcingArrivalEvent::where('sourcing_lot_line_id', $line->id)->get();
        $arrivalAccepted = (string) $arrivals->reduce(fn (string $c, $e) => bcadd($c, (string) $e->accepted_quantity, 2), '0.00');
        $onHand = Inventory::onHand($line);
        $stockTotal = bcadd($b['received'], $b['adjusted'], 2);

        if ($receivedTotal !== null && bccomp($receivedTotal, $ordered, 2) > 0) {
            $this->add('confirmed', 'quantity', 'OVER_RECEIVED', ...[...$ctx, $receivedTotal.' received', $ordered.' ordered', bcsub($receivedTotal, $ordered, 2),
                'More units are recorded as received than were ordered.', 'Check the delivery notes; correct the line through a reviewed adjustment.']);
        }
        if ($receivedTotal === null && bccomp($b['received'], '0', 2) !== 0) {
            $this->add('confirmed', 'missing_receipt', 'LEDGER_WITHOUT_ARRIVAL', ...[...$ctx, $b['received'].' in stock ledger receipts', '0 (line not received)', $b['received'],
                'The stock ledger has receipts but the line records no arrival, so the simple workflow would expect the full quantity again.', 'Record the arrival total on the line from the delivery note (reviewed); never receive again through the workflow.']);
        }
        if ($receivedTotal !== null && bccomp($b['received'], $accepted, 2) !== 0) {
            $this->add('confirmed', 'missing_receipt', 'RECEIPT_LEDGER_MISMATCH', ...[...$ctx, $b['received'].' in stock ledger receipts', $accepted.' accepted on the line', bcsub($b['received'], $accepted, 2),
                'The receipts in the stock ledger do not add up to the accepted quantity on the line.', 'Compare with the delivery note; post the difference as a reviewed adjustment with a reason.']);
        }
        if (bccomp($b['adjusted'], '0', 2) > 0) {
            $this->add('confirmed', 'duplicate_receipt', 'STOCK_EXCEEDS_RECEIVED', ...[...$ctx, $stockTotal.' units in the stock ledger (receipts '.$b['received'].' + adjustments '.$b['adjusted'].')', $accepted.' accepted on arrival', $b['adjusted'],
                'A positive stock adjustment put more units into stock than were ever received on this line'.($this->adjustReasons($line->id) ? ' (reason given: '.$this->adjustReasons($line->id).')' : '').'. If the units do not physically exist, stock is overstated and could be sold twice.',
                'Count the physical stock of this product. Then post ONE reviewed adjustment to the counted quantity with the reason; do not delete the original movement.']);
        } elseif (bccomp($b['adjusted'], '0', 2) < 0) {
            $this->add('review', 'quantity', 'NEGATIVE_ADJUSTMENT', ...[...$ctx, $b['adjusted'].' adjusted', '0', $b['adjusted'],
                'Units were adjusted out of stock (loss, count correction or reversal).', 'Confirm the adjustment reason matches the physical count.']);
        }
        if (! Inventory::reconciles($line)) {
            $this->add('confirmed', 'quantity', 'LEDGER_UNBALANCED', ...[...$ctx, 'buckets do not add up', 'received + adjusted = sum of buckets, available ≥ 0', null,
                'The stock buckets of this line do not add up, or available stock is negative.', 'Investigate the movements of this line before any further stock operation.']);
        }
        if ($arrivals->isNotEmpty() && bccomp($arrivalAccepted, $accepted, 2) !== 0) {
            $this->add('confirmed', 'missing_receipt', 'ARRIVAL_HISTORY_MISMATCH', ...[...$ctx, $arrivalAccepted.' accepted in arrival history', $accepted.' accepted on the line', bcsub($arrivalAccepted, $accepted, 2),
                'The arrival history and the line totals disagree.', 'Check each arrival against its delivery note.']);
        }
        if ($receipts['negative'] > 0) {
            $this->add('info', 'quantity', 'RECEIPT_RERECORDED', ...[...$ctx, 'receipts '.$receipts['list'], 'net '.$accepted, null,
                'The arrival was recorded more than once and corrected with a negative receipt; the net equals the accepted quantity.', 'None; history is consistent.']);
        }

        $state = match (true) {
            $receivedTotal === null || bccomp($receivedTotal, '0', 2) === 0 => 'none',
            bccomp($receivedTotal, $ordered, 2) >= 0 => 'full',
            default => 'partial',
        };
        if ($state === 'partial') {
            $this->add('review', 'quantity', 'PARTIALLY_RECEIVED', ...[...$ctx, $receivedTotal.' received', $ordered.' ordered', bcsub($receivedTotal, $ordered, 2),
                'Only part of the ordered quantity is recorded as received. The rest must be confirmed (still coming, short-shipped or cancelled) before anything else is received.', 'Confirm the remaining quantity with the supplier; receive only that confirmed quantity, under a new delivery reference.']);
        }
        $issue = collect(array_slice($this->findings, $before))->whereIn('class', ['confirmed', 'review'])->isNotEmpty();
        if ($state !== 'none' && $arrivals->isEmpty() && ! $issue) {
            $this->add('info', 'missing_receipt', 'LEGACY_RECEIVING', ...[...$ctx, $receivedTotal.' received (line total, no arrival rows)', $receivedTotal, '0.00',
                'Received under the older workflow, which kept the total on the line without an arrival history. The simple workflow reads the same line total, so it expects only '.Receiving::expected($line).' more.', 'None.']);
        }
        $expected = Receiving::expected($line);

        return [[
            'lot_id' => $lot->id, 'lot' => $lot->number, 'line_id' => $line->id, 'product' => $line->description, 'supplier' => $line->supplier?->name,
            'unit' => $line->unit, 'ordered' => $ordered, 'received' => $receivedTotal, 'accepted' => $accepted, 'ledger_receipts' => $b['received'],
            'adjusted' => $b['adjusted'], 'stock_total' => $stockTotal, 'on_hand' => $onHand, 'available' => $b['available'], 'arrival_rows' => $arrivals->count(),
            'still_expected' => $expected, 'safe_to_receive' => $issue ? 'needs review' : $expected,
        ], $state, $issue];
    }

    /**
     * Supplier payments, lot costs (delivery) and the approved snapshot against the lot's current figures.
     *
     * @param  array<string, mixed>  $f
     * @param  array{0: int, 1: string, 2: string, 3: string, 4: string}  $ctx
     */
    private function money(SourcingLot $lot, array $f, array $ctx, bool $legacy): void
    {
        $stage = $lot->stage();
        $diff = $f['supplier_paid'] - $f['purchase'];
        if ($diff > 0) {
            $this->add('confirmed', 'supplier_payment', 'SUPPLIER_OVERPAID', ...[...$ctx, Money::format($f['supplier_paid']).' paid', Money::format($f['purchase']).' purchase cost', Money::format($diff),
                'Live supplier payments exceed the purchase cost of the lot.', 'Check for a duplicate payment; correct only by a reviewed void/refund, never by deleting.']);
        } elseif ($diff < 0 && ($stage === 'completed' || $lot->lines->contains(fn ($l) => $l->received_quantity !== null))) {
            $this->add('review', 'supplier_payment', 'SUPPLIER_UNDERPAID', ...[...$ctx, Money::format($f['supplier_paid']).' paid', Money::format($f['purchase']).' purchase cost', Money::format($diff),
                'Goods are received but the supplier is not fully paid on record.', 'Confirm with the supplier whether money is owed or the payment was recorded elsewhere.']);
        }

        foreach ($lot->payments as $payment) {
            $lineSuppliers = $lot->lines->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();
            if (! in_array((int) $payment->supplier_id, $lineSuppliers, true)) {
                $this->add('confirmed', 'orphan', 'PAYMENT_SUPPLIER_NOT_ON_LOT', ...[...$ctx, 'payment #'.$payment->id.' to supplier #'.$payment->supplier_id, 'a supplier of the lot', null,
                    'A supplier payment on this lot is to a supplier that supplies none of its lines.', 'Move the payment to the right lot or supplier through a reviewed correction.']);
            }
            if ($payment->voided_at === null && $payment->expense && (int) $payment->expense->amount !== (int) $payment->amount) {
                $this->add('confirmed', 'supplier_payment', 'PAYMENT_EXPENSE_AMOUNT', ...[...$ctx, Money::format((int) $payment->expense->amount).' expense #'.$payment->expense_id, Money::format((int) $payment->amount).' payment #'.$payment->id,
                    Money::format((int) $payment->expense->amount - (int) $payment->amount), 'The expense posted for a supplier payment differs from the payment.', 'Reconcile with the bank/cash record; correct through a reviewed expense correction.']);
            }
            if ($payment->voided_at === null && $payment->expense && $payment->expense->status === 'pending') {
                $this->add('review', 'supplier_payment', 'PAYMENT_UNVERIFIED', ...[...$ctx, 'expense #'.$payment->expense_id.' pending', 'verified', null,
                    'A supplier payment has not been verified by the Board yet, so it is not in the books.', 'Verify or reject it through the normal approval.']);
            }
            if ($payment->voided_at === null && $payment->expense_id === null) {
                $this->add('review', 'supplier_payment', 'PAYMENT_WITHOUT_EXPENSE', ...[...$ctx, 'payment #'.$payment->id.' has no expense', 'one expense', null,
                    'A supplier payment has no expense, so the money it says was paid never reached the books.', 'Find the cash record; post it once through the normal payment correction.']);
            }
        }
        $live = $lot->payments->filter(fn ($p) => $p->effectiveAmount() > 0);
        $day = fn ($p) => substr((string) $p->getRawOriginal('paid_on'), 0, 10);
        foreach ($live->groupBy(fn ($p) => $p->supplier_id.'|'.$p->amount.'|'.$day($p)) as $group) {
            if ($group->count() > 1) {
                $this->add('review', 'supplier_payment', 'POSSIBLE_DUPLICATE_PAYMENT', ...[...$ctx, $group->count().' payments of '.Money::format((int) $group->first()->amount).' on '.$day($group->first()), '1', null,
                    'Several live supplier payments with the same supplier, amount and date.', 'Check the bank/cash record; void a duplicate only through the reviewed void.']);
            }
        }

        $replaced = $lot->costs->pluck('replaces_id')->filter()->all();
        foreach ($lot->costs->reject(fn ($c) => in_array($c->id, $replaced, true))->where('kind', 'actual') as $cost) {
            $isDelivery = (bool) preg_match('/deliver|transport|courier|carry/i', $cost->category.' '.$cost->description);
            $category = $isDelivery ? 'delivery' : 'supplier_payment';
            if ($cost->expense_id === null) {
                $this->add($stage === 'completed' ? 'review' : 'info', $category, 'COST_UNPAID', ...[...$ctx, Money::format((int) $cost->amount).' '.$cost->category.' (no payment recorded)', 'paid once', Money::format((int) $cost->amount),
                    'A lot cost is in the lot\'s landed cost but has no payment recorded.', 'If it was paid, record the payment once from the lot; if it was never incurred, reverse the cost through a reviewed correction.']);

                continue;
            }
            if ($cost->expense && (int) $cost->expense->amount !== (int) $cost->amount) {
                $this->add('confirmed', $category, 'COST_EXPENSE_AMOUNT', ...[...$ctx, Money::format((int) $cost->expense->amount).' expense #'.$cost->expense_id, Money::format((int) $cost->amount).' '.$cost->category.' cost',
                    Money::format((int) $cost->expense->amount - (int) $cost->amount), 'The expense paying a lot cost differs from the cost.', 'Correct through a reviewed expense correction.']);
            }
            if ($cost->expense?->status === 'pending') {
                $this->add('review', $category, 'COST_UNVERIFIED', ...[...$ctx, 'expense #'.$cost->expense_id.' pending', 'verified', null,
                    'The payment of a lot cost is not verified yet.', 'Verify or reject it through the normal approval.']);
            }
        }

        if ($lot->approved_cost !== null && $f['cost_variance'] !== 0) {
            $this->add('review', 'supplier_payment', 'COST_CHANGED_SINCE_APPROVAL', ...[...$ctx, Money::format($f['landed']).' landed now', Money::format((int) $lot->approved_cost).' at pricing approval', Money::format($f['cost_variance']),
                'The lot\'s cost changed after its price was approved.', 'Confirm the extra or removed cost is real; the approval snapshot itself is never rewritten.']);
        }
        if ($lot->approved_revenue !== null && $f['revenue'] !== (int) $lot->approved_revenue) {
            $this->add('review', 'budget', 'REVENUE_CHANGED_SINCE_APPROVAL', ...[...$ctx, Money::format($f['revenue']).' sales value now', Money::format((int) $lot->approved_revenue).' at pricing approval', Money::format($f['revenue'] - (int) $lot->approved_revenue),
                'The lot\'s sales value differs from the approved snapshot (usually a shortage accepted on arrival).', 'Confirm the agreed price with the client.']);
        }

        if ($legacy) {
            $deliveryCosts = (int) $lot->costs->reject(fn ($c) => in_array($c->id, $replaced, true))->where('kind', 'actual')->filter(fn ($c) => preg_match('/deliver|transport|courier|carry/i', $c->category.' '.$c->description))->sum('amount');
            $basis = $lot->approved_revenue ?? $f['revenue'];
            $simple = (int) $basis - $f['purchase'] - $deliveryCosts;
            $this->add('info', 'budget', 'NO_CLIENT_BUDGET', ...[...$ctx, 'client budget NULL (priced per line); sales value '.Money::format($f['revenue']), 'stays NULL', null,
                'Legacy lot: its revenue is its approved per-line prices, exactly as before. If it were re-expressed as a budget lot (approved sales '.Money::format((int) $basis).' − base '.Money::format($f['purchase']).' − delivery '.Money::format($deliveryCosts).') the profit would read '.Money::format($simple).' vs '.Money::format($f['profit']).' today'.($simple !== $f['profit'] ? ' (difference: other lot costs or a revenue change)' : '').'.',
                'Do not set a budget on this lot. It keeps its legacy figures; nothing is reprocessed.']);
        } elseif ((int) $lot->client_budget <= 0) {
            $this->add('confirmed', 'budget', 'BUDGET_NOT_POSITIVE', ...[...$ctx, Money::format((int) $lot->client_budget), 'above zero', null,
                'A client lot has a zero budget, which the form never allows.', 'Find who changed it; correct through a reviewed correction.']);
        }
        if ($f['profit'] < 0) {
            $this->add('info', 'budget', 'NEGATIVE_PROFIT', ...[...$ctx, Money::format($f['profit']), '≥ 0', Money::format($f['profit']),
                'The lot is expected to make a loss. Shown as a loss, never hidden or posted.', 'None, unless the price was entered wrongly.']);
        }
    }

    /**
     * The stage a person would assign from the evidence, compared with the stage the new screens show (derived from the status).
     *
     * @param  array<string, mixed>  $f
     * @param  array{none: int, partial: int, full: int}  $received
     * @param  array{0: int, 1: string, 2: string, 3: string, 4: string}  $ctx
     */
    private function status(SourcingLot $lot, array $f, array $received, array $ctx): string
    {
        $shown = $lot->stage();
        $lines = array_sum($received);
        $all = $lines > 0 && $received['full'] === $lines;
        $none = $received['partial'] === 0 && $received['full'] === 0;
        [$evidence, $reason] = match (true) {
            $lot->status === 'cancelled' => ['cancelled', null],
            $lines === 0 => ['needs_review', 'The lot has no product lines.'],
            $received['partial'] > 0 || (! $all && ! $none) => ['needs_review', 'Part of the lot is received. A partially received lot is never shown as Completed, and the remaining quantity must be confirmed first.'],
            $all && in_array($shown, ['completed'], true) => ['completed', null],
            $all => ['needs_review', 'All goods are recorded as received, but the status is still "'.$lot->statusLabel().'", so the new screens show "'.$lot->stageLabel().'". The status was never advanced after arrival.'],
            $none && $shown === 'completed' => ['needs_review', 'Marked '.$lot->statusLabel().' but no goods are recorded as received.'],
            $none && $shown === 'in_order' => ['in_order', null],
            $none && ($f['supplier_paid'] > 0 || $lot->procurement_status !== 'ordered') => ['needs_review', 'Nothing received yet, but the supplier has been paid '.Money::format($f['supplier_paid']).' — the order looks placed (it would be "In order"), while the status still reads "'.$lot->statusLabel().'".'],
            default => ['processing', null],
        };
        if ($reason !== null) {
            $this->add('review', 'status', 'STAGE_EVIDENCE_MISMATCH', ...[...$ctx, 'status '.$lot->status.' → shown as "'.$lot->stageLabel().'"', $evidence === 'needs_review' ? 'manual decision' : $evidence, null,
                $reason, 'Keep the status and its timestamps unchanged. A person decides the stage; advance it through the detailed lot view only after checking the evidence.']);
        }
        $reopened = DB::table('audit_logs')->where('action', 'sourcing.reopened')->where('subject', 'lot '.$lot->number)->orderBy('id')->get(['details', 'created_at']);
        if ($reopened->isNotEmpty() && $lot->status !== 'settled') {
            $this->add('review', 'status', 'REOPENED_AFTER_SETTLEMENT', ...[...$ctx, 'reopened '.$reopened->count().'× ('.$reopened->pluck('details')->implode('; ').'), status now '.$lot->status, 'settled again, or the open issue documented', null,
                'The lot was financially settled and then reopened; it has not been settled again.', 'Find what needed fixing, fix it through the detailed view, then settle again. Its profit is reported but not final.']);
        }

        return $evidence;
    }

    /**
     * Client billing: legacy lots were billed by manual invoices that are not linked to the lot, so the lot shows its revenue as "unbilled".
     * Billing it from the lot would invoice the client a second time.
     *
     * @param  array<string, mixed>  $f
     * @param  array{0: int, 1: string, 2: string, 3: string, 4: string}  $ctx
     */
    private function billing(SourcingLot $lot, array $f, array $ctx, bool $legacy): void
    {
        if (! $lot->project_id) {
            $this->add('review', 'orphan', 'NO_PROJECT', ...[...$ctx, 'no client project', 'the client project the goods were for', null,
                'The lot is linked to no project, so its revenue and profit are attributed to no client'.($lot->ownership === 'company' ? ' (ownership: company stock)' : '').'.', 'Confirm which client/project the goods were sold to (see the project invoices) and link it through a reviewed change.']);
        } elseif (! $lot->project) {
            $this->add('confirmed', 'orphan', 'MISSING_PROJECT', ...[...$ctx, 'project #'.$lot->project_id, 'an existing project', null, 'The lot points to a project that does not exist.', 'Relink to the correct project.']);
        } elseif ($lot->project->archived_at !== null) {
            $this->add('review', 'orphan', 'ARCHIVED_PROJECT', ...[...$ctx, $lot->project->name.' archived', 'an active project', null, 'The lot belongs to an archived project.', 'Confirm the project is right.']);
        }
        if ($lot->ownership === null && $lot->status !== 'cancelled') {
            $this->add('review', 'profitability', 'OWNERSHIP_UNCONFIRMED', ...[...$ctx, 'ownership not confirmed', 'client or company', null,
                'Project economics only count client-owned lots, so this lot\'s margin is "pending verification" on its project.', 'Confirm ownership through the ownership request on the lot.']);
        }

        if ($f['billed'] === 0 && $f['revenue'] > 0 && $lot->project_id && $lot->status !== 'cancelled') {
            $linked = SourcingAllocation::whereNotNull('billed_invoice_id')->pluck('billed_invoice_id')->unique()->all();
            $candidates = Invoice::where('project_id', $lot->project_id)->whereNull('voided_at')->whereNotIn('id', $linked)
                ->whereHas('items', fn ($q) => $q->whereNull('segment_id'))->with('items')->orderBy('id')->get();
            if ($candidates->isNotEmpty()) {
                $list = $candidates->map(fn ($i) => $i->number.' '.Money::format((int) $i->total).' ['.$i->items->whereNull('segment_id')->pluck('description')->implode(' / ').']')->implode('; ');
                $this->add('review', 'invoice', 'MANUAL_INVOICES_NOT_LINKED', ...[...$ctx, 'lot shows '.Money::format($f['revenue']).' unbilled', 'billed by existing manual invoice(s) — candidates: '.$list, null,
                    'The client was invoiced manually (not from the lot), so the lot still reads as unbilled. Using "Bill client" / "Bill whole lot" on it would invoice the client again and double-count project revenue.',
                    'Do not bill this lot from the lot screen. Confirm which manual invoice lines cover it; any link must be a reviewed correction that creates no new invoice.']);
            } elseif ($legacy) {
                $this->add('review', 'invoice', 'NOT_INVOICED', ...[...$ctx, Money::format($f['revenue']).' unbilled', 'an invoice, or confirmed unbilled', null,
                    'The lot has revenue but no invoice is linked to it and the project has no candidate manual invoice.', 'Confirm with the client whether it was billed.']);
            }
        }
    }

    /** Sourcing expenses that name a lot but are linked to no supplier payment or lot cost: they could be a second posting of the same money. */
    private function unlinkedSourcingExpenses(): void
    {
        $linked = DB::table('supplier_payments')->whereNotNull('expense_id')->pluck('expense_id')
            ->merge(DB::table('supplier_payments')->whereNotNull('reversal_expense_id')->pluck('reversal_expense_id'))
            ->merge(DB::table('sourcing_lot_costs')->whereNotNull('expense_id')->pluck('expense_id'))->map(fn ($id) => (int) $id)->all();
        $lots = SourcingLot::pluck('id', 'number');
        foreach (DB::table('expenses')->where('category', 'like', 'Sourcing:%')->whereNotIn('id', $linked)->where('status', '!=', 'rejected')->get() as $expense) {
            preg_match('/SL-\d{6}/', (string) $expense->description, $m);
            $lotId = isset($m[0]) ? $lots[$m[0]] ?? null : null;
            $this->add('review', str_contains(strtolower($expense->category), 'deliver') ? 'delivery' : 'supplier_payment', 'UNLINKED_SOURCING_EXPENSE', $lotId, $m[0] ?? '', '', '', '',
                'expense #'.$expense->id.' '.Money::format((int) $expense->amount).' ('.$expense->category.')', 'linked to a supplier payment or lot cost', null,
                'A sourcing expense is linked to no supplier payment or lot cost; if the lot also records that payment, the money is counted twice.', 'Match it to the lot\'s payment; if it duplicates one, reverse it through a reviewed expense correction.');
        }

        // Delivery and product-purchase money recorded as an ordinary expense instead of on a lot. Not a duplicate by itself, but the lot's
        // landed cost does not include it, and recording it again on a lot would post it twice.
        $lotProjects = SourcingLot::whereNotNull('project_id')->pluck('project_id')->unique()->map(fn ($id) => (int) $id)->all();
        $loose = DB::table('expenses')->where('category', 'not like', 'Sourcing:%')->whereNotIn('id', $linked)->where('status', '!=', 'rejected')->whereNull('reverses_id')->get();
        foreach ($loose as $expense) {
            $text = $expense->category.' '.$expense->description;
            $delivery = (bool) preg_match('/deliver|courier|transport/i', $text) && $expense->project_id !== null && in_array((int) $expense->project_id, $lotProjects, true);
            $purchase = (bool) preg_match('/product|purchase/i', $text);
            if (! $delivery && ! $purchase) {
                continue;
            }
            $project = $expense->project_id ? (string) Project::whereKey($expense->project_id)->value('name') : '';
            $this->add('review', $delivery ? 'delivery' : 'supplier_payment', $delivery ? 'DELIVERY_EXPENSE_OUTSIDE_LOTS' : 'PURCHASE_EXPENSE_OUTSIDE_LOTS', null, '', $project, (string) ($expense->vendor ?? ''), '',
                'expense #'.$expense->id.' '.Money::format((int) $expense->amount).' "'.$expense->category.': '.$expense->description.'" ('.($expense->is_asset ? 'posted as a fixed asset' : $expense->classification).', '.$expense->status.')',
                'part of a lot\'s cost, or confirmed as unrelated', null,
                ($delivery ? 'A delivery charge was recorded as an ordinary project expense and is in no lot\'s landed cost' : 'A product purchase was recorded as an ordinary expense and is in no lot\'s cost').
                ($expense->is_asset ? '; it was also posted to fixed assets (equipment), not to cost' : '').'. Lot and project profit exclude it.',
                'Confirm what it paid for. If it belongs to a lot, reclassify it through a reviewed expense correction — never add it to the lot as a new cost, which would post it twice.');
        }
    }

    /** References that point nowhere or to the wrong place. */
    private function orphans(): void
    {
        $checks = [
            ['sourcing_lot_lines', 'supplier_id', 'suppliers', 'Lot line points to a missing supplier.'],
            ['sourcing_lot_lines', 'supplier_product_id', 'supplier_products', 'Lot line points to a missing product.'],
            ['supplier_payments', 'sourcing_lot_id', 'sourcing_lots', 'Supplier payment points to a missing lot.'],
            ['sourcing_lot_costs', 'expense_id', 'expenses', 'Lot cost points to a missing expense.'],
            ['stock_movements', 'sourcing_lot_line_id', 'sourcing_lot_lines', 'Stock movement points to a missing lot line.'],
            ['client_order_items', 'sourcing_lot_line_id', 'sourcing_lot_lines', 'Order item points to a missing lot line.'],
        ];
        foreach ($checks as [$table, $column, $parent, $reason]) {
            $rows = DB::table($table)->whereNotNull($column)->whereNotIn($column, DB::table($parent)->select('id'))->pluck('id');
            foreach ($rows as $id) {
                $this->add('confirmed', 'orphan', 'MISSING_REFERENCE', null, '', '', '', '', $table.' #'.$id.' → '.$parent, 'an existing row', null, $reason, 'Investigate the deleted parent; restore it or relink through a reviewed correction.');
            }
        }
        $mismatch = DB::table('sourcing_lot_lines as l')->join('supplier_products as p', 'p.id', '=', 'l.supplier_product_id')->whereColumn('p.supplier_id', '!=', 'l.supplier_id')->get(['l.id', 'l.sourcing_lot_id']);
        foreach ($mismatch as $row) {
            $this->add('confirmed', 'orphan', 'PRODUCT_SUPPLIER_MISMATCH', (int) $row->sourcing_lot_id, (string) SourcingLot::whereKey($row->sourcing_lot_id)->value('number'), '', '', '', 'line #'.$row->id, 'product of the line\'s supplier', null,
                'The product on the line belongs to a different supplier.', 'Correct the line\'s product or supplier through a reviewed change.');
        }
        $loose = DB::table('client_order_items as i')->join('client_orders as o', 'o.id', '=', 'i.client_order_id')->whereNull('i.sourcing_lot_line_id')->whereNotNull('i.supplier_product_id')->where('o.status', '!=', 'cancelled')->get(['i.id', 'o.reference']);
        foreach ($loose as $row) {
            $this->add('review', 'orphan', 'ORDER_ITEM_WITHOUT_LOT_LINE', null, '', '', '', '', 'order '.$row->reference.' item #'.$row->id, 'a lot line (stock source)', null,
                'A live order item names a product but no lot line, so its stock and cost cannot be traced to a lot.', 'Link the item to the lot line it is fulfilled from.');
        }
    }

    /**
     * Project profitability before and after the workflow change. "Before" replays the previous rule (margin of client-owned, approved and
     * billed lots); "after" is ProjectEconomics as deployed. For lots without a budget the two must be identical.
     *
     * @return list<array<string, mixed>>
     */
    private function projects(bool $columnsPresent): array
    {
        $rows = [];
        foreach (Project::with('client')->orderBy('id')->get() as $project) {
            $lots = SourcingLot::where('project_id', $project->id)->where('status', '!=', 'cancelled')->with('lines')->get();
            $before = 0;
            $counted = 0;
            foreach ($lots->where('ownership', 'client')->filter(fn ($l) => $l->getAttribute('client_budget') === null) as $lot) {
                $approved = $lot->lines->isNotEmpty() && $lot->lines->every(fn ($l) => $l->approved_price !== null);
                $billed = SourcingAllocation::where('sourcing_lot_id', $lot->id)->where('project_id', $project->id)->where('kind', 'sale')->whereNotNull('billed_invoice_id')->exists();
                if (! $approved || ! $billed) {
                    continue;
                }
                $counted++;
                $before += (int) LotFinancials::for($lot)['profit'];
            }
            // New client lots (with a budget) count once completed, by design; they did not exist under the previous rule.
            $budgetLots = $lots->filter(fn ($l) => $l->getAttribute('client_budget') !== null && in_array($l->status, SourcingLot::STAGE_STATUSES['completed'], true));
            $clientLotMargin = (int) $budgetLots->sum(fn ($l) => LotFinancials::for($l)['profit']);
            $counted += $budgetLots->count();
            // Every lot whose margin the project does not count yet: not client-owned (or ownership unconfirmed), not approved, or not billed.
            $pending = $lots->count() - $counted;
            $after = $columnsPresent ? (int) ProjectEconomics::for($project)['sourcing_margin'] : $before;
            $unexplained = $after - $before - $clientLotMargin;
            $lotRevenue = 0;
            $lotProfit = 0;
            foreach ($lots as $lot) {
                $f = LotFinancials::for($lot);
                $lotRevenue += $f['revenue'];
                $lotProfit += $f['profit'];
            }
            $invoiced = (int) Invoice::where('project_id', $project->id)->whereNull('voided_at')->sum('total');
            $received = (int) DB::table('payments')->where('project_id', $project->id)->whereNull('voided_at')->sum('amount');
            $row = ['project_id' => $project->id, 'project' => $project->name, 'client' => $project->client?->name, 'lots' => $lots->count(), 'pending_lots' => $pending,
                'lot_sales_value' => $lotRevenue, 'lot_profit' => $lotProfit, 'margin_before' => $before, 'client_lot_margin' => $clientLotMargin, 'margin_after' => $after,
                'difference' => $unexplained, 'invoiced' => $invoiced, 'received' => $received, 'after_computed' => $columnsPresent];
            $rows[] = $row;
            if ($unexplained !== 0) {
                $this->add('confirmed', 'profitability', 'MARGIN_CHANGED_BY_WORKFLOW', null, '', $project->name.' (#'.$project->id.')', '', '', Money::format($after).' sourcing margin after', Money::format($before + $clientLotMargin).' (legacy '.Money::format($before).' + completed client lots '.Money::format($clientLotMargin).')', Money::format($unexplained),
                    'The project\'s sourcing margin changes with the new code by more than its completed client lots. A legacy lot is being counted differently (or a client lot twice).', 'Do not deploy until explained.');
            }
            if ($lots->isNotEmpty() && $invoiced > 0 && $before === 0 && $lotRevenue > 0) {
                $this->add('info', 'profitability', 'LOT_MARGIN_PENDING', null, '', $project->name.' (#'.$project->id.')', '', '', $pending.' lot(s) pending; lot sales value '.Money::format($lotRevenue).', lot profit '.Money::format($lotProfit), 'unchanged',
                    null, 'The project\'s income is its manual invoices/payments (invoiced '.Money::format($invoiced).', received '.Money::format($received).'); its lots add no margin until ownership, price and billing are verified. Adding the lot sales value on top of the invoices would count the same revenue twice.', 'None. Keep counting revenue from invoices/payments only.');
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $lots
     * @return array<string, int>
     */
    private function summary(array $lots): array
    {
        $f = collect($this->findings);
        $open = $f->whereIn('class', ['confirmed', 'review']);
        $count = fn (string $category) => $open->where('category', $category)->count();

        return [
            'total_lots' => count($lots), 'legacy_lots' => collect($lots)->where('workflow', 'legacy')->count(),
            'fully_reconciled' => collect($lots)->where('verdict', 'reconciled')->count(), 'needs_review' => collect($lots)->where('verdict', 'needs_review')->count(),
            'quantity_mismatches' => $count('quantity'), 'missing_receiving' => $count('missing_receipt'), 'duplicate_receiving_risks' => $count('duplicate_receipt'),
            'supplier_payment_differences' => $count('supplier_payment'), 'delivery_mismatches' => $count('delivery'), 'status_mapping_reviews' => $count('status'),
            'profitability_differences' => $count('profitability'), 'invoice_duplication_risks' => $count('invoice'), 'orphaned_references' => $count('orphan'),
            'confirmed' => $f->where('class', 'confirmed')->count(), 'review' => $f->where('class', 'review')->count(), 'info' => $f->where('class', 'info')->count(),
        ];
    }

    private function adjustReasons(int $lineId): string
    {
        return DB::table('stock_movements')->where('sourcing_lot_line_id', $lineId)->where('type', 'adjust')->pluck('reason')->filter()->map(fn ($r) => '"'.$r.'"')->implode(', ');
    }

    private function add(string $class, string $category, string $code, ?int $lotId, string $lot, string $project, string $supplier, string $product, string $existing, string $expected, ?string $difference, string $reason, string $suggestion): void
    {
        $this->findings[] = compact('class', 'category', 'code') + ['lot_id' => $lotId, 'lot' => $lot, 'project' => $project, 'supplier' => $supplier, 'product' => $product,
            'existing' => $existing, 'expected' => $expected, 'difference' => $difference, 'reason' => $reason, 'suggestion' => $suggestion];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) || $v === null ? self::safeCell((string) $v) : json_encode($v)), $row), escape: '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** Neutralise spreadsheet formula injection: a cell starting with = + - @ is prefixed with a quote unless it is a plain number. */
    private static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && ! is_numeric($value) ? "'".$value : $value;
    }

    /**
     * The receipt movements of a line: how many were negative (an arrival re-recorded and corrected) and the list, for the report.
     *
     * @return array{negative: int, list: string}
     */
    private function receipts(int $lineId): array
    {
        $deltas = DB::table('stock_movements')->where('sourcing_lot_line_id', $lineId)->where('type', 'receipt')->orderBy('id')->pluck('d_available')->map(fn ($d) => bcadd((string) $d, '0', 2));

        return ['negative' => $deltas->filter(fn ($d) => bccomp($d, '0', 2) < 0)->count(), 'list' => $deltas->map(fn ($d) => bccomp($d, '0', 2) > 0 ? '+'.$d : $d)->implode(', ')];
    }
}
