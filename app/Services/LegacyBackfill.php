<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * One-time data shaping for records that predate payment tracking. Every statement matches only rows still in the
 * pre-migration shape, so running it again (a repeated migration or a manual re-run) changes nothing.
 */
class LegacyBackfill
{
    /**
     * Expenses recorded before payment tracking were all paid on their spending date. Returns the rows it changed.
     */
    public static function expenses(): int
    {
        $paid = DB::table('expenses')->where('payment_status', 'paid')->where('paid_amount', 0)->whereNull('paid_on')
            ->update(['paid_amount' => DB::raw('amount'), 'paid_on' => DB::raw('spent_on')]);
        $assets = DB::table('expenses')->where('is_asset', true)->where('classification', 'operating')->update(['classification' => 'asset']);

        return $paid + $assets;
    }
}
