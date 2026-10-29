<?php

namespace App\Support;

/**
 * Role -> permission map. A user's effective permissions are the UNION of all roles they hold.
 *
 * SUPER_ADMIN has every operational permission (users, ClientHub, preparing/recording/posting finance) but NOT the
 * GOVERNANCE approvals below: those need the Board Member role (held by the same person if they are also on the
 * Board). Conflict-of-interest rules (own payout, own salary, creator = approver) are checked per user in the
 * controllers/services, so holding several roles never lets one person approve their own work.
 */
class Permissions
{
    public const ROLES = [
        'super_admin' => 'Super Admin',
        'board_member' => 'Board Member',
        'finance' => 'Finance',
        'partner' => 'Partner',
        'hr' => 'HR',
        'client_management' => 'Client Management',
        'employee' => 'Employee',
        'auditor' => 'Auditor',
    ];

    /** Approvals that represent a Board decision. */
    public const GOVERNANCE = [
        'policy.approve', 'allocation.approve', 'payout.approve', 'resolution.decide', 'period.verify',
        'exit.approve', 'capital.verify', 'expense.verify', 'opening.approve',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        $view = ['finance.view'];
        $roles = [
            'board_member' => [...$view, 'policy.create', 'policy.approve', 'allocation.approve', 'payout.approve',
                'resolution.create', 'resolution.decide', 'period.verify', 'exit.approve', 'partner.manage', 'capital.verify', 'expense.verify', 'opening.approve', 'document.upload'],
            'finance' => ['clienthub', ...$view, 'capital.record', 'expense.record', 'period.close', 'allocation.prepare',
                'allocation.post', 'payout.pay', 'resolution.pay', 'exit.prepare', 'exit.pay', 'policy.create', 'revenue.record', 'opening.prepare', 'document.upload'],
            'hr' => ['resolution.create'],
            'partner' => ['own.view', 'payout.request', 'resolution.create'],
            'client_management' => ['clienthub'],
            'employee' => [],
            'auditor' => $view,
        ];

        $everything = array_values(array_unique([...array_merge(...array_values($roles)), 'clienthub', 'users.manage', ...$view]));
        $roles['super_admin'] = array_values(array_diff($everything, self::GOVERNANCE));

        return $roles;
    }

    /**
     * @param  list<string>  $roles
     */
    public static function allowsAny(array $roles, string $permission): bool
    {
        $map = self::map();
        foreach ($roles as $role) {
            if (in_array($permission, $map[$role] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
