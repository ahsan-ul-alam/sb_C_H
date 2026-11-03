<?php

namespace App\Services;

use App\Models\Project;

/**
 * One project, every figure the office asks about, each from its existing source (invoices and payments from ProjectFinancialService, the
 * product-sourcing figures from ProjectSourcingReport, which attributes lot cost to the paisa). Nothing is calculated again in the view and
 * the groups are never merged: client money is not profit; estimated, provisional and final figures are labelled as such.
 */
class ProjectFinancialOverview
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Project $project, ?array $report = null): array
    {
        $report ??= ProjectSourcingReport::build();
        $s = $report['projects'][$project->id] ?? null;
        $client = ProjectFinancialService::for($project);
        $purchase = (int) ($s['purchase_cost'] ?? 0);
        $cost = (int) ($s['cost'] ?? 0);

        return [
            'project' => $project,
            // The client side
            'agreed' => (int) ($s['agreed'] ?? 0), 'contract_value' => (int) $client['contract_value'], 'invoiced' => (int) $client['invoiced'],
            'received' => (int) $client['payments_received'], 'client_outstanding' => (int) $client['outstanding'],
            // The supplier side
            'supplier_advances_paid' => (int) ($s['supplier_paid'] ?? 0), 'purchase_cost' => $purchase, 'handling_cost' => max(0, (int) ($s['actual_cost'] ?? 0) - $purchase),
            'acquisition_cost' => (int) ($s['actual_cost'] ?? 0), 'estimated_cost' => $cost, 'open_estimates' => (int) ($s['estimates_open'] ?? 0),
            'supplier_outstanding' => (int) ($s['supplier_payable'] ?? 0) + (int) ($s['costs_unpaid'] ?? 0),
            // The results, kept apart
            'estimated_margin' => (int) ($s['estimated_margin'] ?? 0), 'actual_margin' => (int) ($s['actual_margin'] ?? 0), 'recognised_margin' => (int) ($s['recognised_margin'] ?? 0),
            'cash_surplus' => (int) ($client['payments_received'] - ($s['supplier_paid'] ?? 0) - ($s['costs_paid'] ?? 0)),
            'margin_status' => $s['margin_status'] ?? 'none', 'has_sourcing' => $s !== null, 'lots' => $s['lots'] ?? [],
        ];
    }
}
