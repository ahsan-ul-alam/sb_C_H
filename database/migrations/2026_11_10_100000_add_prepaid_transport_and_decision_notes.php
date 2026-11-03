<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid transport: the date the transport service was confirmed as done (until then the money sits in a prepayment asset, not in
 * inventory). Decisions: the reason a pending financial entry was approved or rejected. Additive and re-runnable; every column is NULL
 * for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sourcing_lot_costs', 'service_completed_on')) {
            Schema::table('sourcing_lot_costs', function (Blueprint $table) {
                $table->date('service_completed_on')->nullable();
            });
        }
        foreach (['expenses', 'advance_settlements', 'cost_recognitions', 'loan_receivables'] as $name) {
            if (! Schema::hasColumn($name, 'decision_note')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('decision_note')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
