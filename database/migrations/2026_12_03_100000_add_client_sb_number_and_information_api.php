<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Gives every client a permanent SB number from a one-row counter that only ever goes up (so a number is never reused,
 * even after its client is deleted), and adds the state for the two-step email-OTP client information API: integrations (hashed secret, per-client
 * grants), OTP challenges (only a keyed hash of the code) and an audit trail. Existing clients are numbered in id order; re-running skips numbered ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sb_number_sequence', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });
        DB::table('sb_number_sequence')->insert(['id' => 1, 'last_value' => 0]);

        Schema::table('clients', function (Blueprint $table) {
            $table->string('sb_number', 16)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->foreignId('email_verified_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $next = 0;
        foreach (DB::table('clients')->whereNull('sb_number')->orderBy('id')->pluck('id') as $id) {
            $next++;
            DB::table('clients')->where('id', $id)->update(['sb_number' => 'SB-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT)]);
        }
        DB::table('sb_number_sequence')->where('id', 1)->update(['last_value' => $next]);

        Schema::create('client_api_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key_id', 16)->unique();
            $table->string('secret_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_api_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_api_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['client_api_integration_id', 'client_id']);
        });

        Schema::create('client_info_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 48)->unique();
            $table->foreignId('client_api_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40)->default('client_information');
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id', 'client_api_integration_id', 'created_at'], 'client_info_challenges_throttle');
            $table->index('expires_at');
        });

        Schema::create('client_api_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_api_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->string('request_id', 48)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id', 'created_at']);
        });
    }

    /**
     * Not a recovery path (restore the backup); provided so the schema can be rebuilt in development.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_api_audits');
        Schema::dropIfExists('client_info_challenges');
        Schema::dropIfExists('client_api_grants');
        Schema::dropIfExists('client_api_integrations');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_verified_by');
            $table->dropColumn(['email_verified_at', 'sb_number']);
        });
        Schema::dropIfExists('sb_number_sequence');
    }
};
