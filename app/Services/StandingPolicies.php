<?php

namespace App\Services;

use App\Models\Resolution;
use App\Models\User;

/**
 * Standing Board authorisations, kept in the EXISTING Board resolution register (type "Other"). A policy is active
 * when the latest resolution with its exact title is approved and is not a revocation. Nothing is active by default
 * and the code invents no limits: the amount (and categories, where relevant) come from the approved resolution.
 */
class StandingPolicies
{
    public const REVOKED = 'REVOKED';

    public const EXPENSES = 'expenses';

    public const SUPPLIER_PAYMENTS = 'supplier_payments';

    public const PERIOD = 'period';

    public const EMERGENCY = 'emergency';

    public const RELEASE = 'release';

    public const COMMISSION = 'commission';

    /** key => [exact resolution title, what it authorises, what the amount means] */
    public const DEFINITIONS = [
        self::EXPENSES => ['Standing authority: automatic verification of routine expenses', 'Routine expenses in the listed categories are verified and posted automatically when within the per-expense limit, not duplicated and not capital purchases.', 'Per-expense limit; list the allowed categories (comma separated) in the notes'],
        self::SUPPLIER_PAYMENTS => ['Standing authority: supplier payments for approved sourcing lots', 'Supplier and lot-cost payments for lots with Board-approved pricing are verified automatically up to the limit. Unexpected costs always need the Board.', 'Per-payment limit'],
        self::PERIOD => ['Standing authority: automatic monthly close and profit allocation', 'Finished months with nothing unverified are closed and verified, and eligible profit or loss is allocated and posted under the signed policy (20/5/25/50, partners 40/30/30).', 'Not used'],
        self::EMERGENCY => ['Standing authority: automatic Emergency Fund coverage for sourcing losses', 'Coverage for an approved sourcing lot\'s genuine uncovered loss is approved and utilised automatically up to the limit, within the available reserve.', 'Per-request limit'],
        self::RELEASE => ['Standing authority: release unneeded Emergency Fund coverage', 'Held coverage that no partner loss allocation can claim (the period closed profitable) is returned to the Emergency Reserve automatically.', 'Not used'],
        self::COMMISSION => ['Standing authority: automatic commission invoicing', 'Commission on an order marked successful is invoiced through the client\'s billing automatically.', 'Not used'],
    ];

    public static function title(string $key): string
    {
        return self::DEFINITIONS[$key][0];
    }

    /** The approved, unrevoked resolution behind a policy, or null when the policy is not active. */
    public static function active(string $key): ?Resolution
    {
        $latest = Resolution::where('type', 'other')->where('title', self::title($key))->where('status', 'approved')->latest('id')->first();

        return $latest && $latest->notes !== self::REVOKED && ! str_starts_with((string) $latest->notes, self::REVOKED) ? $latest : null;
    }

    /** Latest resolution of any state, for display ("pending Board decision"). */
    public static function latest(string $key): ?Resolution
    {
        return Resolution::where('type', 'other')->where('title', self::title($key))->latest('id')->first();
    }

    /** @return list<string> lower-case allowed categories from the approved resolution's notes */
    public static function categories(Resolution $policy): array
    {
        return array_values(array_filter(array_map(fn ($c) => mb_strtolower(trim($c)), explode(',', (string) $policy->notes))));
    }

    /** Board revokes a standing policy: a new approved resolution that supersedes the earlier one (history is kept). */
    public static function revoke(string $key, User $board): void
    {
        $title = self::title($key);
        Resolution::create(['type' => 'other', 'title' => $title, 'status' => 'approved', 'notes' => self::REVOKED, 'requested_by' => $board->id,
            'decided_by' => $board->id, 'decided_at' => now()]);
        Audit::log('policy.revoked', $title);
    }
}
