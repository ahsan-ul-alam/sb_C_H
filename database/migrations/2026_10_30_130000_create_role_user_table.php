<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive: users may hold several roles. Existing single roles are copied across; users.role is left in place
     * (unused) so nothing is lost and the change can be reverted by simply dropping this table.
     */
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24);
            $table->timestamps();
            $table->unique(['user_id', 'role']);
        });

        foreach (DB::table('users')->whereNotNull('role')->get(['id', 'role']) as $user) {
            DB::table('role_user')->insert(['user_id' => $user->id, 'role' => $user->role, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
