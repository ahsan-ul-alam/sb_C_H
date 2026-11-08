<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive; touches no existing row.
 *
 * stock_allocations   the physical units of a lot line that its home project (the lot's own project) has handed to ANOTHER project of the same client.
 *                     The home project is entitled to what is left. A row is never edited or deleted: cancelling sets cancelled_at (refused once the units are used).
 *                     This is a stock entitlement only; it is separate from the billing allocations of a lot and is never inferred from them.
 * project_user        which projects a staff member is assigned to. A user with NO assignment keeps today's access (nobody is locked out by this migration).
 * users.client_id     binds a client-facing user to exactly one client.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_allocations')) {
            Schema::create('stock_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
                $table->foreignId('project_id')->constrained()->restrictOnDelete();
                $table->decimal('quantity', 14, 2);
                $table->string('reason');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['sourcing_lot_line_id', 'project_id']);
            });
        }
        if (! Schema::hasTable('project_user')) {
            Schema::create('project_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'project_id']);
            });
        }
        if (! Schema::hasColumn('users', 'client_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for operational or financial data (restore the verified backup instead).
    }
};
