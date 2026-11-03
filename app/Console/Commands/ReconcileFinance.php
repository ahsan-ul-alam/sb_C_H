<?php

namespace App\Console\Commands;

use App\Services\ReconciliationService;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:reconcile {--csv= : Write the report to this CSV file (relative to storage/app)} {--backup : First snapshot the finance tables to storage/app/finance-backups}')]
#[Description('Read-only financial reconciliation: recorded vs recalculated figures, with reasons and proposed corrections. Changes nothing.')]
class ReconcileFinance extends Command
{
    public function handle(): int
    {
        if ($this->option('backup')) {
            $this->info('Backup written to '.ReconciliationService::backup());
        }

        $rows = ReconciliationService::report();
        $fmt = fn (array $r, string $key): string => $r['unit'] === 'count' ? (string) $r[$key] : Money::format($r[$key]);

        $this->table(['Area', 'Check', 'Recorded', 'Recalculated', 'Difference', 'OK'], array_map(
            fn ($r) => [$r['area'], $r['check'], $fmt($r, 'recorded'), $fmt($r, 'recalculated'), $fmt($r, 'difference'), $r['ok'] ? 'yes' : 'NO'], $rows,
        ));

        foreach (array_filter($rows, fn ($r) => ! $r['ok']) as $r) {
            $this->warn($r['area'].' — '.$r['check']);
            $this->line('  Reason:   '.$r['reason']);
            $this->line('  Proposal: '.$r['proposal']);
        }

        if ($path = $this->option('csv')) {
            $file = storage_path('app/'.ltrim($path, '/'));
            $out = fopen($file, 'w');
            fputcsv($out, ['area', 'check', 'recorded', 'recalculated', 'difference', 'ok', 'reason', 'proposal']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['area'], $r['check'], $fmt($r, 'recorded'), $fmt($r, 'recalculated'), $fmt($r, 'difference'), $r['ok'] ? 'yes' : 'no', $r['reason'], $r['proposal']]);
            }
            fclose($out);
            $this->info('Report written to '.$file);
        }

        $bad = count(array_filter($rows, fn ($r) => ! $r['ok']));
        $this->line($bad === 0 ? 'All checks agree.' : $bad.' check(s) need review. No data was changed.');

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
