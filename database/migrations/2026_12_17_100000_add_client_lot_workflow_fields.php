<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The simple client-lot workflow (Processing → In order → Completed): the client lot budget agreed for the whole lot, and who moved the lot
 * into order and completed it, and when. Additive and re-runnable; every column is NULL for existing rows, so historical lots keep exactly
 * the figures they had (a NULL budget means "priced per line, the way the lot was made") and no ledger, payment or stock row is touched.
 * The operational stage is derived from the existing status column, never stored twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sourcing_lots', 'client_budget')) {
            Schema::table('sourcing_lots', function (Blueprint $table) {
                $table->unsignedBigInteger('client_budget')->nullable(); // paisa: the agreed revenue of the whole lot (not cash received)
                $table->timestamp('ordered_at')->nullable();
                $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
