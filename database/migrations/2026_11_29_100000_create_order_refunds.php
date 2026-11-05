<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds of money collected from an end customer for a client's goods. A refund is a request, an approval by a different person, and a recorded
 * payout from a named account; only the payout posts to the ledger (Dr client funds / Cr cash), so an order status change alone never refunds anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_refunds')) {
            Schema::create('order_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_order_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('amount');
                $table->string('kind', 10); // return = linked to goods coming back; no_return = needs the approver to confirm the contractual justification
                $table->string('reason');
                $table->string('status', 10)->default('requested'); // requested | approved | rejected | paid | cancelled
                $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
                $table->date('paid_on')->nullable();
                $table->string('reference')->nullable();
                $table->string('idempotency_key', 64)->nullable()->unique();
                $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
