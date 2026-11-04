<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the money of an expense reversal goes. An expense paid in parts through several accounts is refunded per original payment, so each
 * refund amount is tied to the payment (and account) it reverses instead of one account being assumed for all of it. Additive and
 * re-runnable; a reversal requested before this table existed has no rows and keeps posting to the single account on its own row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expense_refund_allocations')) {
            return;
        }
        Schema::create('expense_refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reversal_expense_id')->constrained('expenses')->restrictOnDelete();
            $table->foreignId('expense_payment_id')->nullable()->constrained('expense_payments')->restrictOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->index('reversal_expense_id');
        });
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
