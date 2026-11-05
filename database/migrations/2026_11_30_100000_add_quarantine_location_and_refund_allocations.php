<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closure additions, additive only.
 *
 * sourcing_arrival_events.quarantine_location_id   where the rejected/damaged units of an arrival physically stand (they are never stock and never valued).
 * order_refunds                                    classification, responsible party, commission treatment and its adjustment.
 * order_refund_allocations                         which collection(s) a refund is paid back against, exact amounts (unique per refund + collection).
 * commission_adjustments                           an auditable correction of an order's commission, unique per refund so it can never be applied twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sourcing_arrival_events', 'quarantine_location_id')) {
            Schema::table('sourcing_arrival_events', function (Blueprint $table) {
                $table->foreignId('quarantine_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('order_refunds', 'classification')) {
            Schema::table('order_refunds', function (Blueprint $table) {
                $table->string('classification', 24)->default('return_of_goods');
                $table->string('beneficiary')->nullable();
                $table->string('responsible_party', 10)->default('client'); // client = paid from the client's funds | sellbees = Sellbees bears it (needs Finance review)
                $table->string('commission_treatment', 12)->default('pending'); // pending | reduce | keep | review (reduce was approved but the commission was already paid or netted, so it needs a manual correction)
            });
        }
        if (! Schema::hasTable('order_refund_allocations')) {
            Schema::create('order_refund_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_refund_id')->constrained()->restrictOnDelete();
                $table->foreignId('order_collection_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('amount');
                $table->timestamps();
                $table->unique(['order_refund_id', 'order_collection_id'], 'refund_alloc_refund_collection_unique'); // the generated name would exceed MySQL's 64-character limit
            });
        }
        if (! Schema::hasTable('commission_adjustments')) {
            Schema::create('commission_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_order_id')->constrained()->restrictOnDelete();
                $table->foreignId('order_refund_id')->unique()->constrained()->restrictOnDelete(); // one correction per refund: never reversed twice
                $table->unsignedBigInteger('commission_before');
                $table->unsignedBigInteger('commission_after');
                $table->string('basis'); // why, in words, for the audit trail
                $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
