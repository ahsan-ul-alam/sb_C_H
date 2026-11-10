<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reporting metadata only: which category an invoice LINE belongs to. It never changes an invoice, a payment, a segment or a journal. Every proposal, approval and
     * rejection stays as its own row (nothing is overwritten or deleted); the effective category of a line is its latest APPROVED row, and a row whose category is
     * "unclassified" reverses an earlier classification.
     */
    public function up(): void
    {
        Schema::create('invoice_line_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->string('reason', 255);
            $table->string('status', 10)->default('proposed'); // proposed | approved | rejected
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();
            $table->index(['invoice_item_id', 'status'], 'ilc_item_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_classifications');
    }
};
