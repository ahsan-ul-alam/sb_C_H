<?php

namespace App\Support;

/**
 * Decimal-safe sourcing arithmetic (bcmath on integer minor units; never floats).
 * Unit costs are strings with 4 decimals; prices are rounded half-up to whole minor units.
 */
class Costing
{
    /** Landed cost per costed unit, 4 decimals. */
    public static function unitCost(int $landed, string $quantity): string
    {
        return bccomp($quantity, '0', 2) > 0 ? bcdiv((string) $landed, $quantity, 4) : '0.0000';
    }

    /** Lowest whole-minor-unit price that does not lose money (rounds the unit cost UP). */
    public static function breakEven(int $landed, string $quantity): int
    {
        if (bccomp($quantity, '0', 2) <= 0) {
            return 0;
        }
        $exact = bcdiv((string) $landed, $quantity, 6);
        $floor = bcdiv($exact, '1', 0);

        return (int) (bccomp($exact, $floor, 6) > 0 ? bcadd($floor, '1', 0) : $floor);
    }

    /**
     * Client unit price from a unit cost. fixed: cost + profit per unit (minor units).
     * markup: cost x (1 + bps/10000) — markup ON COST, not margin on selling price.
     */
    public static function price(string $unitCost, string $method, int $value): int
    {
        $raw = $method === 'markup'
            ? bcdiv(bcmul($unitCost, (string) (10000 + $value), 4), '10000', 4)
            : bcadd($unitCost, (string) $value, 4);

        return (int) bcadd($raw, '0.5', 0);
    }

    /**
     * Split a shared cost across lines with the largest-remainder method so the parts always sum to the whole.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, int>
     */
    public static function allocateShared(int $amount, array $weights): array
    {
        if (array_sum($weights) <= 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
        }

        return $weights === [] ? [] : Split::apply($amount, array_map('intval', $weights));
    }

    /** Ratio as a percentage string with 2 decimals, or null when the base is zero. */
    public static function percent(int $part, int $base): ?string
    {
        return $base > 0 ? bcdiv(bcmul((string) $part, '100', 6), (string) $base, 2) : null;
    }
}
