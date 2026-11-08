<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. A customer payment is never deleted: an approved void stamps payments.voided_at (the row, its allocations and its audit trail stay), and the
 * void itself is a maker-checker request. active_payment_id is set while a request is pending or approved and is unique, so a payment can never carry two live
 * requests and can never be voided twice. No existing row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->index();
        });

        Schema::create('payment_void_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->unsignedBigInteger('amount');                          // the payment as it was proposed; an approval refuses if it has changed since
            $table->string('reason', 255);
            $table->string('evidence', 255);                              // bank / bKash statement reference that supports the void
            $table->string('status', 10)->default('pending');             // pending | approved | rejected
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->unsignedBigInteger('active_payment_id')->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Not a recovery path (restore the backup).
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_void_requests');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('voided_at'));
    }
};
