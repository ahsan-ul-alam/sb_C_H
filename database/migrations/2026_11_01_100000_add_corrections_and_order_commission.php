<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A correcting expense points at the verified expense it reverses (Dr cash / Cr expense when verified).
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('reverses_id')->nullable()->after('verified_at')->constrained('expenses')->restrictOnDelete();
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->foreignId('reversal_expense_id')->nullable()->after('expense_id')->constrained('expenses')->restrictOnDelete();
        });

        // Commission rates: a default (0 until Finance sets it — nothing is charged silently) and an optional per-client override.
        Schema::table('business_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('commission_bps')->default(0);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('commission_bps')->nullable();
        });

        Schema::create('client_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('reference');
            $table->date('ordered_on');
            $table->unsignedBigInteger('net_value')->comment('Agreed net order value, excluding shipping and taxes. Minor units');
            $table->string('status', 12)->default('pending'); // pending | successful | cancelled
            $table->unsignedSmallInteger('rate_bps')->nullable()->comment('Rate snapshot taken when the order became successful');
            $table->unsignedBigInteger('commission')->default(0);
            $table->foreignId('billed_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamp('billed_at')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_orders');
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('commission_bps'));
        Schema::table('business_settings', fn (Blueprint $t) => $t->dropColumn('commission_bps'));
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reversal_expense_id');
        });
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reverses_id');
        });
    }
};
