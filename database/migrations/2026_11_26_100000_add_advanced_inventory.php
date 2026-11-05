<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced inventory, additive only. Nothing here is a second source of stock quantity: stock_movements stays the one ledger.
 *
 * stock_locations              named warehouse places.
 * sourcing_arrival_events      one row per physical arrival of a lot line (the receiving history). The line's received/usable quantity is the SUM of
 *                              its events, written through SourcingArrival::record, so the existing receipt movement and valuation sync are reused.
 * stock_movements.stock_location_id   where an on-hand change physically happened (NULL = not placed).
 * stock_transfers              a move of units between two locations (requested -> completed | cancelled); completing writes a balanced pair of movements.
 * stock_thresholds             low-stock level per product, optionally per project and location.
 * client_orders.sales_channel  where the order came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_locations')) {
            Schema::create('stock_locations', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('sourcing_arrival_events')) {
            Schema::create('sourcing_arrival_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
                $table->string('reference', 80)->nullable();
                $table->decimal('received_quantity', 14, 2);
                $table->decimal('accepted_quantity', 14, 2);
                $table->decimal('rejected_quantity', 14, 2)->default(0); // received - accepted
                $table->decimal('damaged_quantity', 14, 2)->default(0); // the part of rejected that arrived damaged
                $table->date('arrived_on');
                $table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
                $table->string('evidence_reference')->nullable();
                $table->string('notes')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['sourcing_lot_line_id', 'reference']);
            });
        }
        if (! Schema::hasColumn('stock_movements', 'stock_location_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
                $table->foreignId('arrival_event_id')->nullable()->constrained('sourcing_arrival_events')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sourcing_lot_line_id')->constrained()->restrictOnDelete();
                $table->foreignId('inventory_handover_id')->nullable()->constrained('inventory_handovers')->restrictOnDelete();
                $table->foreignId('from_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete(); // NULL = units not yet placed anywhere (older stock)
                $table->foreignId('to_location_id')->constrained('stock_locations')->restrictOnDelete();
                $table->decimal('quantity', 14, 2);
                $table->string('status', 12)->default('requested'); // requested | completed | cancelled
                $table->string('reason');
                $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('stock_thresholds')) {
            Schema::create('stock_thresholds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_product_id')->constrained()->restrictOnDelete();
                $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
                $table->decimal('minimum_quantity', 14, 2);
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('client_orders', 'sales_channel')) {
            Schema::table('client_orders', function (Blueprint $table) {
                $table->string('sales_channel', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
