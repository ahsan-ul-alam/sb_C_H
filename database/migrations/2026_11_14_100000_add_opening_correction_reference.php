<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A correction to opening balances (an "adjustment" batch) names the posted batch it corrects and says why. Additive and re-runnable; NULL
 * for every existing batch (an opening batch corrects nothing, and no adjustment existed before this).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('opening_batches', 'corrects_batch_id')) {
            Schema::table('opening_batches', function (Blueprint $table) {
                $table->foreignId('corrects_batch_id')->nullable()->constrained('opening_batches')->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('opening_batches', 'correction_reason')) {
            Schema::table('opening_batches', function (Blueprint $table) {
                $table->string('correction_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
