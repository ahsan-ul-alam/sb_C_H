<?php

namespace App\Console\Commands;

use App\Services\ProductionFix;
use App\Support\Money;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * TEMPORARY one-time production repair. Remove this file, App\Services\ProductionFix and tests/Feature/ProductionFixTest.php after the successful run.
 */
class SellbeesProductionFix extends Command
{
    protected $signature = 'sellbees:production-fix
        {--apply : Complete the technical cutover. Without it the command only previews and changes nothing}
        {--expect-database= : The database name the preview printed (required with --apply)}
        {--fingerprint= : The state fingerprint the preview printed (required with --apply)}
        {--as= : Email of the Finance or Board user running this; every action is attributed to them (required with --apply)}';

    protected $description = 'ONE-TIME, owner-approved operational cutover. Default is a read-only preview. Reports TECHNICAL CUTOVER and FINANCIAL RECONCILIATION separately; never voids, verifies or rewrites a financial record; refuses to run twice.';

    public function handle(): int
    {
        if (! $this->option('apply')) {
            return $this->preview();
        }

        try {
            $result = ProductionFix::apply((string) $this->option('expect-database'), (string) $this->option('fingerprint'), (string) $this->option('as'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->section('Repairs performed');
        foreach ($result['repaired'] ?: ['nothing needed rebuilding'] as $line) {
            $this->line('  '.$line);
        }
        $this->line('  '.$result['registered'].' historical item(s) registered for legacy review (Finance > Historical Reconciliation)');
        $this->body($result['report']);
        $this->results($result['report']);
        $this->line('The execution marker is recorded for the TECHNICAL cutover only. This command will not run again. It does not certify financial reconciliation.');
        $this->line('Next: verify the checklist, then remove the command (see the deployment notes).');

        return self::SUCCESS;
    }

    private function preview(): int
    {
        $report = ProductionFix::report();
        $this->warn('PREVIEW ONLY: nothing is changed.');
        if ($report['applied']) {
            $this->info('The technical cutover was already completed; --apply will refuse. Showing the current verification only.');
        }
        $this->body($report);
        $this->results($report);
        if ($report['technical'] === 'READY') {
            $this->line('');
            $this->line('To apply: php artisan sellbees:production-fix --apply --expect-database='.$report['database'].' --fingerprint='.$report['fingerprint'].' --as=<your email>');
        } elseif ($report['technical'] === 'BLOCKED') {
            $this->error('Blocked: --apply would refuse until every technical check above passes.');
        }

        return $report['technical'] === 'BLOCKED' ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function results(array $report): void
    {
        $this->line('');
        $this->line('TECHNICAL CUTOVER: '.($report['technical'] === 'READY' ? 'NOT YET APPLIED (all checks pass)' : $report['technical']));
        $this->line('FINANCIAL RECONCILIATION: '.$report['financial'].($report['financial'] === 'PENDING' ? ' (historical items stay visible; they do not block operating the ERP)' : ''));
    }

    /** @param array<string, mixed> $report */
    private function body(array $report): void
    {
        $this->section('Database');
        $this->line('  '.$report['database']);
        $this->section('Preconditions');
        foreach ($report['checks'] as $c) {
            $this->line('  ['.($c['ok'] ? 'ok' : 'FAIL').'] '.$c['label'].($c['ok'] || $c['detail'] === '' ? '' : ' - '.$c['detail']));
        }
        $this->section('Erroneous Suchona Mart receipts (voided only through Finance proposal + different Board approval)');
        foreach ($report['voids'] as $id => $v) {
            $this->line('  payment #'.$id.': '.$v['state'].' - '.$v['next']);
        }
        $this->section('Invoices (totals are never changed)');
        foreach ($report['invoices'] as $id => $i) {
            $this->line($i ? '  #'.$id.' '.$i['number'].': total '.$this->money($i['total']).', paid '.$this->money($i['paid']).', outstanding '.$this->money($i['balance']).', '.$i['status'] : '  #'.$id.': missing');
        }
        $this->section('Derived totals out of step with their payments');
        foreach ($report['drift'] as $d) {
            $this->line('  '.$d['number'].': stored '.$d['stored_paid'].'/'.$d['stored_status'].' should be '.$d['computed_paid'].'/'.$d['computed_status']);
        }
        if ($report['sb_drift']) {
            $this->line('  SB counter '.$report['sb_drift']['last_value'].' is behind the highest number in use ('.$report['sb_drift']['max'].')');
        }
        if ($report['drift'] === [] && ! $report['sb_drift']) {
            $this->line('  none');
        }
        $this->section('Loans receivable (never changed)');
        $this->line('  '.$report['loans']['count'].' verified loan(s), principal '.$this->money($report['loans']['principal']).', outstanding '.$this->money($report['loans']['outstanding']));
        $this->section('Cash reconciliation (read from the live database; never stored or hardcoded)');
        $cash = $report['cash'];
        if ($cash === null) {
            $this->line('  not available until the migrations are applied');
        } else {
            $this->line('  ERP expected cash: '.$this->money($cash['expected']).' ('.$cash['expected_as_of'].')');
            if ($cash['status'] === 'not_verified') {
                $this->line('  Actual balance: Not Verified - '.$cash['reason']);
            } else {
                $this->line('  Actual balance: '.$this->money($cash['actual']).' ('.($cash['evidence'] === 'owner_confirmed' ? 'owner-confirmed, not statement-verified' : 'statement-verified').', as of '.$cash['cutoff'].')');
                $this->line('  Unreconciled cash difference (actual - expected): '.$cash['sign'].$this->money(abs($cash['difference'])).' - an unresolved difference, not income, expense or loss');
            }
        }
        $this->section('Outstanding items (not done by this command)');
        foreach ($report['outstanding'] ?: ['none'] as $o) {
            $this->line('  - '.$o);
        }
    }

    private function money(int $minor): string
    {
        return Money::format($minor, config('finance.currency'));
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->info($title);
    }
}
