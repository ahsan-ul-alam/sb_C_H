<?php

namespace App\Console\Commands;

use App\Services\SellbeesReconciliation;
use App\Support\Money;
use Illuminate\Console\Command;

class SellbeesReconcile extends Command
{
    protected $signature = 'sellbees:reconcile
        {--json : Print the findings as JSON}
        {--all : Also list the checks that passed}
        {--snapshot= : Write a history fingerprint (journals, allocations, periods, payments) to this file under storage/app}
        {--compare= : Compare the live history with a fingerprint written earlier by --snapshot}';

    protected $description = 'READ-ONLY master reconciliation: ledger, inventory, receipts, invoices, supplier, ownership, clients and frozen allocations. Changes nothing. Exit 0 = clean, 2 = only documented historical exceptions, 1 = integrity failure.';

    public function handle(): int
    {
        $checks = SellbeesReconciliation::run();

        if ($path = $this->option('compare')) {
            $file = storage_path('app/'.ltrim((string) $path, '/'));
            if (! is_file($file)) {
                $this->error('No fingerprint file at '.$file);

                return self::FAILURE;
            }
            $checks = [...$checks, ...SellbeesReconciliation::compare((array) json_decode((string) file_get_contents($file), true))];
        }
        if ($path = $this->option('snapshot')) {
            $file = storage_path('app/'.ltrim((string) $path, '/'));
            @mkdir(dirname($file), 0750, true);
            file_put_contents($file, json_encode(SellbeesReconciliation::snapshot(), JSON_PRETTY_PRINT));
            $this->info('History fingerprint written to '.$file);
        }

        $counts = SellbeesReconciliation::counts($checks);
        if ($this->option('json')) {
            $this->line(json_encode(['counts' => $counts, 'checks' => $checks], JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $c) {
                if ($c['status'] === 'pass' && ! $this->option('all')) {
                    continue;
                }
                $tag = ['pass' => 'PASS', 'exception' => 'EXCEPTION', 'fail' => 'FAIL'][$c['status']];
                $this->line(sprintf('[%s] %s  %s%s', $tag, $c['id'], $c['title'], $c['amount'] !== null ? '  ('.Money::format($c['amount']).')' : ''));
                if ($c['status'] !== 'pass') {
                    $this->line('    '.$c['detail']);
                    if ($c['refs'] !== []) {
                        $this->line('    refs: '.implode('; ', array_slice($c['refs'], 0, 12)).(count($c['refs']) > 12 ? ' …' : ''));
                    }
                }
            }
            $this->line("Passed {$counts['pass']}, historical exceptions {$counts['exception']}, failures {$counts['fail']}. Nothing was changed.");
        }

        return $counts['fail'] > 0 ? self::FAILURE : ($counts['exception'] > 0 ? 2 : self::SUCCESS);
    }
}
