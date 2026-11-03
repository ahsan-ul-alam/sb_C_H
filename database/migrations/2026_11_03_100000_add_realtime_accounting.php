<?php

use App\Services\LegacyBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: new tables and nullable / defaulted columns. Nothing existing is dropped, renamed or rewritten.
 * Existing expenses were all cash-paid on their spending date, so the new payment columns are back-filled to say exactly that.
 *
 * Re-runnable: every step checks what already exists, so a run that stopped half-way (MySQL DDL is not transactional) can
 * simply be run again, and running it on an already migrated database changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financial_accounts')) {
            Schema::create('financial_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('type', 12)->default('cash'); // cash | bank | mobile | petty
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('allow_negative')->default(false);
                $table->string('notes')->nullable();
                $table->timestamps();
            });
        }

        $this->addColumns('journal_lines', 'financial_account_id', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->index(['account', 'financial_account_id']);
        });
        $this->addColumns('payments', 'financial_account_id', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('posting_version')->default(0);
        });
        $this->addColumns('revenue_adjustments', 'financial_account_id', function (Blueprint $table) {
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
        });
        $this->addColumns('capital_entries', 'kind', function (Blueprint $table) {
            $table->string('kind', 12)->default('contribution'); // contribution | withdrawal
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('method', 24)->nullable();
            $table->string('notes')->nullable();
        });
        $this->addColumns('expenses', 'classification', function (Blueprint $table) {
            $table->string('classification', 16)->default('operating'); // operating | direct | asset | other | tax
            $table->string('payment_status', 12)->default('paid'); // unpaid | partial | paid | reversed
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->date('paid_on')->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->string('vendor')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->index(['project_id', 'status']);
            $table->index(['status', 'spent_on']);
        });

        // Idempotent: touches only rows still in the pre-migration shape.
        LegacyBackfill::expenses();

        if (! Schema::hasTable('expense_payments')) {
            Schema::create('expense_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('expense_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('amount');
                $table->date('paid_on');
                $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
                $table->string('reference')->nullable();
                $table->string('idempotency_key', 64)->unique();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('account_transfers')) {
            Schema::create('account_transfers', function (Blueprint $table) {
                $table->id();
                // A null side is the "Unassigned (legacy)" cash that was posted before accounts existed.
                $table->foreignId('from_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
                $table->foreignId('to_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
                $table->unsignedBigInteger('amount');
                $table->date('transferred_on');
                $table->string('reference')->nullable();
                $table->string('notes')->nullable();
                $table->string('idempotency_key', 64)->unique();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }

        // Explicit, opt-in split of a shared (non-direct) expense across projects; never applied automatically.
        if (! Schema::hasTable('expense_allocations')) {
            Schema::create('expense_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('expense_id')->constrained()->restrictOnDelete();
                $table->foreignId('project_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('amount');
                $table->timestamps();
                $table->unique(['expense_id', 'project_id']);
            });
        }
    }

    /** Add a group of columns only when its marker column is missing (so a retry never fails on a half-applied step). */
    private function addColumns(string $table, string $marker, Closure $callback): void
    {
        if (! Schema::hasColumn($table, $marker)) {
            Schema::table($table, $callback);
        }
    }

    public function down(): void
    {
        foreach (['expense_allocations', 'account_transfers', 'expense_payments'] as $table) {
            Schema::dropIfExists($table);
        }
        // Column drops are intentionally omitted: rolling back must never discard financial history.
    }
};
