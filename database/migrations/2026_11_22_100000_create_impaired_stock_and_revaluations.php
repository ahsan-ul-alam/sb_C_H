<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory completion, Milestone 1. Additive only.
 *
 * impaired_stock_events: what finally happens to units held apart at a recoverable value by an approved impairment case: they are released back to sellable stock
 *   (repair completed, or found resalable) at the carrying value they were recognised at, or disposed of (sold as salvage or scrapped) with the proceeds, if any.
 * inventory_revaluations: a Board-approved write-down of a lot line's value to net realisable value, and the reversal of an earlier write-down (never above cost).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('impaired_stock_events')) {
            Schema::create('impaired_stock_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_loss_case_id')->constrained('inventory_loss_cases')->restrictOnDelete();
                $table->string('action', 10); // release | dispose
                $table->decimal('quantity', 14, 2);
                $table->bigInteger('proceeds')->default(0); // cash received on disposal
                $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
                $table->string('reference'); // repair completion report, salvage invoice, destruction certificate
                $table->string('evidence_reference');
                $table->date('event_on');
                $table->date('posted_on')->nullable();
                $table->boolean('prior_period')->default(false);
                $table->string('status', 10)->default('pending'); // pending | posted | rejected | cancelled
                $table->bigInteger('carrying_value')->nullable(); // the recoverable value these units were held at
                $table->bigInteger('original_cost')->nullable(); // the cost these units were removed from inventory at
                $table->bigInteger('loss_delta')->nullable(); // + loss, - recovery above the carrying value
                $table->string('loss_account', 20)->nullable();
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->string('idempotency_key', 64)->unique();
                $table->timestamps();
                $table->index(['inventory_loss_case_id', 'status']);
            });
        }
        if (! Schema::hasTable('inventory_revaluations')) {
            Schema::create('inventory_revaluations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
                $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
                $table->string('direction', 10); // write_down | reversal
                $table->bigInteger('amount'); // total value, integer minor units
                $table->string('basis'); // the net realisable value evidence: price list, sales quote, market report
                $table->string('evidence_reference');
                $table->date('effective_on');
                $table->date('posted_on')->nullable();
                $table->boolean('prior_period')->default(false);
                $table->string('status', 14)->default('pending_review'); // pending_review | reviewed | approved | rejected | cancelled
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->string('idempotency_key', 64)->unique();
                $table->timestamps();
                $table->index(['sourcing_lot_line_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
