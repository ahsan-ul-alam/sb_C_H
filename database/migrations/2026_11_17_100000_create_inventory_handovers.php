<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client handover (transfer of control) and its returns, Phase 2C-1. Additive only.
 *
 * inventory_handovers: a request, approved by a different authorised user, that control of a quantity of Sellbees-owned goods has passed to a client project. Only an
 * approved handover posts cost of goods sold (Dr cogs / Cr assets.inventory) and removes the exact value from the lot line's layers. The values posted are stored here.
 * inventory_handover_returns: goods coming back to Sellbees under evidence, resalable (cost restored at the original cost) or damaged (no value restored).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_handovers')) {
            Schema::create('inventory_handovers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->restrictOnDelete();
                $table->foreignId('client_id')->constrained()->restrictOnDelete();
                $table->foreignId('sourcing_allocation_id')->constrained()->restrictOnDelete();
                $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
                $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
                $table->foreignId('supplier_product_id')->nullable()->constrained()->restrictOnDelete();
                $table->decimal('quantity', 14, 2);
                $table->date('proposed_on');
                $table->date('transferred_on'); // when control actually passed to the client (a fact, not the invoice date)
                $table->string('evidence_reference'); // signed receiving document or contractual handover record
                $table->string('evidence_note')->nullable();
                $table->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->bigInteger('purchase_value')->nullable(); // exact integer value removed from the line's cost layers, set on approval
                $table->bigInteger('landed_value')->nullable();
                $table->bigInteger('cogs_value')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->decimal('returned_quantity', 14, 2)->default(0); // resalable goods back, cost restored
                $table->decimal('damaged_returned_quantity', 14, 2)->default(0); // damaged goods back, no value restored
                $table->string('idempotency_key', 64)->unique();
                $table->timestamps();
                $table->index(['sourcing_allocation_id', 'status']);
            });
        }
        if (! Schema::hasTable('inventory_handover_returns')) {
            Schema::create('inventory_handover_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_handover_id')->constrained()->restrictOnDelete();
                $table->decimal('quantity', 14, 2);
                $table->string('condition', 10); // resalable | damaged
                $table->date('returned_on');
                $table->string('evidence_reference');
                $table->string('reason');
                $table->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->bigInteger('purchase_value')->nullable();
                $table->bigInteger('landed_value')->nullable();
                $table->bigInteger('restored_value')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->string('idempotency_key', 64)->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
