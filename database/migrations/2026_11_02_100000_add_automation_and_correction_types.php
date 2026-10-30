<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('auto_policy_id')->nullable()->comment('Board resolution that authorised an automatic verification');
            $table->boolean('is_asset')->default(false)->comment('Capital purchase: posted to an asset account, not to expenses');
            $table->string('correction_type', 16)->nullable()->comment('For reversing rows: recording_error | refund_received | credit_note');
        });

        // A system process (scheduled close / allocation under a standing Board policy) has no user. NULL + audit log
        // + policy reference identifies it; it never borrows a human's name.
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->change();
        });
        Schema::table('allocations', function (Blueprint $table) {
            $table->foreignId('prepared_by')->nullable()->change();
            $table->unsignedBigInteger('auto_policy_id')->nullable();
        });
        Schema::table('emergency_fund_requests', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->change();
            $table->unsignedBigInteger('auto_policy_id')->nullable();
        });
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->unsignedBigInteger('auto_policy_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('emergency_fund_requests', fn (Blueprint $t) => $t->dropColumn('auto_policy_id'));
        Schema::table('allocations', fn (Blueprint $t) => $t->dropColumn('auto_policy_id'));
        Schema::table('accounting_periods', fn (Blueprint $t) => $t->dropColumn('auto_policy_id'));
        Schema::table('expenses', fn (Blueprint $t) => $t->dropColumn(['auto_policy_id', 'is_asset', 'correction_type']));
    }
};
