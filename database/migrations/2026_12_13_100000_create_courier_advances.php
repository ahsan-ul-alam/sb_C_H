<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive; rewrites no financial record.
 *
 * courier_advances             a courier charge Sellbees paid for a client (already an ordinary paid expense) that is recoverable from that client's sales proceeds. Proposed by one
 *                              user and verified by a different one (the same independence the loan-receivable reclassification uses); verifying posts ONE non-cash reclassification
 *                              Dr assets.client_courier_advance / Cr expense, so the expense is not paid or counted twice and no revenue arises.
 * client_settlements.courier_applied / courier_released
 *                              how much of a settlement cleared client funds against such an advance, and how much unrecoverable excess went back to expense.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('courier_advances')) {
            Schema::create('courier_advances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_order_id')->constrained()->restrictOnDelete();
                $table->foreignId('expense_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('amount');
                $table->unsignedBigInteger('applied')->default(0);
                $table->unsignedBigInteger('released')->default(0);
                $table->string('status', 10)->default('pending'); // pending | verified | rejected
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->timestamps();
                $table->index(['client_order_id', 'status']);
            });
        }
        if (! Schema::hasColumn('client_settlements', 'courier_applied')) {
            Schema::table('client_settlements', function (Blueprint $table) {
                $table->unsignedBigInteger('courier_applied')->default(0);
                $table->unsignedBigInteger('courier_released')->default(0);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
