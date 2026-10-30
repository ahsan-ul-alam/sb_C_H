<?php

use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;

if (! function_exists('dr')) {
    /** Debit-minus-credit of a ledger account straight from the journal rows (independent of the app's own balance helpers). */
    function dr(string $account, ?int $partnerId = null): int
    {
        $q = JournalLine::where('account', $account)->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId));

        return (int) $q->sum('debit') - (int) $q->sum('credit');
    }
}

if (! function_exists('largestRemainder')) {
    /**
     * Independent implementation of the largest-remainder split used to check the app's shared-cost allocation.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    function largestRemainder(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $floor = [];
        $rem = [];
        foreach ($weights as $k => $w) {
            $floor[$k] = intdiv($amount * $w, $total);
            $rem[$k] = ($amount * $w) % $total;
        }
        $left = $amount - array_sum($floor);
        $keys = array_keys($weights);
        usort($keys, fn ($a, $b) => [$rem[$b], $a] <=> [$rem[$a], $b]);
        foreach (array_slice($keys, 0, $left) as $k) {
            $floor[$k]++;
        }

        return $floor;
    }
}

if (! function_exists('ledgerAudit')) {
    /**
     * Ledger-wide integrity audit used at the end of the simulations: every entry balances, no source is posted twice, and every
     * entry points at a record that really exists. Entries with source type "audit" are the scenarios' declared opening reserve.
     *
     * @return array{entries: int, unbalanced: int, duplicates: int, orphans: list<string>}
     */
    function ledgerAudit(): array
    {
        $tables = ['expense' => 'expenses', 'capital' => 'capital_entries', 'period' => 'accounting_periods', 'allocation' => 'allocations', 'payout' => 'payouts',
            'sourcing_fund' => 'emergency_fund_requests', 'sourcing_fund_reversal' => 'emergency_fund_requests', 'resolution' => 'resolutions', 'exit' => 'exit_settlements',
            'opening' => 'opening_batches', 'revenue' => 'revenue_adjustments'];
        $entries = JournalEntry::all();
        $orphans = [];
        foreach ($entries as $e) {
            if ($e->source_type === 'audit' || $e->source_type === 'test') {
                continue;
            }
            $table = $tables[$e->source_type] ?? null;
            if (! $table || ! DB::table($table)->where('id', $e->source_id)->exists()) {
                $orphans[] = $e->source_type.'#'.$e->source_id;
            }
        }

        return [
            'entries' => $entries->count(),
            'unbalanced' => $entries->filter(fn ($e) => $e->lines()->sum('debit') !== $e->lines()->sum('credit') || $e->lines()->sum('debit') <= 0)->count(),
            'duplicates' => JournalEntry::selectRaw('source_type, source_id, COUNT(*) n')->groupBy('source_type', 'source_id')->get()->where('n', '>', 1)->count(),
            'orphans' => $orphans,
        ];
    }
}
