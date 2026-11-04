<?php

namespace App\Console\Commands;

use App\Services\InventoryReconciliation;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:reconcile')]
#[Description('Read-only reconciliation of the inventory value subledger to the general ledger, lot by lot and company-wide. Writes nothing.')]
class ReconcileInventory extends Command
{
    public function handle(): int
    {
        $report = InventoryReconciliation::company();

        $this->table(['Lot', 'Ownership', 'Engine', 'Ledger value', 'Subledger', 'Difference', 'Unvalued accepted', 'Note'], collect($report['lots'])->map(fn ($r) => [
            $r['lot'], $r['ownership'] ?? '—', $r['valued'] ? 'valued' : 'excluded', Money::format($r['journal_value']),
            $r['subledger_value'] === null ? '—' : Money::format($r['subledger_value']), $r['difference'] === null ? '—' : Money::format($r['difference']),
            $r['valued'] ? Money::format($r['unvalued_accepted']) : '—', $r['exclusion'] ?? implode(' | ', $r['exceptions']),
        ])->all());

        $this->line('Inventory asset in the ledger:     '.Money::format($report['gl_inventory']));
        $this->line('Explained by the lots\' own entries: '.Money::format($report['journal_by_lot']).'  (unattributed: '.Money::format($report['unattributed']).')');
        $this->line('Engine lots, ledger / subledger:    '.Money::format($report['engine_journal']).' / '.Money::format($report['engine_subledger']).'  (accepted goods not valued yet: '.Money::format($report['engine_unvalued']).')');
        $this->line('Duplicate movements: '.$report['duplicates'].'   Lines with a negative value: '.$report['negative_lines']);
        $this->{$report['ok'] ? 'info' : 'warn'}($report['ok'] ? 'Reconciled.' : 'Differences found (see above).');

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
