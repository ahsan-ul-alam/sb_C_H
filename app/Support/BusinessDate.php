<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The business calendar is Asia/Dhaka, whatever the server's configured timezone is. Month cut-offs for storage billing and the
 * dates stamped on stock movements use it, so a movement made after midnight in Dhaka lands on the right day and month.
 */
class BusinessDate
{
    public const ZONE = 'Asia/Dhaka';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONE);
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    public static function currentMonth(): string
    {
        return self::now()->format('Y-m');
    }
}
