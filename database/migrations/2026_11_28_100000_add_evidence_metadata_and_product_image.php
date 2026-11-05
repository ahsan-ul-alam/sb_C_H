<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receiving evidence reuses the existing documents table (private disk, subject_type = stock_arrival). These columns record what kind of evidence a
 * file is and fingerprint it (sha256), so the same file cannot be attached twice to one arrival and a verified receipt can cite exactly what was seen.
 * supplier_products.image_path is the single product image, kept on the private disk and served through an authorised route; no binary is ever stored in
 * a financial table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'sha256')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('kind', 20)->nullable();
                $table->string('mime', 80)->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('sha256', 64)->nullable();
            });
        }
        if (! Schema::hasColumn('supplier_products', 'image_path')) {
            Schema::table('supplier_products', function (Blueprint $table) {
                $table->string('image_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
