<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The accrual customer-revenue engine, additive and forward-only. It is inert until finance.accrual_revenue_from is set (it is not, anywhere); nothing here reads or
 * changes a posted journal, a closed period or a frozen allocation.
 *
 * revenue_events: every customer-facing accounting event of a project, as signed deltas of the five positions it can move, so that each event is one balanced journal and
 *   any position is the plain sum of its events. kinds: recognition (revenue earned: against an invoice it becomes a receivable, with none a contract asset, any customer
 *   advance is applied first), application (an advance settling a receivable, automatic), bill (a contract asset invoiced: becomes a receivable), credit_note (revenue
 *   reversed: the receivable first, any excess a credit owed to the customer) and refund (cash back to the customer out of the advance balance).
 * projects: how Sellbees acts for the project's customer sales (principal | agent), with its evidence, and an optional explicit budget (NULL = not configured, never zero).
 * invoices: a void is recorded, never a delete; it is refused once anything is recognised, credited or paid against the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('revenue_events')) {
            Schema::create('revenue_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->restrictOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('related_event_id')->nullable()->constrained('revenue_events')->restrictOnDelete(); // a credit note's recognition
                $table->foreignId('inventory_handover_id')->nullable()->constrained('inventory_handovers')->restrictOnDelete(); // the transfer of control a principal sale rests on
                $table->foreignId('inventory_handover_return_id')->nullable()->constrained('inventory_handover_returns')->restrictOnDelete(); // goods that came back under a credit note
                $table->string('kind', 12); // recognition | application | bill | credit_note | refund
                $table->string('basis', 14)->nullable(); // sale | service | commission | service_fee
                $table->string('arrangement', 10)->nullable(); // principal | agent, as the project was classified when this was proposed
                $table->bigInteger('amount'); // the headline amount, always positive
                $table->bigInteger('revenue_delta')->default(0);
                $table->bigInteger('receivable_delta')->default(0);
                $table->bigInteger('contract_asset_delta')->default(0);
                $table->bigInteger('advance_delta')->default(0);
                $table->bigInteger('cash_delta')->default(0);
                $table->date('effective_on');
                $table->date('posted_on')->nullable();
                $table->boolean('prior_period')->default(false);
                $table->string('evidence_reference')->nullable(); // the contract clause, delivery or completion record, return note or bank receipt
                $table->string('note')->nullable();
                $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
                $table->string('status', 10)->default('pending'); // pending | approved | rejected | cancelled
                $table->foreignId('prepared_by')->nullable()->constrained('users')->restrictOnDelete(); // NULL for an automatic application
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->string('decision_note')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->string('idempotency_key', 64)->unique();
                $table->timestamps();
                $table->index(['project_id', 'status']);
                $table->index(['invoice_id', 'status']);
            });
        }
        if (! Schema::hasColumn('projects', 'revenue_arrangement')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('revenue_arrangement', 10)->nullable(); // principal | agent; NULL = classification_required
                $table->string('revenue_arrangement_evidence')->nullable();
                $table->bigInteger('budget_minor')->nullable(); // NULL = no budget configured
            });
        }
        if (! Schema::hasColumn('invoices', 'voided_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('void_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back is never a recovery path for financial data (restore the verified backup instead).
    }
};
