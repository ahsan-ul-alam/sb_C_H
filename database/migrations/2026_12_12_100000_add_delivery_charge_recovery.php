<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive; rewrites no financial record and posts nothing.
 *
 * client_orders.delivery_invoice_id    the client invoice on which a courier charge that SELLBEES paid for the client was billed (once, by an explicit action, through
 *                                      the existing billing). Settlement then recovers it like any other fee: by netting that invoice from the money held for the client.
 * client_settlements.delivery_netted   how much of a settlement was such a recovery (kept apart from commission and storage so every taka stays traceable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_orders', 'delivery_invoice_id')) {
            Schema::table('client_orders', function (Blueprint $table) {
                $table->foreignId('delivery_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('client_settlements', 'delivery_netted')) {
            Schema::table('client_settlements', function (Blueprint $table) {
                $table->unsignedBigInteger('delivery_netted')->default(0);
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
