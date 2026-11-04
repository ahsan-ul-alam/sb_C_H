<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ImpairedStockEvent;
use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\InventoryLossCase;
use App\Models\JournalLine;
use App\Models\Project;
use App\Models\RevenueEvent;
use App\Models\SourcingLot;
use App\Models\SupplierReturn;

/**
 * One project result, built from the same journal lines the company statements read, so the sum of the projects and the company can be reconciled instead of argued about.
 * Each journal entry is attributed to the project its source belongs to (an earned-revenue event, a handover and its returns, a loss case or impaired-stock event on a
 * handed-over return, a supplier return of a client lot, a project's own expense). Whatever no source ties to a project stays at company level and is shown as such:
 * unattributed items, and overhead, which is NEVER spread over projects without an approved allocation policy (none exists, so none is applied).
 *
 *   contribution = earned revenue (net of credit notes) - cost of goods sold - inventory losses - unrecovered supplier-return cost - write-downs - direct expenses
 *   sum of project contribution + unattributed income and other income - unattributed inventory results - unallocated overhead = company operational profit
 */
class ProjectAccounting
{
    private const ACCOUNTS = ['income', 'income.returns', 'cogs', 'inventory_loss', 'operating_inventory_loss', 'inventory_writedown', 'supplier_return_difference', 'expense'];

    private static function projectOf(string $type, int $id): ?int
    {
        return match ($type) {
            'revenue_event' => RevenueEvent::whereKey($id)->value('project_id'),
            'inventory_handover' => InventoryHandover::whereKey($id)->value('project_id'),
            'inventory_handover_return' => InventoryHandoverReturn::find($id)?->handover?->project_id,
            'inventory_loss_case' => ($h = InventoryLossCase::whereKey($id)->value('inventory_handover_id')) ? InventoryHandover::whereKey($h)->value('project_id') : null,
            'impaired_stock_event' => ($c = ImpairedStockEvent::whereKey($id)->value('inventory_loss_case_id')) && ($h = InventoryLossCase::whereKey($c)->value('inventory_handover_id')) ? InventoryHandover::whereKey($h)->value('project_id') : null,
            'supplier_return' => SourcingLot::whereKey(SupplierReturn::whereKey($id)->value('sourcing_lot_id'))->value('project_id'),
            'expense' => Expense::whereKey($id)->value('project_id'),
            default => null,
        };
    }

    /**
     * @return array{rows: list<array<string, mixed>>, bridge: array<string, int>, reconciles: bool, from: string, to: string}
     */
    public static function report(string $from, string $to): array
    {
        $by = [];
        $totals = array_fill_keys(self::ACCOUNTS, 0);
        $cache = [];
        $lines = JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->whereIn('journal_lines.account', self::ACCOUNTS)
            ->whereDate('journal_entries.entry_date', '>=', $from)->whereDate('journal_entries.entry_date', '<=', $to)
            ->get(['journal_lines.account as account', 'journal_lines.debit as debit', 'journal_lines.credit as credit', 'journal_entries.source_type as st', 'journal_entries.source_id as sid']);
        foreach ($lines as $l) {
            $net = (int) $l->debit - (int) $l->credit; // debit-positive
            $totals[$l->account] += $net;
            $key = $l->st.'#'.$l->sid;
            $cache[$key] ??= self::projectOf((string) $l->st, (int) $l->sid) ?: 0;
            if ($cache[$key] !== 0) {
                $by[$cache[$key]][$l->account] = ($by[$cache[$key]][$l->account] ?? 0) + $net;
            }
        }

        $rows = [];
        $sum = array_fill_keys(self::ACCOUNTS, 0);
        foreach (Project::with('client:id,name')->orderBy('id')->get() as $project) {
            $a = $by[$project->id] ?? [];
            if ($a === [] && ! RevenueEvent::where('project_id', $project->id)->exists() && ! InventoryHandover::where('project_id', $project->id)->exists()) {
                continue;
            }
            $g = fn (string $account) => (int) ($a[$account] ?? 0);
            foreach (self::ACCOUNTS as $account) {
                $sum[$account] += $g($account);
            }
            $revenue = -$g('income') - $g('income.returns');
            $cogs = $g('cogs');
            $losses = $g('inventory_loss') + $g('operating_inventory_loss');
            $other = $g('inventory_writedown') + $g('supplier_return_difference');
            $direct = $g('expense');
            $b = AccrualRevenue::balances($project);
            $lots = SourcingLot::where('project_id', $project->id)->where('ownership', 'company')->get();
            $custody = '0.00';
            foreach (InventoryHandover::with('line')->where('project_id', $project->id)->where('status', 'approved')->where('custody_mode', 'warehouse')->get() as $h) {
                $s = Inventory::sliceBalances($h->line, $h->id);
                $custody = bcadd($custody, bcadd(bcadd($s['available'], $s['reserved'], 2), bcadd($s['returned'], $s['damaged'], 2), 2), 2);
            }
            $rows[] = ['project_id' => $project->id, 'project' => $project->name, 'client' => $project->client?->name, 'budget' => $project->budget_minor !== null ? (int) $project->budget_minor : null,
                'arrangement' => $project->revenue_arrangement, 'cash_collected' => $b['collected'], 'customer_advances' => $b['advances'], 'receivable' => $b['receivable'], 'contract_asset' => $b['contract_asset'],
                'owned_inventory' => (int) $lots->sum(fn (SourcingLot $l) => $l->lines->sum(fn ($line) => InventoryValuation::lineValue($line))),
                'supplier_advances' => (int) $lots->sum(fn (SourcingLot $l) => AdvanceSettlementService::unsettled($l)),
                'supplier_payable' => (int) $lots->sum(fn (SourcingLot $l) => $l->lines->pluck('supplier_id')->unique()->filter()->sum(fn ($sid) => AdvanceSettlementService::payableOutstanding($l, (int) $sid))),
                'custody_units' => $custody, 'revenue' => $revenue, 'cogs' => $cogs, 'gross_profit' => $revenue - $cogs, 'inventory_losses' => $losses, 'other_inventory_results' => $other, 'direct_expenses' => $direct,
                'contribution' => $revenue - $cogs - $losses - $other - $direct];
        }

        $companyRevenue = -$totals['income'] - $totals['income.returns'];
        $attributedRevenue = -$sum['income'] - $sum['income.returns'];
        $inventoryCompany = $totals['cogs'] + $totals['inventory_loss'] + $totals['operating_inventory_loss'] + $totals['inventory_writedown'] + $totals['supplier_return_difference'];
        $inventoryAttributed = $sum['cogs'] + $sum['inventory_loss'] + $sum['operating_inventory_loss'] + $sum['inventory_writedown'] + $sum['supplier_return_difference'];
        $profit = LedgerStatements::operationalProfit($from, $to);
        $bridge = ['project_contribution' => (int) collect($rows)->sum('contribution'), 'unattributed_revenue' => $companyRevenue - $attributedRevenue, 'other_income' => $profit['other_income'],
            'unattributed_inventory_results' => -($inventoryCompany - $inventoryAttributed), 'unallocated_overhead' => -($totals['expense'] - $sum['expense']), 'company_operational_profit' => $profit['operational_profit']];
        $reconciles = $bridge['company_operational_profit'] === $bridge['project_contribution'] + $bridge['unattributed_revenue'] + $bridge['other_income'] + $bridge['unattributed_inventory_results'] + $bridge['unallocated_overhead'];

        return ['rows' => $rows, 'bridge' => $bridge, 'reconciles' => $reconciles, 'from' => $from, 'to' => $to];
    }
}
