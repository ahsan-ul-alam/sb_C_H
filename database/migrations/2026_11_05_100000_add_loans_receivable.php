<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money lent out that must be paid back (a loan receivable), created by reclassifying an expense that was really a loan.
 * Additive and re-runnable (see 2026_11_03_100000): nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('loan_receivables', function (Blueprint $table) {
            $table->id();
            $table->string('borrower_name');
            $table->foreignId('borrower_partner_id')->nullable()->constrained('partners')->restrictOnDelete();
            $table->foreignId('expense_id')->constrained('expenses')->restrictOnDelete(); // the original disbursement; never altered
            $table->unsignedBigInteger('principal');
            $table->date('lent_on');
            $table->string('notes')->nullable();
            $table->string('status', 16)->default('pending'); // pending | verified | rejected | reversed
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index('expense_id');
        });

        $this->create('loan_receivable_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_receivable_id')->constrained('loan_receivables')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('repaid_on');
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->string('status', 16)->default('pending'); // pending | verified | rejected | reversed
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();
        });
    }

    private function create(string $name, Closure $callback): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $callback);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_receivable_repayments');
        Schema::dropIfExists('loan_receivables');
    }
};
