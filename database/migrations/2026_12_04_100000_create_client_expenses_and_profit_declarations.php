<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Client-borne expenses are recorded here for CLIENT profit reporting; they are attribution records, never ledger postings,
 * and never a Sellbees expense or liability. Each goes through entry -> Finance review -> independent approval, may be reversed with a reason, and
 * is allocated (to projects, orders or products) in rows that must add up to the amount. A profit declaration is Finance's confirmation, for one
 * scope and period, that every applicable client expense is recorded; it carries a fingerprint of the data it covered, so any later change voids it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->unsignedBigInteger('amount');
            $table->date('spent_on');
            $table->string('responsible_party', 10)->default('client'); // client = counts in client profit | sellbees = recorded, excluded
            $table->string('paid_status', 8); // paid | unpaid
            $table->string('reference');
            $table->string('description')->nullable();
            $table->string('allocation_basis', 20)->default('direct'); // direct | sales_value | units | manual
            $table->string('allocation_note')->nullable();
            $table->foreignId('expense_id')->nullable()->unique()->constrained('expenses')->restrictOnDelete(); // an equivalent ERP expense, linked so it is not entered twice
            $table->string('status', 10)->default('entered'); // entered | reviewed | approved | rejected | reversed
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->string('duplicate_key', 64)->unique();
            $table->timestamps();
            $table->index(['client_id', 'status', 'spent_on']);
        });

        Schema::create('client_expense_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_expense_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
        });

        Schema::create('client_expense_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_expense_id')->constrained()->restrictOnDelete();
            $table->string('event', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('client_profit_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('zero_categories');
            $table->string('fingerprint', 64);
            $table->string('note')->nullable();
            $table->foreignId('declared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('declared_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->index(['client_id', 'project_id', 'period_from', 'period_to'], 'client_profit_declarations_scope');
        });
    }

    /**
     * Not a recovery path (restore the backup); present so a development schema can be rebuilt.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_profit_declarations');
        Schema::dropIfExists('client_expense_events');
        Schema::dropIfExists('client_expense_allocations');
        Schema::dropIfExists('client_expenses');
    }
};
