<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\InventoryHandover;
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

    /** A storage contract is recorded and had started by the given day. */
    public static function contractInForce(Client $client, string $on): bool
    {
        return trim((string) $client->storage_contract_reference) !== '' && ($client->storage_contract_from === null || substr((string) $client->storage_contract_from, 0, 10) <= $on);
    }

    /**
     * Every client's stock that cannot be charged for storage yet, and exactly what is missing: the entitlement (a storage contract in force), measurable usage (an area a
     * person measured) and an approved rate and policy. Verified custody stock (goods Sellbees handed over and still holds in its warehouse for the client) is listed with
     * the reason it is not billed: no process measures or bills it, so it is neither charged nor hidden. Nothing here charges anything.
     *
     * @return list<array{client_id: int, client: string, subject: string, units: string, missing: list<string>}>
     */
    public static function configurationExceptions(): array
    {
        $today = today()->toDateString();
        $policy = StandingPolicies::active(StandingPolicies::STORAGE);
        $rows = [];
        $missingCommon = function (Client $client) use ($policy, $today): array {
            $m = [];
            if (! self::contractInForce($client, $today)) {
                $m[] = 'no storage contract in force is recorded for this client';
            }
            if (self::rateFor($client) <= 0) {
                $m[] = 'no approved storage rate';
            }
            if (! $policy) {
                $m[] = 'the storage charging policy is not approved';
            }

            return $m;
        };
        foreach (SourcingLot::where('ownership', 'client')->whereNotNull('project_id')->where('status', '!=', 'cancelled')->with('project.client')->orderBy('id')->get() as $lot) {
            $units = self::onHand($lot, $today);
            if (bccomp($units, '0', 2) <= 0) {
                continue;
            }
            $missing = $missingCommon($lot->project->client);
            if ($lot->storage_sqft === null || (float) $lot->storage_sqft <= 0) {
                $missing[] = 'no measured storage area';
            }
            if ($missing !== []) {
                $rows[] = ['client_id' => $lot->project->client->id, 'client' => $lot->project->client->name, 'subject' => 'Lot '.$lot->number, 'units' => $units, 'missing' => $missing];
            }
        }
        foreach (InventoryHandover::with(['client', 'line'])->where('status', 'approved')->where('custody_mode', 'warehouse')->orderBy('id')->get() as $h) {
            $units = Inventory::sliceBalances($h->line, $h->id);
            $held = bcadd(bcadd($units['available'], $units['reserved'], 2), bcadd($units['returned'], $units['damaged'], 2), 2);
            if (bccomp($held, '0', 2) > 0) {
                $rows[] = ['client_id' => $h->client->id, 'client' => $h->client->name, 'subject' => 'Custody stock, handover #'.$h->id.' ('.$h->line->description.')', 'units' => $held,
                    'missing' => [...$missingCommon($h->client), 'no measured storage area for custody stock', 'custody stock is not billed by any process yet (it must not also be charged through a client-owned lot)']];
            }
        }

        return $rows;
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
            if (config('finance.storage_contract_required') && ! self::contractInForce($client, $cutoff)) {
                $out['skipped']++; // no contract entitlement: nothing is charged, and the stock is listed under Storage Billing Configuration Required

                return;
            }
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
