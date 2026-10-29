<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Refunds, customer advances and their recognition are separate records linked to the ORIGINAL payment,
        // which is never modified. They are verified by a different user and journaled when the month closes.
        Schema::create('revenue_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24); // advance | recognition | refund
            $table->string('against', 16)->nullable(); // refund only: revenue | advance
            $table->unsignedBigInteger('amount');
            $table->date('effective_on');
            $table->string('reason')->nullable();
            $table->string('status', 16)->default('pending'); // pending | verified | rejected
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'effective_on']);
        });

        Schema::create('opening_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->default('opening'); // opening | adjustment
            $table->date('as_of');
            $table->string('note')->nullable();
            $table->string('status', 16)->default('draft'); // draft | approved | posted | cancelled
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('opening_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_batch_id')->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->foreignId('partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('note')->nullable();
        });

        // Supporting documents (exit settlements, policies). Stored on the private local disk.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id');
            $table->string('name');
            $table->string('path');
            $table->string('review_status', 16)->default('pending'); // pending | approved | rejected
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('exit_settlements', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
        });

        Schema::table('resolutions', function (Blueprint $table) {
            $table->string('approval_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resolutions', fn (Blueprint $t) => $t->dropColumn('approval_reference'));
        Schema::table('exit_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
        foreach (['documents', 'opening_lines', 'opening_batches', 'revenue_adjustments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
