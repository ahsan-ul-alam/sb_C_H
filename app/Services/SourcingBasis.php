<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Which accounting basis a sourcing payment or cost date falls under. Forward-only: accrual needs BOTH finance.sourcing_basis=accrual
 * and a start month, and only dates from that month on are affected. Everything earlier stays cash-basis and is never restated.
 */
class SourcingBasis
{
    /** Expense classifications that are balance-sheet assets (never an expense) until cost is recognised. */
    public const ASSET_CLASSIFICATIONS = ['supplier_advance', 'acquisition', 'transport_prepayment'];

    public static function accrualFrom(): ?string
    {
        $from = config('finance.sourcing_accrual_from');

        return $from ? CarbonImmutable::parse((string) $from)->format('Y-m') : null;
    }

    public static function accrualOn(string $date): bool
    {
        $from = self::accrualFrom();

        return config('finance.sourcing_basis') === 'accrual' && $from !== null && substr($date, 0, 7) >= $from;
    }

    public static function recognitionTrigger(): ?string
    {
        $trigger = config('finance.sourcing_recognition_trigger');

        return in_array($trigger, ['lot_completed'], true) ? $trigger : null;
    }
}
