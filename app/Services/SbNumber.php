<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Allocates permanent client numbers (SB-000001, SB-000002, ...) from a one-row counter that only ever increases. The UPDATE takes the row lock
 * and holds it to the end of the caller's transaction, so simultaneous creations are serialised and can never receive the same number. A number is
 * never derived from MAX(id) or MAX(sb_number), so deleting a client can never make its number available again.
 */
class SbNumber
{
    public const PATTERN = '/^SB-\d{6,12}$/';

    public static function allocate(): string
    {
        return DB::transaction(function (): string {
            // increment() quotes the column: "last_value" is a reserved word in MySQL 8.
            DB::table('sb_number_sequence')->where('id', 1)->increment('last_value');
            $value = (int) DB::table('sb_number_sequence')->where('id', 1)->value('last_value');

            return self::format($value);
        });
    }

    public static function format(int $value): string
    {
        return 'SB-'.str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
