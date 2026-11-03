<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product operations: stock movements (the one source of stock truth), order lines and delivery status on the existing client orders,
 * customer collections held for the client, client settlements, and monthly warehouse storage charges. Additive only: every new column
 * is NULL or has a harmless default, so existing lots, orders and invoices are untouched and nothing is reclassified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sourcing_lots', function (Blueprint $table) {
            $table->string('ownership', 8)->nullable(); // client | company; NULL = not stated yet (never guessed for existing lots)
            $table->decimal('storage_sqft', 10, 2)->nullable(); // occupied warehouse area, measured by a person
            $table->date('storage_measured_on')->nullable();
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_rate_minor')->nullable(); // per sq ft per month; NULL = company default
        });
        Schema::table('business_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_rate_minor')->default(0); // 0 = no storage fee configured
            $table->unsignedInteger('storage_threshold')->default(100); // a lot is charged only while MORE than this many units are on hand
            $table->boolean('storage_deduct')->default(false); // may unpaid storage fees be netted from a client's settlement?
        });
        Schema::table('client_orders', function (Blueprint $table) {
            $table->string('fulfilment', 8)->nullable(); // stock = fulfilled from inventory; NULL = the original manual commission order
            $table->string('delivery_status', 12)->nullable(); // pending | confirmed | dispatched | delivered | failed | cancelled
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_address')->nullable();
        });

        Schema::create('client_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sourcing_lot_line_id')->nullable()->constrained()->restrictOnDelete(); // the exact lot the stock came from (NULL until confirmed)
            $table->string('description');
            $table->decimal('quantity', 14, 2);
            $table->unsignedBigInteger('unit_price'); // agreed customer selling price per unit, minor units
            $table->decimal('delivered_quantity', 14, 2)->default(0);
            $table->decimal('returned_quantity', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            // Each movement moves quantity between buckets; the columns of one movement add up to zero (except receipts and adjustments).
            $table->decimal('d_available', 14, 2)->default(0);
            $table->decimal('d_reserved', 14, 2)->default(0);
            $table->decimal('d_dispatched', 14, 2)->default(0);
            $table->decimal('d_delivered', 14, 2)->default(0);
            $table->decimal('d_returned', 14, 2)->default(0); // returned by a customer, awaiting inspection
            $table->decimal('d_damaged', 14, 2)->default(0);
            $table->date('occurred_on');
            $table->string('reason')->nullable();
            $table->string('move_key', 80)->nullable()->unique(); // makes a repeated request a no-op
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['sourcing_lot_line_id', 'occurred_on']);
        });

        Schema::create('order_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('collected_on');
            $table->string('reference')->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('client_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('funds_before'); // client money held when this settlement was recorded
            $table->unsignedBigInteger('commission_netted')->default(0);
            $table->unsignedBigInteger('storage_netted')->default(0);
            $table->unsignedBigInteger('paid')->default(0); // actually remitted to the client
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('storage_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sourcing_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->decimal('quantity', 14, 2); // units on hand at the cutoff
            $table->decimal('sqft', 10, 2)->nullable();
            $table->unsignedBigInteger('rate'); // snapshot: minor units per sq ft per month
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('status', 20); // billed | needs_measurement
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sourcing_lot_id', 'period']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
