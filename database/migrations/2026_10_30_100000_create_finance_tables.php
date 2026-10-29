<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 24)->default('super_admin');
            $table->unsignedBigInteger('partner_id')->nullable();
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('code', 4)->unique();
            $table->string('name');
            $table->string('status', 16)->default('active'); // active | exited
            $table->date('joined_on')->nullable();
            $table->date('exited_on')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Investment, ownership, loss and profit ratios are four independent maps keyed by partner code.
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('effective_from');
            $table->boolean('is_legacy')->default(false);
            $table->json('investment_bps');
            $table->json('ownership_shares');
            $table->json('loss_shares');
            $table->json('profit_bps');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('signed_reference')->nullable();
            $table->foreignId('profit_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('profit_confirmed_at')->nullable();
            $table->string('profit_signed_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->string('memo');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('reversal_of')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->string('account', 40);
            $table->foreignId('partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);
            $table->index(['account', 'partner_id']);
        });

        Schema::create('capital_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->string('status', 16)->default('pending'); // pending | verified | rejected
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 60);
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->date('spent_on');
            $table->string('status', 16)->default('pending'); // pending | verified | rejected
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique(); // YYYY-MM
            $table->bigInteger('income');
            $table->bigInteger('expenses');
            $table->bigInteger('net_profit');
            $table->string('status', 16)->default('closed'); // closed | verified
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_period_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('policy_version_id')->constrained()->restrictOnDelete();
            $table->string('kind', 8); // profit | loss
            $table->bigInteger('net_profit');
            $table->string('status', 16)->default('proposed'); // proposed | approved | posted
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('allocation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_id')->constrained()->restrictOnDelete();
            $table->string('account', 40);
            $table->foreignId('partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('note')->nullable();
            $table->string('status', 16)->default('requested'); // requested | approved | paid | rejected
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // Board resolutions: partner salary, employee increments and other governance decisions.
        Schema::create('resolutions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24); // partner_salary | employee_increment | other
            $table->string('title');
            $table->foreignId('partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('employee_name')->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('previous_amount')->default(0);
            $table->string('month', 7)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | rejected | paid
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exit_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->date('exit_date');
            $table->unsignedBigInteger('company_fund_base');
            $table->unsignedInteger('profit_bps');
            $table->unsignedBigInteger('incentive');
            $table->bigInteger('capital_balance');
            $table->bigInteger('profit_payable');
            $table->bigInteger('other_adjustment')->default(0);
            $table->bigInteger('net_settlement');
            $table->string('adjustment_note')->nullable();
            $table->string('status', 16)->default('proposed'); // proposed | approved
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject', 60)->nullable();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'exit_settlements', 'resolutions', 'payouts', 'allocation_lines', 'allocations', 'accounting_periods', 'expenses', 'capital_entries', 'journal_lines', 'journal_entries', 'policy_versions', 'partners'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'partner_id']));
    }
};
