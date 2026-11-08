<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. Rewrites no financial record.
 *
 * client_orders.delivery_charge_bearer       who bears the courier charge: client (deducted from the sale before commission), sellbees, or customer (paid on top).
 * client_orders.delivery_charge_status       payable (not yet paid by anyone) | paid_by_sellbees | courier_deducted (the courier kept it before remitting): a label that
 *                                            stops the charge being deducted a second time from cash or from the client's settlement.
 * client_orders.delivery_charge_recorded_at  the charge is recorded ONCE, at dispatch; delivery reuses it.
 * client_orders.failed_delivery_charge / return_charge   courier charges of an unsuccessful delivery and of a return, kept apart from the delivery charge.
 * users.all_projects                         an explicit grant of access to every project for staff who are not management roles. Users who today hold the Client
 *                                            Management role are given it once, so nobody loses the access they had; an administrator can then narrow it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_orders', 'delivery_charge_bearer')) {
            Schema::table('client_orders', function (Blueprint $table) {
                $table->string('delivery_charge_bearer', 10)->default('client');
                $table->string('delivery_charge_status', 20)->default('payable');
                $table->timestamp('delivery_charge_recorded_at')->nullable();
                $table->unsignedBigInteger('failed_delivery_charge')->default(0);
                $table->unsignedBigInteger('return_charge')->default(0);
            });
        }
        if (! Schema::hasColumn('users', 'all_projects')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('all_projects')->default(false);
            });
            DB::table('users')->whereIn('id', DB::table('role_user')->where('role', 'client_management')->select('user_id'))->update(['all_projects' => true]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for operational or financial data (restore the verified backup instead).
    }
};
