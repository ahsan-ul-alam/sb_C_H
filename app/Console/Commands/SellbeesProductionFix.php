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
        {--as= : Email of the Finance or Board user running this; every action is attributed to them (required with --apply)}
        {--baseline : ONE-TIME FINANCIAL BASELINE mode (irreversible with --apply): voids the erroneous receipts by explicit administrative correction and posts the Legacy Opening Balance Adjustment}
        {--cutoff= : Baseline: the date and time the owner\'s balance is true, e.g. "2026-11-08 12:00"}
        {--owner-cash= : Baseline: the owner-confirmed actual cash, e.g. 49000}
        {--expected-cash= : Baseline: the expected cash after the corrections that the preview computed, e.g. 43571 (the run stops if it differs)}
        {--backup-file= : Baseline: path of a database dump taken within the last two hours}
        {--owner-authorization= : Baseline: who authorized this, when and how (written down permanently)}
        {--confirm= : Baseline: the exact irreversible-cutover confirmation sentence printed by the preview}';

    protected $description = 'ONE-TIME, owner-approved operational cutover. Default is a read-only preview. Reports TECHNICAL CUTOVER and FINANCIAL RECONCILIATION separately; the technical mode never voids, verifies or rewrites a financial record; --baseline is the separate, explicitly authorized financial baseline. Refuses to run twice.';

    public function handle(): int
    {
        if ($this->option('baseline')) {
            return $this->option('apply') ? $this->applyBaseline() : $this->previewBaseline();
        }
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
        if ($report['evidence'] === 'owner_confirmed') {
            $this->line('EVIDENCE: the actual balance is owner-confirmed only; no independent bank/bKash statement backs it.');
        }
    }

    /** @return array<string, mixed> */
    private function baselineInput(): array
    {
        return ['database' => (string) $this->option('expect-database'), 'fingerprint' => (string) $this->option('fingerprint'), 'operator' => (string) $this->option('as'), 'cutoff' => (string) $this->option('cutoff'),
            'owner_cash' => (string) $this->option('owner-cash'), 'expected_cash' => (string) $this->option('expected-cash'), 'backup' => (string) $this->option('backup-file'),
            'authorization' => (string) $this->option('owner-authorization'), 'confirm' => (string) $this->option('confirm')];
    }

    private function previewBaseline(): int
    {
        $report = ProductionFix::report();
        $plan = ProductionFix::baselinePlan($this->baselineInput());
        $this->warn('BASELINE PREVIEW ONLY: nothing is changed. Applying it is irreversible.');
        if ($report['baseline_applied']) {
            $this->info('The financial baseline was already applied; --baseline --apply will refuse.');
        }
        $this->body($report);
        $this->section('Exact before / after (the cutoff date and the owner\'s figures are the inputs you supplied)');
        if ($plan['cutoff_date'] === null) {
            $this->line('  Give --cutoff="YYYY-MM-DD HH:MM" to compute the plan.');
        } else {
            $this->line('  Ledger cash (cash lines only, today): '.$this->money((int) $plan['ledger_cash_before']));
            $this->line('  ERP expected cash at the cutoff, before corrections: '.$this->money((int) $plan['expected_before']));
            foreach ($plan['voids'] as $id => $amount) {
                $this->line('  less erroneous receipt #'.$id.' (voided by administrative correction, record kept): -'.$this->money($amount));
            }
            $this->line('  = Reconstructed historical cash after corrections: '.$this->money((int) $plan['expected_after']));
            if ($plan['owner_cash'] !== null) {
                $this->line('  Owner-confirmed actual cash (Bank + bKash + Cash in Hand): '.$this->money($plan['owner_cash']).' (NOT independently bank-verified)');
                $this->line('  Legacy Opening Balance Adjustment / Historical Cash Difference: '.($plan['adjustment'] >= 0 ? '+' : '-').$this->money(abs((int) $plan['adjustment'])).' (one balanced entry, Dr/Cr cash and opening equity; never revenue, profit, expense, loan, supplier payment or capital)');
                $this->line('  Cash after: ledger '.$this->money((int) $plan['ledger_cash_before'] + (int) $plan['adjustment']).'; expected cash at the cutoff '.$this->money($plan['owner_cash']).'; unreconciled difference 0 at the cutoff.');
                foreach ($plan['later_repayments'] ?? [] as $id => $amount) {
                    $this->line('  after the cutoff, kept exactly as recorded (verified loan repayment #'.$id.'): +'.$this->money((int) $amount));
                }
                $this->line('  Expected cash TODAY once the baseline is applied (owner balance at the cutoff + what happened after it): '.$this->money((int) $plan['expected_today_after']));
            }
            $this->line('  Backup: '.($plan['backup'] ? $plan['backup']['path'].' ('.$plan['backup']['modified'].', sha256 '.substr((string) $plan['backup']['sha256'], 0, 16).'...)' : 'none given (--backup-file is required to apply)'));
        }
        foreach ($plan['problems'] as $p) {
            $this->warn('  Cannot apply yet: '.$p);
        }
        $this->results($report);
        if ($report['blockers'] === []) {
            $this->line('');
            $this->line('To apply (irreversible): php artisan sellbees:production-fix --baseline --apply --expect-database='.$report['database'].' --fingerprint='.$report['fingerprint']
                .' --as=<your email> --cutoff="<YYYY-MM-DD HH:MM>" --owner-cash=<amount> --expected-cash=<the expected cash after corrections above> --backup-file=<fresh dump> --owner-authorization="<who, when, how>" --confirm="'.ProductionFix::CONFIRMATION.'"');
        } else {
            $this->error('Blocked: --apply would refuse until every technical check above passes.');
        }

        return $report['blockers'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function applyBaseline(): int
    {
        try {
            $result = ProductionFix::applyBaseline($this->baselineInput());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->section('Corrections performed');
        foreach ($result['repaired'] as $line) {
            $this->line('  '.$line);
        }
        $this->body($result['report']);
        $this->results($result['report']);
        $this->line('The baseline is recorded permanently (audit log "cash.baseline_set" and journal source "'.ProductionFix::BASELINE_SOURCE.'"): reconstructed '.$this->money((int) $result['plan']['expected_after']).' + adjustment '.$this->money((int) $result['plan']['adjustment']).' = owner-confirmed '.$this->money((int) $result['plan']['owner_cash']).'.');
        $this->line('This command will not run again. Next: verify the checklist, then remove the command (see the deployment notes).');

        return self::SUCCESS;
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
