<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('country')->nullable();
            $table->string('mobile', 32);
            $table->string('mobile_alt', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('id_type', 32)->nullable();
            $table->string('id_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
