<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. An account statement now says what kind of evidence its balance rests on ('statement' = a bank / bKash statement or count sheet,
 * 'owner_confirmed' = the owner's written confirmation, not an independent statement) and, optionally, the exact time of the balance. Existing rows keep
 * evidence 'statement'. Nothing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_statements', function (Blueprint $table) {
            $table->string('evidence', 16)->default('statement');
            $table->timestamp('cutoff_at')->nullable();
        });
    }

    /**
     * Not a recovery path (restore the backup).
     */
    public function down(): void
    {
        Schema::table('account_statements', fn (Blueprint $table) => $table->dropColumn(['evidence', 'cutoff_at']));
    }
};
