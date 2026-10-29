<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use App\Models\Partner;
use App\Models\PolicyVersion;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Create the admin account from ADMIN_EMAIL / ADMIN_PASSWORD in .env, the three founders and the
     * (unsigned) three-partner policy draft. Safe to re-run.
     */
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (! $email || ! $password) {
            throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env before seeding.');
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            ['name' => config('admin.name'), 'password' => $password],
        );
        $admin->addRole('super_admin'); // adds only; any other roles the admin already holds are kept

        BusinessSetting::current();

        foreach (['A', 'B', 'C'] as $i => $code) {
            Partner::firstOrCreate(['code' => $code], ['name' => 'Partner '.$code, 'sort' => $i + 1]);
        }

        // Draft only: no approval date or signature is invented. Live posting stays blocked until the Board signs it.
        PolicyVersion::firstOrCreate(['name' => '3-Partner Policy'], [
            'effective_from' => today(),
            'investment_bps' => ['A' => 4000, 'B' => 3000, 'C' => 3000],
            'ownership_shares' => ['A' => 1, 'B' => 1, 'C' => 1],
            'loss_shares' => ['A' => 1, 'B' => 1, 'C' => 1],
            'profit_bps' => ['A' => 4000, 'B' => 3000, 'C' => 3000],
        ]);
    }
}
