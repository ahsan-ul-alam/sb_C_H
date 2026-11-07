<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. A client expense now says whether it is an additional charge (deducted from client profit) or a fee already included in the billed
 * acquisition cost (kept for information, never deducted again, linked to the invoice that included it). A profit declaration is now per currency, so
 * currencies are never added together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_expenses', function (Blueprint $table) {
            $table->string('charge_treatment', 24)->default('additional'); // additional | included_in_acquisition
            $table->foreignId('source_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
        });
        Schema::table('client_profit_declarations', function (Blueprint $table) {
            $table->string('currency', 3)->nullable(); // NULL = the default reporting currency
        });
    }

    /**
     * Not a recovery path (restore the backup).
     */
    public function down(): void
    {
        Schema::table('client_profit_declarations', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::table('client_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_invoice_id');
            $table->dropColumn('charge_treatment');
        });
    }
};
