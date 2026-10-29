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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('number', 32)->unique();
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->string('currency', 3);
            $table->unsignedBigInteger('total')->default(0)->comment('Minor units');
            $table->unsignedBigInteger('amount_paid')->default(0)->comment('Minor units, recalculated from payments');
            $table->string('status', 16)->default('unpaid')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
