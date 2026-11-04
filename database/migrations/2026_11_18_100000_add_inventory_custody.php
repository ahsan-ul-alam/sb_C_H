<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership versus physical custody, Phase 2D-0. Additive only: every new column is NULL or has a harmless default.
 *
 * stock_movements.inventory_handover_id   the custody slice a movement belongs to. NULL = the line's own stock (Sellbees-owned on a company lot, the whole
 *                                         lot on a client-owned lot). A handover's slice holds the units that were handed over and still stand in the warehouse.
 * client_order_items.inventory_handover_id the custody slice an order line was reserved from, so dispatch, delivery, failure and return follow the same units.
 * inventory_handovers.custody_mode        warehouse = the goods stay in our warehouse as the client's custody stock; released = they physically left at handover.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_movements', 'inventory_handover_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('inventory_handover_id')->nullable()->constrained('inventory_handovers')->restrictOnDelete();
                $table->index(['inventory_handover_id', 'sourcing_lot_line_id']);
            });
        }
        if (! Schema::hasColumn('client_order_items', 'inventory_handover_id')) {
            Schema::table('client_order_items', function (Blueprint $table) {
                $table->foreignId('inventory_handover_id')->nullable()->constrained('inventory_handovers')->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('inventory_handovers', 'custody_mode')) {
            Schema::table('inventory_handovers', function (Blueprint $table) {
                $table->string('custody_mode', 10)->default('warehouse');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
