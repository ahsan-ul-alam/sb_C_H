<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RevenueAdjustment;
use App\Models\Segment;
use Illuminate\Support\Collection;

/**
 * Per-project financial summary, built from grouped queries (one per source, never one per project).
 *
 *   Recognised revenue = payments − advances still unearned + recognitions − refunds of revenue   (cash is not revenue)
 *   Gross profit       = recognised revenue − direct project costs                                (accrual: costs count when verified)
 *   Cash flow          = payments received − costs actually paid (net of cash returned) − refunds
 *
 * Shared office expenses are never charged to a project unless someone splits them explicitly (ExpenseService::allocate);
 * such a split is shown separately as "allocated shared costs" and never enters gross profit.
 */
class ProjectFinancialService
{
    /**
     * @param  Collection<int, int>|list<int>|null  $projectIds  null means every project
     * @return Collection<int, array<string, int|null>> keyed by project id
     */
    public static function summaries(Collection|array|null $projectIds = null): Collection
    {
        $ids = $projectIds === null ? Project::pluck('id') : collect($projectIds);
        $in = fn ($q, string $col = 'project_id') => $q->whereIn($col, $ids);

        $contract = $in(Segment::query())->selectRaw('project_id, SUM(amount) a')->groupBy('project_id')->pluck('a', 'project_id');
        $invoices = $in(Invoice::query())->selectRaw('project_id, SUM(total) t, SUM(amount_paid) p')->groupBy('project_id')->get()->keyBy('project_id');
        $received = $in(Payment::query())->selectRaw('project_id, SUM(amount) a')->groupBy('project_id')->pluck('a', 'project_id');

        $adjustments = RevenueAdjustment::query()->join('payments', 'payments.id', '=', 'revenue_adjustments.payment_id')
            ->where('revenue_adjustments.status', 'verified')->whereIn('payments.project_id', $ids)
            ->selectRaw('payments.project_id project_id, revenue_adjustments.kind kind, revenue_adjustments.against against, SUM(revenue_adjustments.amount) a')
            ->groupBy('payments.project_id', 'revenue_adjustments.kind', 'revenue_adjustments.against')->get()->groupBy('project_id');

        $costs = $in(Expense::query())->where('status', 'verified')->where('classification', 'direct')->where('is_asset', false)
            ->selectRaw('project_id, reverses_id IS NOT NULL AS is_reversal, correction_type, payment_status, SUM(amount) amount, SUM(paid_amount) paid')
            ->groupBy('project_id', 'is_reversal', 'correction_type', 'payment_status')->get()->groupBy('project_id');

        $shared = ExpenseAllocation::query()->join('expenses', 'expenses.id', '=', 'expense_allocations.expense_id')
            ->where('expenses.status', 'verified')->whereIn('expense_allocations.project_id', $ids)
            ->selectRaw('expense_allocations.project_id project_id, SUM(expense_allocations.amount) a')->groupBy('expense_allocations.project_id')->pluck('a', 'project_id');

        return $ids->mapWithKeys(function (int $id) use ($contract, $invoices, $received, $adjustments, $costs, $shared) {
            $adj = fn (string $kind, ?string $against = null) => (int) ($adjustments[$id] ?? collect())->where('kind', $kind)
                ->when($against, fn ($c) => $c->where('against', $against))->sum('a');
            $cost = $costs[$id] ?? collect();

            $payments = (int) ($received[$id] ?? 0);
            $advance = $adj('advance');
            $recognition = $adj('recognition');
            $refundRevenue = $adj('refund', 'revenue');
            $refundAdvance = $adj('refund', 'advance');
            $recognised = $payments - $advance + $recognition - $refundRevenue;

            $originals = $cost->where('is_reversal', 0);
            $reversals = $cost->where('is_reversal', 1);
            $directCosts = (int) $originals->sum('amount') - (int) $reversals->sum('amount');
            $unpaidCosts = (int) $originals->whereIn('payment_status', ['unpaid', 'partial'])->sum(fn ($r) => $r->amount - $r->paid);
            $cashBack = (int) $reversals->whereIn('correction_type', ['recording_error', 'refund_received'])->sum('amount');
            $paidCosts = (int) $originals->sum('paid') - $cashBack;

            $contractValue = (int) ($contract[$id] ?? 0);
            $gross = $recognised - $directCosts;

            return [$id => [
                'contract_value' => $contractValue,
                'invoiced' => (int) ($invoices[$id]->t ?? 0),
                'invoice_balance' => (int) ($invoices[$id]->t ?? 0) - (int) ($invoices[$id]->p ?? 0),
                'payments_received' => $payments,
                'outstanding' => $contractValue - $payments,
                'advances_held' => $advance - $recognition - $refundAdvance,
                'refunds' => $refundRevenue + $refundAdvance,
                'recognised_revenue' => $recognised,
                'direct_costs' => $directCosts,
                'unpaid_costs' => $unpaidCosts,
                'paid_costs' => $paidCosts,
                'gross_profit' => $gross,
                'gross_margin_bps' => $recognised > 0 ? intdiv($gross * 10000, $recognised) : null,
                'cash_flow' => $payments - $paidCosts - $refundRevenue - $refundAdvance,
                'allocated_shared_costs' => (int) ($shared[$id] ?? 0),
            ]];
        });
    }

    /** @return array<string, int|null> */
    public static function for(Project $project): array
    {
        return self::summaries([$project->id])[$project->id];
    }
}
