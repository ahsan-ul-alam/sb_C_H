<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory value subledger (Phase 2B). Additive only: two new tables, nothing existing is touched.
 *
 * inventory_engine_enrolments: a lot enters the valuation engine only through an approved enrolment, which also records who classified its ownership,
 * on what evidence, and from which date. Nothing is enrolled automatically and no ownership is assumed.
 *
 * inventory_value_movements: append-only. Each row moves a quantity and/or a value (integer minor units) of ONE lot line, names the source event that caused it
 * and the posted journal entry that already carries that value in the general ledger (this table never creates a second inventory debit), and carries an
 * idempotency key so a source event is applied once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_engine_enrolments')) {
            Schema::create('inventory_engine_enrolments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sourcing_lot_id')->unique()->constrained()->restrictOnDelete();
                $table->string('ownership', 8); // company | client, as classified and approved here
                $table->string('classification_source'); // the evidence: contract, resolution, signed delivery note...
                $table->date('effective_on');
                $table->string('status', 12)->default('pending'); // pending | approved | rejected
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory_value_movements')) {
            Schema::create('inventory_value_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
                $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('supplier_product_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
                $table->string('ownership', 8);
                $table->string('component', 10); // purchase | landed | quantity
                $table->string('type', 12); // add | correction | reversal
                $table->decimal('quantity_delta', 14, 2)->default(0);
                $table->bigInteger('value_delta')->default(0); // integer minor units, signed
                $table->string('valuation_status', 14); // the line's status after this movement: pending | partial | valued | over_valued
                $table->string('source_type', 32);
                $table->unsignedBigInteger('source_id');
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete(); // the posted entry that carries this value in the ledger
                $table->string('move_key', 100)->unique(); // an event is applied once
                $table->foreignId('reverses_id')->nullable()->constrained('inventory_value_movements')->restrictOnDelete();
                $table->date('effective_on'); // never inside a closed month
                $table->string('reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete(); // who verified the source event
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->index(['sourcing_lot_line_id', 'component']);
                $table->index(['source_type', 'source_id']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
