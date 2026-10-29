<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Create the admin account from ADMIN_EMAIL / ADMIN_PASSWORD in .env. Safe to re-run.
     */
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (! $email || ! $password) {
            throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env before seeding.');
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => config('admin.name'), 'password' => $password],
        );

        BusinessSetting::current();
    }
}
