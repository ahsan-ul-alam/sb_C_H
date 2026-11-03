<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified account statements (the independent figure a ledger balance is compared with) and the arrival facts of a sourcing
 * line. Additive and re-runnable (see 2026_11_03_100000): nothing existing is altered, and the new line columns stay NULL
 * ("not recorded") until someone records a real arrival; goods are never assumed to have been received.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('account_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->bigInteger('statement_balance'); // minor units; may be negative for an overdrawn account
            $table->string('reference'); // the statement, screenshot or passbook page this figure comes from
            $table->string('notes')->nullable();
            $table->string('status', 16)->default('pending'); // pending | verified | rejected
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['financial_account_id', 'statement_date']);
        });

        $this->create('statement_reconciling_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_statement_id')->constrained('account_statements')->restrictOnDelete();
            $table->string('kind', 32); // payment_not_cleared | receipt_in_transit | unrecorded_charge | other
            $table->bigInteger('amount'); // signed: what is ADDED to the statement balance to reach the ledger balance
            $table->string('description');
            $table->timestamps();
        });

        if (! Schema::hasColumn('sourcing_lot_lines', 'received_quantity')) {
            Schema::table('sourcing_lot_lines', function (Blueprint $table) {
                $table->decimal('received_quantity', 14, 2)->nullable(); // NULL = no arrival recorded yet
                $table->date('arrived_on')->nullable();
            });
        }
    }

    private function create(string $name, Closure $callback): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $callback);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_reconciling_items');
        Schema::dropIfExists('account_statements');
    }
};
