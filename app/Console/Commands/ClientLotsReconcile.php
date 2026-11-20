<?php

namespace App\Console\Commands;

use App\Services\ClientLotReconciliation;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClientLotsReconcile extends Command
{
    protected $signature = 'client-lots:reconcile
        {--dry-run : Required. The command has no write mode; this flag makes the read-only intent explicit}
        {--csv= : Write the findings to this CSV file under storage/app (a second "-lines" CSV holds the per-product quantities)}
        {--json : Print the whole report as JSON}
        {--all : Also print informational findings}';

    protected $description = 'READ-ONLY reconciliation of historical sourcing lots against the simplified client-lot workflow: stage mapping, quantities vs the stock ledger, duplicate receiving, supplier/delivery payments, budgets, invoices and project profitability. Changes nothing. Exit 0 = clean, 2 = only items needing review, 1 = confirmed discrepancies.';

    public function handle(): int
    {
        if (! $this->option('dry-run')) {
            $this->error('This command only reads. Run it as: php artisan client-lots:reconcile --dry-run');

            return self::INVALID;
        }

        // Belt and braces: the report only reads, and it also runs inside a transaction that is always rolled back, with a fingerprint of every
        // relevant table taken before and after. Any difference aborts with an error.
        $before = ClientLotReconciliation::fingerprint();
        DB::beginTransaction();
        try {
            $report = ClientLotReconciliation::run();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        DB::rollBack();
        $after = ClientLotReconciliation::fingerprint();
        if ($before !== $after) {
            $this->error('The data changed while the report ran (another user, or a defect). Nothing was written by this command; re-run it. Changed: '.implode(', ', array_keys(array_diff_assoc($after, $before))));

            return self::FAILURE;
        }

        if ($path = $this->option('csv')) {
            $file = storage_path('app/'.ltrim((string) $path, '/'));
            @mkdir(dirname($file), 0750, true);
            file_put_contents($file, ClientLotReconciliation::csv($report['findings']));
            file_put_contents(preg_replace('/(\.csv)?$/', '-lines.csv', $file, 1), ClientLotReconciliation::csv($report['lines']));
            $this->info('CSV written to '.$file.' (and the -lines file next to it).');
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->render($report);
        }

        $s = $report['summary'];

        return $s['confirmed'] > 0 ? self::FAILURE : ($s['review'] > 0 ? 2 : self::SUCCESS);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $schema = $report['schema'];
        $s = $report['summary'];
        $this->line('CLIENT LOT RECONCILIATION — DRY RUN (read-only; database: '.$schema['driver'].')');
        $this->newLine();

        $this->line('Migration compatibility');
        $this->line('  Workflow columns ('.implode(', ', ClientLotReconciliation::WORKFLOW_COLUMNS).'): '.($schema['columns_present'] ? 'present' : 'NOT migrated yet — legacy figures used for the "after" side'));
        if ($schema['columns_present']) {
            $this->line('  Non-NULL values: '.collect($schema['non_null'])->map(fn ($n, $c) => $c.'='.$n)->implode(', '));
        }
        $this->line('  Pending migrations: '.($schema['pending_migrations'] === [] ? 'none' : implode(', ', $schema['pending_migrations'])));
        $this->newLine();

        $this->table(['Summary', 'Count'], collect([
            'Total lots' => $s['total_lots'], 'Legacy lots (no client budget)' => $s['legacy_lots'], 'Fully reconciled' => $s['fully_reconciled'],
            'Requiring review' => $s['needs_review'], 'Quantity mismatches' => $s['quantity_mismatches'], 'Missing receiving records' => $s['missing_receiving'],
            'Duplicate receiving risks' => $s['duplicate_receiving_risks'], 'Supplier payment differences' => $s['supplier_payment_differences'],
            'Delivery charge mismatches' => $s['delivery_mismatches'], 'Stage mapping reviews' => $s['status_mapping_reviews'],
            'Project profitability items' => $s['profitability_differences'], 'Invoice duplication risks' => $s['invoice_duplication_risks'],
            'Orphaned / inconsistent references' => $s['orphaned_references'], 'Confirmed discrepancies' => $s['confirmed'], 'Requiring manual investigation' => $s['review'],
        ])->map(fn ($v, $k) => [$k, $v])->values()->all());

        $this->table(['Lot', 'Project', 'Status', 'Shown as', 'Evidence stage', 'Landed', 'Sales value', 'Profit', 'Verdict'], collect($report['lots'])->map(fn ($l) => [
            $l['lot'], $l['project'] ?: '—', $l['status'], $l['shown_as'], $l['evidence_stage'], Money::format($l['landed']), Money::format($l['revenue']), Money::format($l['profit']),
            $l['verdict'] === 'reconciled' ? 'reconciled' : 'NEEDS REVIEW ('.$l['open_findings'].')',
        ])->all());

        $this->table(['Lot', 'Product', 'Ordered', 'Received', 'Ledger receipts', 'Adjusted', 'In stock ledger', 'On hand', 'Still expected', 'Safe to receive'], collect($report['lines'])->map(fn ($l) => [
            $l['lot'], $l['product'], $l['ordered'], $l['received'] ?? '—', $l['ledger_receipts'], $l['adjusted'], $l['stock_total'], $l['on_hand'], $l['still_expected'], $l['safe_to_receive'],
        ])->all());

        $this->table(['Project', 'Lots', 'Not in margin', 'Lot sales value', 'Lot profit', 'Margin before', '+ Client lots', 'Margin after', 'Unexplained', 'Invoiced', 'Received'], collect($report['projects'])->map(fn ($p) => [
            $p['project'], $p['lots'], $p['pending_lots'], Money::format($p['lot_sales_value']), Money::format($p['lot_profit']), Money::format($p['margin_before']), Money::format($p['client_lot_margin']),
            Money::format($p['margin_after']).($p['after_computed'] ? '' : '*'), Money::format($p['difference']), Money::format($p['invoiced']), Money::format($p['received']),
        ])->all());

        foreach (['confirmed' => 'CONFIRMED DISCREPANCIES', 'review' => 'REQUIRES MANUAL INVESTIGATION', 'info' => 'INFORMATION'] as $class => $title) {
            $items = collect($report['findings'])->where('class', $class);
            if ($items->isEmpty() || ($class === 'info' && ! $this->option('all'))) {
                continue;
            }
            $this->newLine();
            $this->line($title.' ('.$items->count().')');
            foreach ($items as $i) {
                $this->line(sprintf('  [%s] %s %s%s', ClientLotReconciliation::CATEGORIES[$i['category']], $i['code'], $i['lot'] ?: '', $i['product'] ? ' — '.$i['product'] : ($i['project'] ? ' — '.$i['project'] : '')));
                $this->line('      existing: '.$i['existing'].' | expected: '.$i['expected'].($i['difference'] !== null ? ' | difference: '.$i['difference'] : ''));
                $this->line('      why: '.$i['reason']);
                $this->line('      suggested: '.$i['suggestion']);
            }
        }
        $this->newLine();
        $this->line("Confirmed {$s['confirmed']}, needing review {$s['review']}, information {$s['info']}".($this->option('all') ? '' : ' (use --all to list information)').'. Nothing was changed.');
    }
}
