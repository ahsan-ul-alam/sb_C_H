<?php

namespace App\Support;

/**
 * Money is stored as integer minor units (e.g. paisa, cents) so sums are exact.
 * Conversions use string arithmetic; floats are never used for stored values.
 */
class Money
{
    /**
     * Validation regex for user-entered amounts: up to 2 decimal places, no sign.
     */
    public const AMOUNT_REGEX = '/^\d{1,13}(\.\d{1,2})?$/';

    /**
     * Convert a validated decimal string ("4000", "4000.5", "4000.50") to minor units.
     */
    public static function toMinor(string|int|null $value): int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $value = str_replace(',', '', $value);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * Convert minor units to a plain decimal string for form inputs ("4000.00").
     */
    public static function toDecimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format minor units for display, optionally prefixed with a currency code.
     */
    public static function format(int $minor, ?string $currency = null): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        $formatted = number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);

        return trim(($currency ? $currency.' ' : '').$sign.$formatted);
    }

    /**
     * Multiply a decimal quantity by a minor-unit price, rounding half up to the nearest minor unit.
     */
    public static function multiply(string $quantity, int $unitPrice): int
    {
        return (int) bcadd(bcmul($quantity, (string) $unitPrice, 4), '0.5', 0);
    }
}
