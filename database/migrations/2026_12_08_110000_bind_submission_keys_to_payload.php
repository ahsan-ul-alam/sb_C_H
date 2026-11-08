<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A key may only repeat the same submission, so each claim stores a hash of what was submitted. The table must be InnoDB: the guard relies on
 * transactions and on the unique index blocking a concurrent duplicate until the first request commits. Additive; touches no financial record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_keys', function (Blueprint $table) {
            $table->string('payload_hash', 64)->nullable()->after('user_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $engine = DB::selectOne('select ENGINE as e from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME = ?', ['submission_keys']);
            if ($engine && strtoupper((string) $engine->e) !== 'INNODB') {
                DB::statement('ALTER TABLE submission_keys ENGINE=InnoDB');
            }
        }
    }

    public function down(): void
    {
        Schema::table('submission_keys', function (Blueprint $table) {
            $table->dropColumn('payload_hash');
        });
    }
};
