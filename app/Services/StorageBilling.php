<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\SourcingLot;
use App\Models\StorageCharge;
use App\Support\BusinessDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monthly warehouse storage charge for a client's lot: occupied square feet × the monthly rate, while MORE than the threshold of units is
 * on hand at the month-end cut-off (Asia/Dhaka). No proration, and no invented footprint: the area is a measurement a person enters, and
 * a lot without one is reported as "needs measurement" instead of being charged. Each lot and month is billed once; the quantity, area
 * and rate are snapshotted. The charge is a separate ClientHub service invoice — never a sourcing cost.
 *
 * It is a fee the Board has not necessarily agreed, so nothing is charged until the standing policy for storage charges is approved
 * AND a rate above zero is configured.
 */
class StorageBilling
{
    public static function rateFor(Client $client): int
    {
        return (int) ($client->storage_rate_minor ?? BusinessSetting::current()->storage_rate_minor);
    }

    /** Units physically on hand in the lot at the end of the given day. */
    public static function onHand(SourcingLot $lot, string $upTo): string
    {
        $sum = '0';
        foreach ($lot->lines()->get() as $line) {
            $sum = bcadd($sum, Inventory::onHand($line, $upTo), 2);
        }

        return $sum;
    }

    /**
     * Bill (or re-check) one finished month.
     *
     * @return array{billed: list<string>, needs_measurement: list<string>, skipped: int}
     */
    public static function runMonth(string $month): array
    {
        if (! StandingPolicies::active(StandingPolicies::STORAGE)) {
            throw new RuntimeException('Warehouse storage charging is not authorised: the Board has not approved the standing policy for storage charges.');
        }
        if ($month >= BusinessDate::currentMonth()) {
            throw new RuntimeException('Only a finished month can be billed.');
        }
        $cutoff = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->endOfMonth()->toDateString();
        $threshold = (string) BusinessSetting::current()->storage_threshold;
        $out = ['billed' => [], 'needs_measurement' => [], 'skipped' => 0];

        SourcingLot::where('ownership', 'client')->whereNotNull('project_id')->where('status', '!=', 'cancelled')->with('project.client')->orderBy('id')->get()->each(function (SourcingLot $lot) use ($month, $cutoff, $threshold, &$out) {
            $client = $lot->project->client;
            $qty = self::onHand($lot, $cutoff);
            $rate = self::rateFor($client);
            $existing = StorageCharge::where('sourcing_lot_id', $lot->id)->where('period', $month)->first();
            if (bccomp($qty, $threshold, 2) <= 0 || $rate <= 0 || ($existing && $existing->status === 'billed')) {
                $out['skipped']++;

                return;
            }
            $measured = $lot->storage_sqft !== null && (float) $lot->storage_sqft > 0; // the latest area a person measured; never invented, never scaled down as units sell
            DB::transaction(function () use ($lot, $client, $month, $qty, $rate, $measured, $existing, &$out) {
                if (! $measured) {
                    StorageCharge::firstOrCreate(['sourcing_lot_id' => $lot->id, 'period' => $month], ['client_id' => $client->id, 'quantity' => $qty, 'rate' => $rate, 'status' => 'needs_measurement']);
                    $out['needs_measurement'][] = $lot->number;

                    return;
                }
                $sqft = (string) $lot->storage_sqft;
                $amount = Money::multiply($sqft, $rate);
                if ($amount <= 0) {
                    return;
                }
                [$invoice] = ClientBilling::addCharge($lot->project, 'Warehouse storage', 'Monthly warehouse storage', null, null, 'Warehouse storage',
                    'Warehouse storage — '.$lot->number.' — '.$month.' ('.$qty.' units on hand)', $sqft, 'sq ft', $rate, $amount);
                ($existing ?? new StorageCharge(['sourcing_lot_id' => $lot->id, 'period' => $month]))->forceFill([
                    'client_id' => $client->id, 'quantity' => $qty, 'sqft' => $sqft, 'rate' => $rate, 'amount' => $amount, 'status' => 'billed', 'invoice_id' => $invoice->id,
                ])->save();
                Audit::log('storage.billed', 'lot '.$lot->number, "$month $sqft sq ft x $rate = $amount");
                $out['billed'][] = $lot->number;
            });
        });

        return $out;
    }

    /** The scheduled run: the month that just finished, if the policy allows. Safe to repeat. */
    public static function runDue(): ?array
    {
        if (! StandingPolicies::active(StandingPolicies::STORAGE)) {
            return null;
        }

        return self::runMonth(BusinessDate::now()->subMonthNoOverflow()->format('Y-m'));
    }
}
