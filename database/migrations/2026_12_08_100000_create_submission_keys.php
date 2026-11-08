<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per accepted form submission. The unique key is what makes a double-click, a retry or a replayed request create exactly one record.
 * Additive only: nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('action', 60);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_keys');
    }
};
