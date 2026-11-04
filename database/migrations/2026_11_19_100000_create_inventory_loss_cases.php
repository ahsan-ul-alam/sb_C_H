<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory damage, impairment and write-off cases, Phase 2D-1. Additive only.
 *
 * One case is one physical finding about a quantity of one lot line, taken through three separate people: Inventory/Operations proposes it (the condition found and a
 * proposed recoverable value), Finance reviews the evidence and the treatment, and a Board member decides. Nothing is posted before the decision. The values the
 * decision posted (carrying value, loss, restored cost) and the journal are written back here and never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_loss_cases')) {
            return;
        }
        Schema::create('inventory_loss_cases', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10); // owned (Sellbees stock) | return (a damaged return, control came back) | custody (client-owned stock, an incident only)
            $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_handover_id')->nullable()->constrained('inventory_handovers')->restrictOnDelete();
            $table->foreignId('inventory_handover_return_id')->nullable()->constrained('inventory_handover_returns')->restrictOnDelete();
            $table->string('from_bucket', 10); // damaged | available: where the units are standing now
            $table->decimal('quantity', 14, 2);
            $table->string('outcome', 16); // repairable | impaired | write_off | custody_damage
            $table->string('classification', 10)->nullable(); // normal | abnormal (cause-based; null for a custody incident)
            $table->string('cause');
            $table->string('evidence_reference');
            $table->string('assessment_note')->nullable();
            $table->bigInteger('recoverable_value')->nullable(); // total for the quantity; NULL = valuation pending, never assumed zero
            $table->string('valuation_basis')->nullable();
            $table->date('event_on'); // when the damage or return happened
            $table->date('discovered_on'); // when it was found and recorded
            $table->date('posted_on')->nullable(); // the date the journal carries (today when the event's month is closed)
            $table->boolean('prior_period')->default(false); // the event's month was closed: flagged for Finance/Board attention, corrected in the open period
            $table->string('status', 18)->default('pending_review'); // pending_valuation | pending_review | reviewed | approved | rejected | cancelled
            $table->string('treatment', 24)->nullable(); // inventory_loss_expense | retained_in_cogs | impaired_inventory | custody_incident
            $table->bigInteger('carrying_value')->nullable();
            $table->bigInteger('loss_value')->nullable();
            $table->bigInteger('cogs_reversed')->nullable();
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
            $table->index(['inventory_handover_return_id', 'status']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
