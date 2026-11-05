<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preparation records for reconciling historical data. Nothing here posts to the ledger, changes a payment, or backfills existing rows: every table starts empty
 * and every existing financial account keeps activation_status 'active'.
 *
 * financial_accounts.activation_status   a prepared account is inactive until Finance confirms its identity and a second person activates it against a verified statement.
 * receipt_reviews                        a proposed classification of one historical customer receipt: owner-confirmed received, reviewed, approved, then applied.
 *                                        account_verified / revenue_recognition_verified stay false: this workflow never sets them.
 * lot_ownership_requests                 maker-checker for the irreversible ownership of a lot, with evidence.
 * reconciliation_exceptions              visible, auditable open items (a voided payment with a difference, the unassigned cash, an unresolved lot ownership).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('financial_accounts', 'activation_status')) {
            Schema::table('financial_accounts', function (Blueprint $table) {
                $table->string('activation_status', 24)->default('active');
                $table->string('identity_reference')->nullable();
                $table->date('opening_date')->nullable();
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('activated_at')->nullable();
            });
        }
        if (! Schema::hasTable('receipt_reviews')) {
            Schema::create('receipt_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
                $table->string('proposed_nature', 24); // service | product_sourcing
                $table->string('suggestion_basis')->nullable();
                $table->date('owner_confirmed_on');
                $table->string('owner_confirmation_reference');
                $table->boolean('account_verified')->default(false);
                $table->boolean('revenue_recognition_verified')->default(false);
                $table->string('status', 10)->default('proposed'); // proposed | reviewed | approved | applied | rejected
                $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('lot_ownership_requests')) {
            Schema::create('lot_ownership_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
                $table->string('proposed_ownership', 8); // company | client
                $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('evidence_note', 500);
                $table->string('status', 10)->default('pending'); // pending | approved | rejected
                $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('reconciliation_exceptions')) {
            Schema::create('reconciliation_exceptions', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->unique();
                $table->string('type', 30);
                $table->string('subject');
                $table->bigInteger('amount')->nullable();
                $table->text('detail');
                $table->text('evidence_required');
                $table->string('status', 10)->default('open'); // open | resolved
                $table->text('resolution_note')->nullable();
                $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
