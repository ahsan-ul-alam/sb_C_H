<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the day-to-day sourcing workflow needs on top of the supplier-advance accounting: the purchase order and planned project of a lot,
 * its operational (procurement) status kept apart from its financial status, and a settlement of kind "payable" for accepted goods that
 * exceed the advance paid. Additive and re-runnable; every new column is NULL or has a harmless default for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sourcing_lots', 'purchase_order')) {
            Schema::table('sourcing_lots', function (Blueprint $table) {
                $table->string('purchase_order')->nullable();
                $table->string('procurement_status', 16)->default('ordered'); // ordered | preparing | dispatched | in_transit | arrived
                $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete(); // the client project the lot is for (allocations still carry the quantities)
            });
        }
        if (! Schema::hasColumn('advance_settlements', 'kind')) {
            Schema::table('advance_settlements', function (Blueprint $table) {
                $table->string('kind', 12)->default('advance'); // advance (settles money already paid) | payable (accepted goods not yet paid for)
            });
            Schema::table('advance_settlements', function (Blueprint $table) {
                $table->unsignedBigInteger('supplier_payment_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
