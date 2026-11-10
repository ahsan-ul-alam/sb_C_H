<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('nonce', 40)->unique();
            $table->string('session_hash', 64);
            $table->string('code_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id', 'created_at'], 'cpc_client_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_challenges');
    }
};
