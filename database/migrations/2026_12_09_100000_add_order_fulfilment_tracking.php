<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfilment tracking of a customer order, additive. Nothing here is posted anywhere: the delivery charge is information about the order (it is not
 * part of the order value, the commission base or any journal), and the timestamps only record when each step really happened.
 *
 * client_orders.courier / tracking_reference   who carries the parcel and under which consignment number.
 * client_orders.delivery_charge                 minor units charged to the customer for delivery; informational.
 * client_orders.confirmed_at / packed_at / dispatched_at / delivered_at   when each step was recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_orders', 'courier')) {
            Schema::table('client_orders', function (Blueprint $table) {
                $table->string('courier', 80)->nullable();
                $table->string('tracking_reference', 120)->nullable();
                $table->unsignedBigInteger('delivery_charge')->default(0);
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('packed_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for operational or financial data (restore the verified backup instead).
    }
};
