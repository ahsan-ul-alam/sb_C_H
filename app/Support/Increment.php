<?php

namespace App\Support;

/**
 * Theoretical employee increment, in basis points (1000 = 10%).
 *
 * rate = approved available annual net profit / original total investment
 * rate < 80%          -> 0 (no automatic positive increment)
 * 80% <= rate <= 100% -> 10% + (rate - 80) / 20 * 10%
 * rate > 100%         -> 20% + (rate - 100) / 100 * 5%
 */
class Increment
{
    /**
     * Profit-to-investment rate in basis points (10000 = 100%), rounded half up.
     */
    public static function rateBps(int $annualProfit, int $investment): int
    {
        if ($investment <= 0 || $annualProfit <= 0) {
            return 0;
        }

        return (int) bcadd(bcdiv(bcmul((string) $annualProfit, '10000', 0), (string) $investment, 6), '0.5', 0);
    }

    public static function theoreticalBps(int $rateBps): int
    {
        if ($rateBps < 8000) {
            return 0;
        }
        // Percent points: (rate-80)/2 and (rate-100)/20 expressed in bps are (rateBps-8000)/2 and (rateBps-10000)/20.
        $extra = $rateBps <= 10000
            ? bcdiv((string) ($rateBps - 8000), '2', 6)
            : bcdiv((string) ($rateBps - 10000), '20', 6);
        $base = $rateBps <= 10000 ? 1000 : 2000;

        // The rule is defined up to a 300% rate (30%); above that nothing is extrapolated.
        return min(3000, (int) bcadd(bcadd((string) $base, $extra, 6), '0.5', 0));
    }

    /**
     * Salary after applying an increment of $bps to $salary (minor units, half-up).
     */
    public static function apply(int $salary, int $bps): int
    {
        return $salary + (int) bcadd(bcdiv(bcmul((string) $salary, (string) $bps, 0), '10000', 6), '0.5', 0);
    }
}
