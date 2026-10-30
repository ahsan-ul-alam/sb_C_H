<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('categories')->nullable();
            $table->text('payment_info')->nullable(); // bank / wallet details; visible only with sourcing.supplier.secrets
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku', 60)->nullable();
            $table->string('category', 80)->nullable();
            $table->string('variant', 120)->nullable();
            $table->string('unit', 20)->default('pcs');
            $table->unsignedBigInteger('reference_price')->default(0)->comment('Minor units per unit; default for NEW lots only');
            $table->decimal('min_order_qty', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('supplier_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price');
            $table->date('effective_on');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('sourcing_lots', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('title');
            $table->string('status', 16)->default('draft'); // draft|costing|priced|approved|in_progress|completed|settled|cancelled
            $table->string('allocation_basis', 10)->default('value'); // how shared costs are spread: value | quantity
            $table->string('pricing_method', 10)->default('fixed'); // fixed (minor units per unit) | markup (basis points on cost)
            $table->unsignedBigInteger('pricing_value')->default(0);
            $table->unsignedBigInteger('approved_cost')->nullable()->comment('Landed cost snapshot at pricing approval');
            $table->unsignedBigInteger('approved_revenue')->nullable()->comment('Sales value snapshot at pricing approval');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('priced_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sourcing_lot_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->string('unit', 20)->default('pcs');
            $table->decimal('quantity', 14, 2);
            $table->decimal('usable_quantity', 14, 2);
            $table->unsignedBigInteger('unit_price')->comment('Snapshot of the supplier price paid for this lot');
            $table->unsignedBigInteger('reference_price')->default(0)->comment('Catalog price when the line was added');
            $table->unsignedBigInteger('sell_price')->nullable();
            $table->unsignedBigInteger('approved_price')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('sourcing_lot_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('sourcing_lot_line_id')->nullable()->constrained()->restrictOnDelete(); // null = shared across lines
            $table->foreignId('replaces_id')->nullable()->constrained('sourcing_lot_costs')->restrictOnDelete();
            $table->string('category', 60);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('kind', 10)->default('actual'); // estimate | actual
            $table->boolean('is_unexpected')->default(false);
            $table->date('incurred_on');
            $table->string('payee')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('sourcing_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 30)->nullable();
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });

        // Quantities of a lot agreed with a client project at an agreed price; "recovery" rows bill an agreed extra cost.
        Schema::create('sourcing_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('sourcing_lot_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('sale'); // sale | recovery
            $table->string('description');
            $table->decimal('quantity', 14, 2)->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->foreignId('billed_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamp('billed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('emergency_fund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('loss_at_request');
            $table->string('reason', 500);
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | utilised | reversed | cancelled
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->foreignId('utilised_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('utilised_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reverse_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['emergency_fund_requests', 'sourcing_allocations', 'supplier_payments', 'sourcing_lot_costs', 'sourcing_lot_lines', 'sourcing_lots', 'supplier_product_prices', 'supplier_products', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
