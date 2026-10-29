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
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('website')->nullable()->after('email');
            $table->string('watermark_path')->nullable()->after('logo_path');
            $table->boolean('show_watermark')->default(true)->after('watermark_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn(['website', 'watermark_path', 'show_watermark']);
        });
    }
};
