<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive and re-runnable (see the previous migration): loans, other income, prepaid expense releases, the maker-checker
 * allocation of legacy cash to accounts, and reviews of reconciliation findings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'prepaid_months')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedSmallInteger('prepaid_months')->nullable(); // prepaid expenses: months the cost is spread over
            });
        }

        $this->create('cash_allocation_batches', function (Blueprint $table) {
            $table->id();
            $table->string('note')->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | cancelled
            $table->bigInteger('unassigned_at_prepare'); // the legacy cash the plan was made against; a plan goes stale if it moves
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        $this->create('cash_allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_allocation_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
        });

        $this->create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('lender');
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $this->create('loan_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12); // receipt | principal
            $table->unsignedBigInteger('amount');
            $table->date('entry_date');
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('status', 16)->default('pending'); // pending | verified | rejected | reversed
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();
        });

        $this->create('other_incomes', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->date('received_on');
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('status', 16)->default('pending'); // pending | verified | rejected | reversed
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();
        });

        $this->create('prepaid_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->string('month', 7); // YYYY-MM
            $table->unsignedBigInteger('amount');
            $table->string('role', 12)->default('operating'); // operating | direct
            $table->timestamps();
            $table->unique(['expense_id', 'month']);
        });

        $this->create('reconciliation_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('check_key', 64)->unique();
            $table->string('status', 16); // acknowledged | resolved
            $table->string('note');
            $table->bigInteger('difference_at_review');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
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
        foreach (['reconciliation_reviews', 'prepaid_releases', 'other_incomes', 'loan_entries', 'loans', 'cash_allocation_lines', 'cash_allocation_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
