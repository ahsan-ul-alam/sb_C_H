<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial fields of a customer order, additive.
 *
 * client_order_items.unit_discount   minor units taken off the unit price of one line (needs orders.discount; the line value, delivered value, collections
 *                                    and commission all follow the net price, so no figure can ignore it).
 * client_orders.discount_reason      why a discount was given (required whenever any line has one).
 * client_orders.payment_method / delivery_method   how the customer pays and how the goods go.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_order_items', 'unit_discount')) {
            Schema::table('client_order_items', function (Blueprint $table) {
                $table->unsignedBigInteger('unit_discount')->default(0);
            });
        }
        if (! Schema::hasColumn('client_orders', 'discount_reason')) {
            Schema::table('client_orders', function (Blueprint $table) {
                $table->string('discount_reason')->nullable();
                $table->string('payment_method', 20)->nullable();
                $table->string('delivery_method', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
