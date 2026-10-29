<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing users keep the role they already received (they were the ClientHub administrators);
        // every account created from now on has NO role until an administrator assigns one.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 24)->nullable()->default(null)->change();
        });

        Schema::table('resolutions', function (Blueprint $table) {
            $table->unsignedBigInteger('annual_profit')->default(0);
            $table->unsignedBigInteger('original_investment')->default(0);
            $table->unsignedInteger('rate_bps')->nullable();
            $table->unsignedInteger('theoretical_bps')->nullable();
            $table->unsignedInteger('approved_bps')->nullable();
            $table->date('effective_on')->nullable();
        });

        Schema::table('exit_settlements', function (Blueprint $table) {
            $table->bigInteger('recorded_investment')->default(0);
            $table->bigInteger('unadjusted_losses')->default(0);
            $table->bigInteger('expenses_liabilities')->default(0);
            $table->bigInteger('previous_withdrawals')->default(0);
            $table->bigInteger('unpaid_entitlements')->default(0);
            $table->bigInteger('other_liabilities')->default(0);
            $table->bigInteger('other_receivables')->default(0);
            $table->string('payment_status', 16)->default('unpaid'); // unpaid | paid
            $table->date('paid_on')->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exit_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['recorded_investment', 'unadjusted_losses', 'expenses_liabilities', 'previous_withdrawals',
                'unpaid_entitlements', 'other_liabilities', 'other_receivables', 'payment_status', 'paid_on', 'payment_reference']);
        });
        Schema::table('resolutions', function (Blueprint $table) {
            $table->dropColumn(['annual_profit', 'original_investment', 'rate_bps', 'theoretical_bps', 'approved_bps', 'effective_on']);
        });
    }
};
