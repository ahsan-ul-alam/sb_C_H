<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive; posts nothing and rewrites no record.
 *
 * loan_recovery_allocations   an optional, INTERNAL TRACKING link between a verified loan repayment and an investor. It is not a cash receipt, not paid-in capital, not income
 *                             and has no journal entry: the repayment itself (Dr cash / Cr loans receivable) is the only posting and stays exactly as it is. Proposed by Finance, verified by a
 *                             different Board user, reversed (never deleted) by a Board user. Together the live allocations of a repayment can never exceed its amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loan_recovery_allocations')) {
            Schema::create('loan_recovery_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_receivable_repayment_id')->constrained()->restrictOnDelete();
                $table->foreignId('partner_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('amount');
                $table->string('kind', 12)->default('tracking'); // always tracking: never paid-in capital
                $table->string('reason');
                $table->string('reference')->nullable();
                $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
                $table->string('idempotency_key', 64)->nullable()->unique();
                $table->string('status', 10)->default('pending'); // pending | verified | rejected | reversed
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->string('reversal_reason')->nullable();
                $table->timestamps();
                $table->index(['loan_receivable_repayment_id', 'status'], 'lra_repayment_status_idx');
                $table->index(['partner_id', 'status'], 'lra_partner_status_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
