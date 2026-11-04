<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landed cost provenance and approved reallocation (Phase 2C-0). Additive only.
 *  - inventory_value_movements.sourcing_lot_cost_id: which lot cost a landed movement belongs to, so what a cost has put on each line is always known.
 *  - landed_cost_reallocations: a shared cost is never redistributed because a new line appeared. It moves between lines only through an approved request that
 *    names the beneficiary lines and the documented cost policy; the compensating movements are appended and net to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_value_movements', 'sourcing_lot_cost_id')) {
            Schema::table('inventory_value_movements', function (Blueprint $table) {
                $table->unsignedBigInteger('sourcing_lot_cost_id')->nullable()->index();
            });
        }
        if (! Schema::hasTable('landed_cost_reallocations')) {
            Schema::create('landed_cost_reallocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sourcing_lot_cost_id')->constrained()->restrictOnDelete();
                $table->string('policy_reference'); // the documented cost policy or Board resolution that says who benefits
                $table->string('basis', 8)->default('value'); // value | quantity
                $table->json('beneficiary_line_ids');
                $table->string('status', 12)->default('pending'); // pending | approved | rejected
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
