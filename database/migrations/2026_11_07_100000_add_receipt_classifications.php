<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer receipt is FOR (a service fee, product sourcing, other). Kept in its own table so the payment rows themselves,
 * which are frozen once their month is closed, are never touched. Additive and re-runnable (see 2026_11_03_100000).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('receipt_classifications')) {
            Schema::create('receipt_classifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
                $table->string('nature', 24); // service | product_sourcing | other
                $table->string('notes')->nullable();
                $table->foreignId('classified_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_classifications');
    }
};
