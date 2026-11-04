<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier returns, Phase 2D-2. Additive only.
 *
 * supplier_returns: goods sent back to the supplier, from request to Board approval. Physical return, the supplier's acceptance, the recognition of a claim and the
 *   receipt of cash are separate events: the return row holds the first three, the settlement rows below hold what happens later. Nothing is posted before approval.
 *   The allocation of the recovery (payable reduction, credit note, receivable) and the unrecovered difference are fixed on the row and journaled once.
 * supplier_return_settlements: the later, source-linked events: a credit note applied to a payable, and a cash refund received against the recognised receivable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_returns')) {
            Schema::create('supplier_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
                $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
                $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
                $table->string('kind', 10); // accepted (goods in Sellbees inventory) | unaccepted (rejected at arrival: never inventory, a claim only)
                $table->decimal('quantity', 14, 2);
                $table->string('reason');
                $table->string('return_reference'); // the supplier's or carrier's return document: one physical return is recorded once
                $table->date('physical_returned_on');
                $table->string('evidence_reference');
                $table->bigInteger('claimed_amount')->default(0); // what Sellbees claims back from the supplier for these goods
                $table->string('expected_method', 20)->nullable(); // payable | credit_note | refund | mixed (an expectation, not a recognition)
                $table->string('acceptance', 10)->default('pending'); // pending | accepted | partial | disputed | rejected
                $table->bigInteger('accepted_amount')->nullable(); // what the supplier has accepted to give back
                $table->string('supplier_document')->nullable(); // credit note number or the supplier's written acceptance
                $table->string('acceptance_note')->nullable();
                $table->foreignId('acceptance_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('acceptance_at')->nullable();
                $table->bigInteger('payable_reduction')->default(0); // allocation of the accepted recovery, fixed at Finance verification
                $table->bigInteger('credit_note_amount')->default(0);
                $table->bigInteger('receivable_amount')->default(0);
                $table->string('difference_reason')->nullable(); // why the carrying value removed differs from the recovery recognised
                $table->bigInteger('purchase_value')->nullable(); // exact value taken out of the line's cost layers, set on approval
                $table->bigInteger('landed_value')->nullable();
                $table->bigInteger('carrying_value')->nullable();
                $table->bigInteger('difference_value')->nullable();
                $table->date('event_on')->nullable();
                $table->date('posted_on')->nullable();
                $table->boolean('prior_period')->default(false);
                $table->string('status', 12)->default('requested'); // requested | verified | approved | rejected | cancelled
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->string('verify_note')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->string('idempotency_key', 64)->unique();
                $table->timestamps();
                $table->index(['sourcing_lot_line_id', 'status']);
                $table->index(['supplier_id', 'return_reference']);
            });
        }
        if (! Schema::hasTable('supplier_return_settlements')) {
            Schema::create('supplier_return_settlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_return_id')->constrained()->restrictOnDelete();
                $table->string('method', 14); // credit_applied (to a supplier payable) | cash_refund (received against the receivable)
                $table->bigInteger('amount');
                $table->string('reference'); // payable reference the credit was applied to, or the bank receipt
                $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
                $table->date('settled_on');
                $table->string('status', 10)->default('pending'); // pending | posted | rejected
                $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->string('idempotency_key', 64)->unique();
                $table->timestamps();
                $table->index(['supplier_return_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
