<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inventory_value_movements.type was created as varchar(12), but the valuation engine writes 'supplier_return' (15) and 'quarantine_release' (18). SQLite does not
 * enforce the length, so the tests passed; MySQL/MariaDB in strict mode rejects the row ("Data too long for column 'type'"), which would have blocked every
 * supplier return and impaired-stock release in production. Widening is non-destructive: no row, value or key changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inventory_value_movements', 'type')) {
            Schema::table('inventory_value_movements', function (Blueprint $table) {
                $table->string('type', 24)->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
