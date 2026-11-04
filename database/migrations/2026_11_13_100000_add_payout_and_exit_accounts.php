<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The financial account a partner payout or an exit settlement was paid from. Additive and re-runnable; NULL for every existing row
 * (those were paid before the account was recorded, and their journal lines are not touched).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payouts', 'exit_settlements'] as $name) {
            if (! Schema::hasColumn($name, 'financial_account_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
