<?php

use App\Models\Invoice;
use App\Models\SourcingLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\ClientLot;
use App\Services\ClientLotReconciliation;
use App\Services\Inventory;
use App\Services\ProjectEconomics;
use App\Services\Receiving;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| client-lots:reconcile — read-only reconciliation of lots made under the older workflow against the simple client-lot workflow.
| Legacy lots are inserted as raw rows, exactly as the older workflow left them in production (no client budget, receiving kept on the line,
| manual invoices not linked to the lot). Amounts are paisa: BDT 400 = 40_000.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-20');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null, 'finance.sourcing_basis' => 'cash', 'finance.sourcing_recognition_trigger' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->supplier = Supplier::create(['name' => 'Uttara Buying House']);
    $this->product = SupplierProduct::create(['supplier_id' => $this->supplier->id, 'name' => 'Zipper Hoodie', 'unit' => 'pcs', 'reference_price' => 40_000]);
    $this->project = $this->co->project($this->co->client('Suchona Mart'), 'Suchona Mart');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * One lot as the older workflow stored it.
 *
 * @param  array<string, mixed>  $lot  status, project_id, ownership, client_budget …
 * @param  list<array<string, mixed>>  $lines  qty, price, sell, received (null = not received), stock (ledger receipts), adjust
 * @param  array<string, mixed>  $o  paid (supplier, default = full purchase), delivery, delivery_paid (default true), billed (link an invoice)
 */
function rcLegacyLot(array $lot = [], ?array $lines = null, array $o = []): SourcingLot
{
    $t = test();
    $user = $t->co->users['finance']->id;
    $now = now();
    $lines ??= [['qty' => '15', 'price' => 40_000, 'sell' => 49_000, 'received' => '15']];
    $purchase = array_sum(array_map(fn ($l) => (int) bcmul($l['qty'], (string) $l['price'], 0), $lines));
    $revenue = array_sum(array_map(fn ($l) => (int) bcmul($l['qty'], (string) $l['sell'], 0), $lines));
    $delivery = $o['delivery'] ?? 0;
    $number = SourcingLot::nextNumber();

    $model = SourcingLot::create($lot + [
        'number' => $number, 'title' => 'Legacy '.$number, 'status' => 'settled', 'allocation_basis' => 'value', 'pricing_method' => 'fixed', 'pricing_value' => 0,
        'approved_cost' => $purchase + $delivery, 'approved_revenue' => $revenue, 'created_by' => $user, 'priced_by' => $user, 'approved_by' => $user,
        'approved_at' => '2026-11-04 10:00:00', 'project_id' => $t->project->id, 'ownership' => 'client', 'procurement_status' => 'arrived',
    ]);
    foreach ($lines as $i => $l) {
        $lineId = DB::table('sourcing_lot_lines')->insertGetId([
            'sourcing_lot_id' => $model->id, 'supplier_id' => $l['supplier_id'] ?? $t->supplier->id, 'supplier_product_id' => $l['product_id'] ?? $t->product->id,
            'description' => $l['description'] ?? 'Zipper Hoodie', 'unit' => 'pcs', 'quantity' => $l['qty'], 'usable_quantity' => $l['usable'] ?? ($l['received'] ?? $l['qty']),
            'unit_price' => $l['price'], 'reference_price' => $l['price'], 'sell_price' => $l['sell'], 'approved_price' => $l['sell'], 'position' => $i + 1,
            'received_quantity' => $l['received'], 'arrived_on' => $l['received'] !== null ? '2026-11-04' : null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $stock = $l['stock'] ?? $l['received'];
        if ($stock !== null && bccomp($stock, '0', 2) !== 0) {
            DB::table('stock_movements')->insert(['sourcing_lot_line_id' => $lineId, 'type' => 'receipt', 'd_available' => $stock, 'occurred_on' => '2026-11-04',
                'reason' => 'Arrival recorded', 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now]);
        }
        if (! empty($l['adjust'])) {
            DB::table('stock_movements')->insert(['sourcing_lot_line_id' => $lineId, 'type' => 'adjust', 'd_available' => $l['adjust'], 'occurred_on' => '2026-11-10',
                'reason' => 'Ewverse', 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
    $paid = $o['paid'] ?? $purchase;
    if ($paid > 0) {
        $expense = rcExpense('Sourcing: supplier payment', $number.' — supplier #'.$t->supplier->id, $paid);
        DB::table('supplier_payments')->insert(['supplier_id' => $t->supplier->id, 'sourcing_lot_id' => $model->id, 'amount' => $paid, 'paid_on' => '2026-11-03',
            'idempotency_key' => (string) Str::uuid(), 'expense_id' => $expense, 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now]);
    }
    if ($delivery > 0) {
        $expense = ($o['delivery_paid'] ?? true) ? rcExpense('Sourcing: Delivery', $number.' — delivery charge', $delivery) : null;
        DB::table('sourcing_lot_costs')->insert(['sourcing_lot_id' => $model->id, 'category' => 'Delivery', 'description' => 'delivery charge', 'amount' => $delivery,
            'kind' => 'actual', 'incurred_on' => '2026-11-03', 'expense_id' => $expense, 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now]);
    }
    if ($o['billed'] ?? false) {
        $invoice = rcInvoice($revenue, 'Zipper Hoodie');
        foreach ($model->lines()->get() as $line) {
            DB::table('sourcing_allocations')->insert(['sourcing_lot_id' => $model->id, 'sourcing_lot_line_id' => $line->id, 'project_id' => $t->project->id, 'kind' => 'sale',
                'description' => $line->description, 'quantity' => $line->usable_quantity, 'unit_price' => $line->approved_price, 'billed_invoice_id' => $invoice->id,
                'billed_at' => $now, 'created_by' => $user, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    return $model->refresh();
}

function rcExpense(string $category, string $description, int $amount, array $extra = []): int
{
    return DB::table('expenses')->insertGetId($extra + ['category' => $category, 'description' => $description, 'amount' => $amount, 'spent_on' => '2026-11-03',
        'status' => 'verified', 'created_by' => test()->co->users['finance']->id, 'verified_by' => test()->co->users['board']->id, 'verified_at' => now(),
        'payment_status' => 'paid', 'paid_amount' => $amount, 'paid_on' => '2026-11-03', 'created_at' => now(), 'updated_at' => now()]);
}

/** A client invoice entered by hand, the way historical product invoices were (not created from a lot). */
function rcInvoice(int $total, string $description): Invoice
{
    $id = DB::table('invoices')->insertGetId(['project_id' => test()->project->id, 'number' => 'INV-2026-'.Str::upper(Str::random(6)), 'issued_on' => '2026-11-04',
        'currency' => 'BDT', 'total' => $total, 'amount_paid' => $total, 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('invoice_items')->insert(['invoice_id' => $id, 'description' => $description, 'quantity' => 1, 'unit_price' => $total, 'amount' => $total, 'position' => 0,
        'created_at' => now(), 'updated_at' => now()]);

    return Invoice::findOrFail($id);
}

/**
 * @return array<string, mixed>
 */
function rcRun(): array
{
    return ClientLotReconciliation::run();
}

/**
 * @param  array<string, mixed>  $report
 * @return list<string>
 */
function rcCodes(array $report, SourcingLot $lot, ?string $class = null): array
{
    return collect($report['findings'])->where('lot_id', $lot->id)->when($class, fn ($c) => $c->where('class', $class))->pluck('code')->values()->all();
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function rcLot(array $report, SourcingLot $lot): array
{
    return collect($report['lots'])->firstWhere('lot_id', $lot->id);
}

// ---------------------------------------------------------------- Legacy completed lots

it('reconciles a legacy settled lot that was fully received, paid and billed, and keeps its figures', function () {
    $lot = rcLegacyLot(['status' => 'settled', 'settled_at' => '2026-11-10 10:00:00'], null, ['delivery' => 28_000, 'billed' => true]);

    $report = rcRun();
    $row = rcLot($report, $lot);

    expect($row['verdict'])->toBe('reconciled')->and($row['workflow'])->toBe('legacy')
        ->and($row['shown_as'])->toBe('Completed')->and($row['evidence_stage'])->toBe('completed')
        ->and($row['landed'])->toBe(628_000)->and($row['revenue'])->toBe(735_000)->and($row['profit'])->toBe(107_000)
        ->and(rcCodes($report, $lot, 'confirmed'))->toBe([])->and(rcCodes($report, $lot, 'review'))->toBe([])
        ->and(rcCodes($report, $lot))->toContain('NO_CLIENT_BUDGET', 'LEGACY_RECEIVING')
        ->and(collect($report['lines'])->firstWhere('lot_id', $lot->id)['safe_to_receive'])->toBe('0.00');
});

// ---------------------------------------------------------------- Partial receiving and duplicate stock

it('flags a legacy completed lot that is only partly received, and never offers more than the confirmed remainder', function () {
    $lot = rcLegacyLot(['status' => 'completed'], [['qty' => '100', 'price' => 40_000, 'sell' => 49_000, 'received' => '80']], ['billed' => true]);
    $line = $lot->lines()->sole();

    $report = rcRun();
    $lineRow = collect($report['lines'])->firstWhere('lot_id', $lot->id);

    expect(rcLot($report, $lot)['evidence_stage'])->toBe('needs_review')
        ->and(rcCodes($report, $lot, 'review'))->toContain('PARTIALLY_RECEIVED', 'STAGE_EVIDENCE_MISMATCH')
        // The 80 already received are recognised: only 20 are still expected, and even those wait for a person to confirm them.
        ->and($lineRow['received'])->toBe('80.00')->and($lineRow['ledger_receipts'])->toBe('80.00')->and($lineRow['still_expected'])->toBe('20.00')
        ->and($lineRow['safe_to_receive'])->toBe('needs review')
        ->and(Receiving::expected($line))->toBe('20.00')->and(Inventory::balances($line)['available'])->toBe('80.00');
});

it('refuses to run a legacy lot through the simple workflow, so its received goods can never be received again', function () {
    $lot = rcLegacyLot(['status' => 'in_progress'], [['qty' => '100', 'price' => 40_000, 'sell' => 49_000, 'received' => '80']]);
    $approved = rcLegacyLot(['status' => 'approved']);
    $movements = StockMovement::count();

    expect(fn () => ClientLot::complete($lot, ['arrived_on' => '2026-11-20', 'reference' => 'GRN-9'], $this->co->users['finance']))
        ->toThrow(RuntimeException::class, 'not made on the simple client-lot form')
        ->and(fn () => ClientLot::markInOrder($approved, 0, $this->co->users['finance']))
        ->toThrow(RuntimeException::class, 'not made on the simple client-lot form');

    $this->co->post('finance', 'sourcing.lots.complete', ['arrived_on' => '2026-11-20', 'reference' => 'GRN-9'], $lot);

    expect(StockMovement::count())->toBe($movements)->and(Inventory::balances($lot->lines()->sole())['available'])->toBe('80.00')
        ->and($lot->refresh()->status)->toBe('in_progress')->and($lot->completed_at)->toBeNull();
});

it('confirms stock that exceeds what was received (a positive adjustment on top of the receipt)', function () {
    $lot = rcLegacyLot([], [['qty' => '15', 'price' => 40_000, 'sell' => 49_000, 'received' => '15', 'adjust' => '15']], ['billed' => true]);

    $report = rcRun();
    $finding = collect($report['findings'])->firstWhere('code', 'STOCK_EXCEEDS_RECEIVED');

    expect($finding['class'])->toBe('confirmed')->and($finding['category'])->toBe('duplicate_receipt')->and($finding['difference'])->toBe('15.00')
        ->and($finding['reason'])->toContain('"Ewverse"')
        ->and(collect($report['lines'])->firstWhere('lot_id', $lot->id)['stock_total'])->toBe('30.00')
        ->and($report['summary']['duplicate_receiving_risks'])->toBe(1);
});

it('confirms stock receipts that the line never recorded as an arrival', function () {
    $lot = rcLegacyLot(['status' => 'approved'], [['qty' => '10', 'price' => 40_000, 'sell' => 49_000, 'received' => null, 'stock' => '10']]);

    $report = rcRun();

    expect(rcCodes($report, $lot, 'confirmed'))->toContain('LEDGER_WITHOUT_ARRIVAL')->and($report['summary']['missing_receiving'])->toBeGreaterThanOrEqual(1);
});

// ---------------------------------------------------------------- Money

it('matches existing supplier payments and confirms an overpayment', function () {
    $paid = rcLegacyLot([], null, ['billed' => true]);
    $over = rcLegacyLot([], null, ['paid' => 700_000, 'billed' => true]);

    $report = rcRun();
    $finding = collect($report['findings'])->firstWhere('code', 'SUPPLIER_OVERPAID');

    expect(collect(rcCodes($report, $paid))->filter(fn ($c) => str_starts_with($c, 'SUPPLIER_') || str_starts_with($c, 'PAYMENT_')))->toBeEmpty()
        ->and($finding['lot_id'])->toBe($over->id)->and($finding['class'])->toBe('confirmed')->and($finding['difference'])->toBe(Money::format(100_000));
});

it('flags an unpaid delivery charge on a completed lot and a delivery expense recorded outside any lot', function () {
    $lot = rcLegacyLot(['status' => 'completed'], null, ['delivery' => 50_000, 'delivery_paid' => false, 'billed' => true]);
    rcExpense('Delivery', 'products delivery charge', 80_000, ['project_id' => $this->project->id, 'is_asset' => 1, 'classification' => 'asset']);

    $report = rcRun();
    $unpaid = collect($report['findings'])->firstWhere('code', 'COST_UNPAID');
    $outside = collect($report['findings'])->firstWhere('code', 'DELIVERY_EXPENSE_OUTSIDE_LOTS');

    expect($unpaid['lot_id'])->toBe($lot->id)->and($unpaid['category'])->toBe('delivery')->and($unpaid['class'])->toBe('review')
        ->and($outside['class'])->toBe('review')->and($outside['reason'])->toContain('fixed assets')
        ->and($report['summary']['delivery_mismatches'])->toBe(2);
});

it('reports a legacy loss as information, not as a discrepancy', function () {
    $lot = rcLegacyLot([], [['qty' => '10', 'price' => 50_000, 'sell' => 40_000, 'received' => '10']], ['billed' => true]);

    $report = rcRun();

    expect(rcLot($report, $lot)['profit'])->toBe(-100_000)->and(rcLot($report, $lot)['verdict'])->toBe('reconciled')
        ->and(collect($report['findings'])->firstWhere('code', 'NEGATIVE_PROFIT')['class'])->toBe('info');
});

it('treats a missing budget on a legacy lot as normal, and confirms a budget set on a legacy lot by hand', function () {
    $legacy = rcLegacyLot([], null, ['billed' => true]);
    $tampered = rcLegacyLot(['client_budget' => 900_000], null, ['billed' => true]);

    $report = rcRun();

    expect(collect($report['findings'])->where('lot_id', $legacy->id)->firstWhere('code', 'NO_CLIENT_BUDGET')['class'])->toBe('info')
        ->and(rcLot($report, $legacy)['verdict'])->toBe('reconciled')
        ->and(rcCodes($report, $tampered, 'confirmed'))->toContain('BUDGET_ON_LEGACY_LOT');
});

// ---------------------------------------------------------------- Invoices

it('warns that a legacy lot billed by a manual invoice would be billed again from the lot, and creates no invoice', function () {
    rcInvoice(735_000, 'Zipper Hoodie - L');
    $lot = rcLegacyLot(['status' => 'approved']);
    $invoices = Invoice::count();

    $report = rcRun();
    $finding = collect($report['findings'])->firstWhere('code', 'MANUAL_INVOICES_NOT_LINKED');

    expect($finding['lot_id'])->toBe($lot->id)->and($finding['class'])->toBe('review')->and($finding['expected'])->toContain('Zipper Hoodie - L')
        ->and($report['summary']['invoice_duplication_risks'])->toBe(1)->and(Invoice::count())->toBe($invoices);
});

// ---------------------------------------------------------------- Status mapping

it('maps legacy statuses only where the evidence agrees, and never rewrites status or timestamps', function () {
    $receivedButApproved = rcLegacyLot(['status' => 'approved'], null, ['billed' => true]);
    $paidNotReceived = rcLegacyLot(['status' => 'approved', 'procurement_status' => 'ordered'], [['qty' => '6', 'price' => 155_000, 'sell' => 159_167, 'received' => null]], ['billed' => true]);
    $ordered = rcLegacyLot(['status' => 'in_progress'], [['qty' => '6', 'price' => 155_000, 'sell' => 159_167, 'received' => null]], ['billed' => true]);
    $cancelled = rcLegacyLot(['status' => 'cancelled', 'cancelled_at' => '2026-11-05 09:00:00', 'cancel_reason' => 'Supplier out of stock'], [['qty' => '5', 'price' => 10_000, 'sell' => 12_000, 'received' => null]], ['paid' => 0]);
    $before = SourcingLot::orderBy('id')->get(['id', 'status', 'approved_at', 'settled_at', 'cancelled_at', 'updated_at'])->toArray();

    $report = rcRun();

    expect(rcLot($report, $receivedButApproved)['shown_as'])->toBe('Processing')->and(rcLot($report, $receivedButApproved)['evidence_stage'])->toBe('needs_review')
        ->and(rcLot($report, $paidNotReceived)['evidence_stage'])->toBe('needs_review')
        ->and(rcLot($report, $ordered)['evidence_stage'])->toBe('in_order')->and(rcCodes($report, $ordered))->not->toContain('STAGE_EVIDENCE_MISMATCH')
        ->and(rcLot($report, $cancelled)['evidence_stage'])->toBe('cancelled')->and(rcLot($report, $cancelled)['shown_as'])->toBe('Cancelled')
        ->and(SourcingLot::orderBy('id')->get(['id', 'status', 'approved_at', 'settled_at', 'cancelled_at', 'updated_at'])->toArray())->toBe($before);
});

it('flags a lot reopened after settlement and not settled again', function () {
    $lot = rcLegacyLot(['status' => 'completed'], null, ['billed' => true]);
    DB::table('audit_logs')->insert(['user_id' => $this->co->users['finance']->id, 'action' => 'sourcing.reopened', 'subject' => 'lot '.$lot->number, 'details' => 'fix issues', 'created_at' => now()]);

    expect(rcCodes(rcRun(), $lot, 'review'))->toContain('REOPENED_AFTER_SETTLEMENT');
});

// ---------------------------------------------------------------- Project profitability

it('keeps project profitability identical for legacy lots and adds a completed client lot exactly once', function () {
    rcLegacyLot(['status' => 'settled'], null, ['delivery' => 28_000, 'billed' => true]);

    // A new client lot through the real workflow: 10 × BDT 150, delivery BDT 100, budget BDT 2,000 → profit BDT 400.
    $product = SupplierProduct::create(['supplier_id' => $this->supplier->id, 'name' => 'Coffee Mug', 'unit' => 'pcs', 'reference_price' => 15_000]);
    $this->co->post('finance', 'sourcing.client-lots.store', ['supplier_id' => $this->supplier->id, 'supplier_product_id' => $product->id, 'project_id' => $this->project->id,
        'quantity' => '10', 'unit_cost' => '150', 'client_budget' => '2000', 'submission_key' => (string) Str::uuid()])->assertSessionHasNoErrors()->assertSessionMissing('error');
    $clientLot = SourcingLot::latest('id')->firstOrFail();
    $this->co->post('finance', 'sourcing.lots.order', ['delivery_charge' => '100'], $clientLot)->assertSessionMissing('error');
    $this->co->post('finance', 'sourcing.lots.complete', ['arrived_on' => '2026-11-19', 'reference' => 'GRN-1', 'supplier_paid' => '1', 'delivery_paid' => '1'], $clientLot)->assertSessionMissing('error');
    expect($clientLot->refresh()->stage())->toBe('completed');

    $report = rcRun();
    $project = collect($report['projects'])->firstWhere('project_id', $this->project->id);

    expect($project['margin_before'])->toBe(107_000)->and($project['client_lot_margin'])->toBe(40_000)
        ->and($project['margin_after'])->toBe(147_000)->and($project['margin_after'])->toBe(ProjectEconomics::for($this->project)['sourcing_margin'])
        ->and($project['difference'])->toBe(0)
        ->and(collect($report['findings'])->where('code', 'MARGIN_CHANGED_BY_WORKFLOW'))->toBeEmpty()
        ->and(rcLot($report, $clientLot)['workflow'])->toBe('client_lot')->and(rcCodes($report, $clientLot))->not->toContain('NO_CLIENT_BUDGET');
});

it('confirms the five workflow columns are present and NULL for every legacy lot', function () {
    rcLegacyLot([], null, ['billed' => true]);
    rcLegacyLot(['status' => 'approved']);

    $schema = rcRun()['schema'];

    expect($schema['columns_present'])->toBeTrue()->and($schema['pending_migrations'])->toBe([])
        ->and($schema['non_null'])->toBe(['client_budget' => 0, 'ordered_at' => 0, 'ordered_by' => 0, 'completed_at' => 0, 'completed_by' => 0]);
});

// ---------------------------------------------------------------- Command and repeated execution

it('refuses to run without --dry-run', function () {
    $this->artisan('client-lots:reconcile')->expectsOutputToContain('--dry-run')->assertExitCode(Command::INVALID);
});

it('changes nothing and gives the same report every time it runs', function () {
    rcLegacyLot([], [['qty' => '15', 'price' => 40_000, 'sell' => 49_000, 'received' => '15', 'adjust' => '15']], ['billed' => true]);
    rcInvoice(100_000, 'Ladies Coat');
    rcLegacyLot(['status' => 'approved'], [['qty' => '6', 'price' => 155_000, 'sell' => 159_167, 'received' => null]]);
    $fingerprint = ClientLotReconciliation::fingerprint();

    $first = Artisan::call('client-lots:reconcile', ['--dry-run' => true, '--json' => true]);
    $firstOutput = Artisan::output();
    $second = Artisan::call('client-lots:reconcile', ['--dry-run' => true, '--json' => true]);

    expect($first)->toBe(Command::FAILURE)->and($second)->toBe($first)->and(Artisan::output())->toBe($firstOutput)
        ->and(ClientLotReconciliation::fingerprint())->toBe($fingerprint)
        ->and(json_decode($firstOutput, true)['summary']['confirmed'])->toBe(1);
});

it('writes the findings and per-product quantities to CSV with every discrepancy field', function () {
    rcLegacyLot([], [['qty' => '15', 'price' => 40_000, 'sell' => 49_000, 'received' => '15', 'adjust' => '15']], ['billed' => true]);
    $path = 'reconciliation/test-'.Str::random(6).'.csv';

    Artisan::call('client-lots:reconcile', ['--dry-run' => true, '--csv' => $path]);

    $findings = array_map('str_getcsv', file(storage_path('app/'.$path), FILE_IGNORE_NEW_LINES));
    $lines = file(storage_path('app/'.str_replace('.csv', '-lines.csv', $path)), FILE_IGNORE_NEW_LINES);
    expect($findings[0])->toBe(['class', 'category', 'code', 'lot_id', 'lot', 'project', 'supplier', 'product', 'existing', 'expected', 'difference', 'reason', 'suggestion'])
        ->and(collect($findings)->pluck(2))->toContain('STOCK_EXCEEDS_RECEIVED')
        ->and($lines[0])->toContain('safe_to_receive');

    @unlink(storage_path('app/'.$path));
    @unlink(storage_path('app/'.str_replace('.csv', '-lines.csv', $path)));
});
