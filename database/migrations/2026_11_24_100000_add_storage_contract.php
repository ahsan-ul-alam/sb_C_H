<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contractual storage entitlement, additive: the reference of the contract that entitles Sellbees to charge a client for warehouse storage, and when it starts. NULL = no
 * contract recorded, never assumed. Used only when finance.storage_contract_required is on; the exceptions report reads it either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'storage_contract_reference')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('storage_contract_reference')->nullable();
                $table->date('storage_contract_from')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
