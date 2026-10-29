<?php

namespace App\Support;

/**
 * Exact integer splitting by the largest remainder method. Ties go to the earlier key, so the result is
 * deterministic and always sums to the original amount.
 */
class Split
{
    /**
     * @param  array<string, int>  $weights  non-negative integer weights
     * @return array<string, int>
     */
    public static function apply(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $out = array_fill_keys(array_keys($weights), 0);
        if ($total <= 0 || $amount === 0) {
            return $out;
        }

        $remainders = [];
        $assigned = 0;
        foreach ($weights as $key => $weight) {
            $product = bcmul((string) $amount, (string) $weight, 0);
            $out[$key] = (int) bcdiv($product, (string) $total, 0);
            $remainders[$key] = (int) bcmod($product, (string) $total);
            $assigned += $out[$key];
        }

        $position = array_flip(array_keys($weights));
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => [$remainders[$b], $position[$a]] <=> [$remainders[$a], $position[$b]]);
        for ($i = 0; $i < $amount - $assigned; $i++) {
            $out[$order[$i]]++;
        }

        return $out;
    }
}
