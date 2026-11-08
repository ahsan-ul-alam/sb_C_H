<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive; rewrites no financial record and posts nothing.
 *
 * client_orders.courier_refund (+ reference, by, at)   an amount the COURIER has actually refunded on a delivered order's charge, recorded once with the courier's reference by an
 *                                                      authorised person. It only reduces Sellbees' own unrecovered courier cost; it never changes the client's entitlement, profit
 *                                                      or commission, and it posts nothing (the cash side is booked through the existing expense reversal / income workflow).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_orders', 'courier_refund')) {
            Schema::table('client_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('courier_refund')->default(0);
                $table->string('courier_refund_reference')->nullable();
                $table->foreignId('courier_refund_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('courier_refund_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for operational or financial data (restore the verified backup instead).
    }
};
