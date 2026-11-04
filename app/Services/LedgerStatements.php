<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\ImpairedStockEvent;
use App\Models\InventoryLossCase;
use App\Models\InventoryRevaluation;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SourcingLot;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Statements read straight from the immutable ledger, one place for each formula, so that a report can always be traced to journal entries and agrees with every other
 * report that reads the same accounts. These do NOT replace the existing monthly profit and distributable-profit calculations (which stay as they are, so frozen
 * allocations and closed periods are untouched); they are the ledger view next to them, with the bridge between the two stated explicitly.
 *
 *   trialBalance        every account's debits and credits up to a date, and whether the ledger balances
 *   operationalProfit   what the ledger says the period earned and cost, with the inventory results (cost of goods sold, abnormal losses, write-downs, unrecovered
 *                       supplier-return cost) shown as their own lines and NOT folded into "expense", plus the bridge to the profit the allocation policy reads
 *   cashFlow            the movement of ledger cash by what caused it (operating, investing, financing), opening to closing, agreeing with the ledger's cash balance
 *   supplierAging       what is still owed to suppliers for accepted goods, by age
 *   receivableAging     what customers still owe on open invoices, by days past due
 *   exceptions          every reconciliation that disagrees, and every decision waiting, in one list
 */
class LedgerStatements
{
    /** Accounts whose debit balance is a result of inventory events; none is read by the profit that partner allocation uses. */
    public const INVENTORY_RESULT_ACCOUNTS = ['cogs' => 'Cost of goods sold (handed over to clients)', 'inventory_loss' => 'Inventory loss expense (abnormal losses)', 'inventory_writedown' => 'Inventory write-downs to net realisable value',
        'supplier_return_difference' => 'Cost of returned goods not recovered from suppliers'];

    /**
     * @return array{rows: list<array{account: string, debit: int, credit: int, balance: int}>, total_debit: int, total_credit: int, balanced: bool, as_of: string}
     */
    public static function trialBalance(?string $asOf = null): array
    {
        $asOf ??= today()->toDateString();
        $rows = JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->selectRaw('journal_lines.account as account, COALESCE(SUM(journal_lines.debit),0) as d, COALESCE(SUM(journal_lines.credit),0) as c')->groupBy('journal_lines.account')->orderBy('journal_lines.account')->get()
            ->map(fn ($r) => ['account' => (string) $r->account, 'debit' => (int) $r->d, 'credit' => (int) $r->c, 'balance' => (int) $r->d - (int) $r->c])->all();
        $d = (int) collect($rows)->sum('debit');
        $c = (int) collect($rows)->sum('credit');

        return ['rows' => $rows, 'total_debit' => $d, 'total_credit' => $c, 'balanced' => $d === $c, 'as_of' => $asOf];
    }

    private static function net(string $account, string $from, string $to): int
    {
        return (int) JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', $account)
            ->whereDate('journal_entries.entry_date', '>=', $from)->whereDate('journal_entries.entry_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as n')->value('n');
    }

    /**
     * @return array{from: string, to: string, income: int, other_income: int, expense: int, ledger_profit: int, inventory: array<string, int>, inventory_total: int, operational_profit: int, basis: string}
     */
    public static function operationalProfit(string $from, string $to): array
    {
        $income = -self::net('income', $from, $to);
        $other = -self::net('income.other', $from, $to);
        $expense = self::net('expense', $from, $to);
        $inventory = [];
        foreach (self::INVENTORY_RESULT_ACCOUNTS as $account => $label) {
            $inventory[$account] = self::net($account, $from, $to);
        }
        $inventoryTotal = array_sum($inventory);

        return ['from' => $from, 'to' => $to, 'income' => $income, 'other_income' => $other, 'expense' => $expense, 'ledger_profit' => $income + $other - $expense, 'inventory' => $inventory, 'inventory_total' => $inventoryTotal,
            'operational_profit' => $income + $other - $expense - $inventoryTotal,
            'basis' => 'Ledger journal entries dated in the period. The profit that partner allocation reads is calculated separately and is unchanged; the difference is the inventory results above, which that calculation does not include.'];
    }

    /**
     * Ledger cash by cause. Transfers between the company's own cash accounts are not cash flow. An entry is financing when it touches capital, loans or partner money,
     * investing when it touches fixed assets or loans receivable, otherwise operating.
     *
     * @return array{from: string, to: string, opening: int, closing: int, ledger_closing: int, agrees: bool, sections: array<string, array{in: int, out: int, net: int, by_account: array<string, int>}>}
     */
    public static function cashFlow(string $from, string $to): array
    {
        $financing = ['partner.capital', 'equity.opening', 'loans.payable', 'partner.payable', 'fund.salary', 'fund.office', 'fund.emergency', 'fund.coverage'];
        $investing = ['assets.equipment', 'assets.loans_receivable'];
        $opening = (int) JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', 'cash')->whereDate('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as n')->value('n');
        $sections = collect(['operating', 'investing', 'financing'])->mapWithKeys(fn ($s) => [$s => ['in' => 0, 'out' => 0, 'net' => 0, 'by_account' => []]])->all();

        JournalEntry::query()->with('lines')->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)->whereHas('lines', fn ($q) => $q->where('account', 'cash'))->orderBy('id')
            ->chunkById(500, function (Collection $entries) use (&$sections, $financing, $investing) {
                foreach ($entries as $entry) {
                    $cash = (int) $entry->lines->where('account', 'cash')->sum('debit') - (int) $entry->lines->where('account', 'cash')->sum('credit');
                    $others = $entry->lines->where('account', '!=', 'cash');
                    if ($others->isEmpty() || $cash === 0) {
                        continue; // a transfer between the company's own cash accounts
                    }
                    $accounts = $others->pluck('account');
                    $section = $accounts->contains(fn ($a) => in_array($a, $financing, true)) ? 'financing' : ($accounts->contains(fn ($a) => in_array($a, $investing, true)) ? 'investing' : 'operating');
                    $main = (string) $others->sortByDesc(fn ($l) => max((int) $l->debit, (int) $l->credit))->first()->account;
                    $sections[$section][$cash > 0 ? 'in' : 'out'] += abs($cash);
                    $sections[$section]['net'] += $cash;
                    $sections[$section]['by_account'][$main] = ($sections[$section]['by_account'][$main] ?? 0) + $cash;
                }
            });
        $net = (int) collect($sections)->sum('net');
        $ledgerClosing = (int) JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', 'cash')->whereDate('journal_entries.entry_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as n')->value('n');

        return ['from' => $from, 'to' => $to, 'opening' => $opening, 'closing' => $opening + $net, 'ledger_closing' => $ledgerClosing, 'agrees' => $opening + $net === $ledgerClosing, 'sections' => $sections];
    }

    /**
     * What is still owed to suppliers for accepted goods, by age. A payable is paid oldest first, so what remains belongs to the newest settlements; each part is aged from the
     * date that settlement recognised it. Per lot and supplier, never netted across them.
     *
     * @return array{rows: list<array<string, mixed>>, buckets: array<string, int>, total: int, as_of: string}
     */
    public static function supplierAging(?string $asOf = null): array
    {
        $asOf ??= today()->toDateString();
        $rows = [];
        $buckets = ['current' => 0, 'd31_60' => 0, 'd61_90' => 0, 'over_90' => 0];
        $pairs = AdvanceSettlement::where('kind', 'payable')->where('status', 'verified')->select('sourcing_lot_id', 'supplier_id')->distinct()->get();
        foreach ($pairs as $pair) {
            $lot = SourcingLot::find($pair->sourcing_lot_id);
            $left = AdvanceSettlementService::payableOutstanding($lot, (int) $pair->supplier_id);
            if ($left <= 0) {
                continue;
            }
            $row = ['lot' => $lot->number, 'lot_id' => $lot->id, 'supplier_id' => (int) $pair->supplier_id, 'outstanding' => $left, 'current' => 0, 'd31_60' => 0, 'd61_90' => 0, 'over_90' => 0];
            foreach (AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('supplier_id', $pair->supplier_id)->where('kind', 'payable')->where('status', 'verified')->orderByDesc('settled_on')->orderByDesc('id')->get() as $s) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, (int) $s->amount);
                $age = (int) $s->settled_on->diffInDays($asOf, false);
                $bucket = $age <= 30 ? 'current' : ($age <= 60 ? 'd31_60' : ($age <= 90 ? 'd61_90' : 'over_90'));
                $row[$bucket] += $take;
                $buckets[$bucket] += $take;
                $left -= $take;
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'buckets' => $buckets, 'total' => array_sum($buckets), 'as_of' => $asOf];
    }

    /**
     * Open customer invoices by days past their due date (an invoice with no due date is aged from its issue date).
     *
     * @return array{rows: list<array<string, mixed>>, buckets: array<string, int>, total: int, as_of: string}
     */
    public static function receivableAging(?string $asOf = null): array
    {
        $asOf ??= today()->toDateString();
        $buckets = ['not_due' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'over_90' => 0];
        $rows = [];
        foreach (Invoice::query()->with('client')->get() as $invoice) {
            $open = (int) $invoice->total - (int) $invoice->amount_paid;
            if ($open <= 0) {
                continue;
            }
            $due = ($invoice->due_on ?? $invoice->issued_on)?->toDateString() ?? $asOf;
            $late = (int) Carbon::parse($due)->diffInDays($asOf, false);
            $bucket = $late <= 0 ? 'not_due' : ($late <= 30 ? 'd1_30' : ($late <= 60 ? 'd31_60' : ($late <= 90 ? 'd61_90' : 'over_90')));
            $buckets[$bucket] += $open;
            $rows[] = ['invoice' => $invoice->number, 'invoice_id' => $invoice->id, 'client' => $invoice->client?->name, 'due_on' => $due, 'days_past_due' => max(0, $late), 'outstanding' => $open, 'bucket' => $bucket];
        }

        return ['rows' => $rows, 'buckets' => $buckets, 'total' => array_sum($buckets), 'as_of' => $asOf];
    }

    /**
     * Every reconciliation that disagrees and every decision waiting. An empty list is the claim "nothing is outstanding"; each line says where to look.
     *
     * @return list<array{area: string, message: string}>
     */
    public static function exceptions(): array
    {
        $out = [];
        $push = function (string $area, array $messages) use (&$out) {
            foreach ($messages as $m) {
                $out[] = ['area' => $area, 'message' => $m];
            }
        };
        $tb = self::trialBalance();
        if (! $tb['balanced']) {
            $push('Ledger', ['The ledger does not balance: debits '.$tb['total_debit'].' and credits '.$tb['total_credit'].'.']);
        }
        $inv = InventoryReconciliation::company();
        $push('Inventory', collect($inv['lots'])->flatMap(fn ($l) => $l['exceptions'])->all());
        if ($inv['unattributed'] !== 0) {
            $push('Inventory', ['The inventory asset in the ledger differs from what the lots explain by '.$inv['unattributed'].'.']);
        }
        $push('Handovers', $inv['handovers']['problems']);
        $push('Losses and write-downs', $inv['losses']['problems']);
        $push('Supplier returns', $inv['supplier_returns']['problems']);
        foreach (InventoryReconciliation::quantities() as $q) {
            $push('Stock quantities', array_map(fn ($d) => $q['lot'].' '.$q['description'].': '.$d, $q['differences']));
        }
        $waiting = [['Loss cases awaiting a decision', InventoryLossCase::whereIn('status', InventoryLossCase::ACTIVE)->count()], ['Impaired-stock events awaiting the Board', ImpairedStockEvent::where('status', 'pending')->count()],
            ['Revaluations awaiting a decision', InventoryRevaluation::whereIn('status', ['pending_review', 'reviewed'])->count()], ['Supplier returns awaiting a decision', SupplierReturn::whereIn('status', SupplierReturn::ACTIVE)->count()],
            ['Supplier settlements awaiting the Board', SupplierReturnSettlement::where('status', 'pending')->count()]];
        foreach ($waiting as [$label, $n]) {
            if ($n > 0) {
                $push('Approvals', [$label.': '.$n]);
            }
        }
        $flagged = InventoryLossCase::where('prior_period', true)->count() + SupplierReturn::where('prior_period', true)->count();
        if ($flagged > 0) {
            $push('Closed periods', [$flagged.' event(s) concern a closed period and were recorded in the open period: Finance and the Board should decide whether a prior-period treatment is needed.']);
        }
        if ($inv['supplier_returns']['unregistered_historical_corrections'] > 0) {
            $push('Supplier recovery', [$inv['supplier_returns']['unregistered_historical_corrections'].' refund or credit-note correction(s) pre-date the recovery document register and cannot be checked against it.']);
        }
        $unclassified = SourcingLot::whereNull('ownership')->whereHas('lines', fn ($l) => $l->whereNotNull('received_quantity'))->count();
        if ($unclassified > 0) {
            $push('Ownership', [$unclassified.' lot(s) with received goods have no stated owner: they are outside inventory valuation and custody.']);
        }

        return $out;
    }
}
