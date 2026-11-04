<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2D-3A, financial recovery integrity. Additive only; no existing row is read, changed or back-filled.
 *
 * supplier_recovery_documents: ONE register of the documents that prove a supplier gave something back: a credit note number, a supplier's written acceptance, a bank
 *   receipt of a refund, and a payment correction that a supplier return's claim was recovered through. Every workflow that recognises or collects a supplier recovery
 *   (supplier payment refund / credit-note correction, supplier return acceptance, supplier return refund receipt) must register its document here first, and the same
 *   document of the same supplier can be active only once, so one entitlement is never recognised or collected through two workflows. A released document (a cancelled or
 *   rejected event) frees its key: `active` is 1 while in force and NULL once released, and the unique index ignores NULLs.
 * supplier_returns.difference_class: why the cost not recovered was not recovered (a structured class, for the operational P&L mapping of Phase 2E).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_recovery_documents')) {
            Schema::create('supplier_recovery_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
                $table->string('kind', 20); // credit_note | acceptance | bank_receipt | payment_correction
                $table->string('document_key', 120); // the reference with case, spaces and punctuation removed
                $table->string('reference'); // as it was typed
                $table->string('source_type', 40); // supplier_return | supplier_return_settlement | supplier_payment_correction
                $table->unsignedBigInteger('source_id');
                $table->bigInteger('amount')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->tinyInteger('active')->nullable()->default(1); // 1 in force, NULL released
                $table->timestamp('released_at')->nullable();
                $table->timestamps();
                $table->unique(['supplier_id', 'document_key', 'active'], 'supplier_recovery_documents_active_unique');
                $table->index(['source_type', 'source_id']);
            });
        }
        if (! Schema::hasColumn('supplier_returns', 'difference_class')) {
            Schema::table('supplier_returns', function (Blueprint $table) {
                $table->string('difference_class', 24)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
