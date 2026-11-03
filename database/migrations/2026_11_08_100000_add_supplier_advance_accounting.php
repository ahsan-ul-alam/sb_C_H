<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier-advance accounting (settling an advance into acquisition cost, releasing that cost into profit) and the evidence a
 * supplier payment and an arrival must carry. Additive and re-runnable (see 2026_11_03_100000); nothing existing is altered, every
 * new column is NULL for historical rows, and nothing here changes how any existing record is posted: that is decided by
 * finance.sourcing_basis, which stays "cash" until the Board approves a cutover.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('supplier_payments', 'purchase_order')) {
            Schema::table('supplier_payments', function (Blueprint $table) {
                $table->string('purchase_order')->nullable();
                $table->string('evidence_reference')->nullable(); // the supplier invoice, proforma or payment slip this payment is backed by
            });
        }
        if (! Schema::hasColumn('sourcing_lot_lines', 'acceptance_reference')) {
            Schema::table('sourcing_lot_lines', function (Blueprint $table) {
                $table->string('acceptance_reference')->nullable(); // the supplier's delivery note / inspection record
            });
        }

        $this->create('advance_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_lot_id')->constrained('sourcing_lots')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->restrictOnDelete(); // the advance being settled
            $table->unsignedBigInteger('amount'); // minor units, accepted goods only
            $table->date('settled_on');
            $table->string('status', 16)->default('pending'); // pending | verified | rejected | reversed
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('idempotency_key', 96)->unique();
            $table->timestamps();
        });

        $this->create('cost_recognitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_lot_id')->constrained('sourcing_lots')->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // capitalised cost released into profit
            $table->date('recognised_on');
            $table->string('status', 16)->default('pending'); // pending | verified | rejected | reversed
            $table->string('trigger', 32); // the approved event (finance.sourcing_recognition_trigger) at the time
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            // Set to the lot id while the release is pending or verified, NULL otherwise: the unique index means a lot's cost can be
            // released once, however many people click at the same moment (NULLs do not collide).
            $table->unsignedBigInteger('active_lot_id')->nullable()->unique();
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
        Schema::dropIfExists('cost_recognitions');
        Schema::dropIfExists('advance_settlements');
    }
};
