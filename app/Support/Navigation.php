<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The one list of ERP screens, shared by the sidebar and the global search palette so a user is only ever offered pages their permissions allow.
 * Each item: [label, route name, permission (null = any finance-area user), icon, active route patterns].
 */
class Navigation
{
    /** @return array<string, list<array{0: string, 1: string, 2: ?string, 3: string, 4: list<string>}>> */
    public static function groups(): array
    {
        return [
            'Overview' => [
                ['Business Dashboard', 'dashboard', 'dashboard.business', 'chart', ['dashboard']],
                ['ClientHub Overview', 'clienthub.dashboard', 'clienthub', 'dashboard', ['clienthub.dashboard']],
                ['Finance Dashboard', 'finance.dashboard', 'finance.view', 'chart', ['finance.dashboard']],
                ['My Account', 'finance.me', 'own.view', 'users', ['finance.me']],
            ],
            'Client Management' => [
                ['Clients', 'clients.index', 'clienthub', 'users', ['clients.*']],
                ['Projects', 'projects.index', 'clienthub', 'folder', ['projects.*', 'segments.*']],
                ['Invoices', 'invoices.index', 'clienthub', 'invoice', ['invoices.*']],
                ['Payments', 'payments.index', 'clienthub', 'wallet', ['payments.*']],
                ['Invoice Classification', 'finance.classifications', 'finance.view', 'invoice', ['finance.classifications*']],
                ['Client Portal Preview', 'portal.preview.form', 'portal.preview', 'check-circle', ['portal.preview.*']],
            ],
            'Suppliers & Sourcing' => [
                ['Sourcing Overview', 'sourcing.overview', 'sourcing.view', 'dashboard', ['sourcing.overview']],
                ['Suppliers', 'sourcing.suppliers.index', 'sourcing.view', 'users', ['sourcing.suppliers.*']],
                ['Supplier Products', 'sourcing.products.index', 'sourcing.view', 'layers', ['sourcing.products.*']],
                ['Sourcing Lots', 'sourcing.lots.index', 'sourcing.view', 'folder', ['sourcing.lots.*']],
                ['Supplier Payments', 'sourcing.payments.index', 'sourcing.view', 'wallet', ['sourcing.payments.*']],
                ['Sourcing Reports', 'sourcing.reports', 'sourcing.reports', 'chart', ['sourcing.reports']],
                ['Supplier Advances', 'sourcing.advances', 'sourcing.reports', 'wallet', ['sourcing.advances*', 'sourcing.cost-recognitions*']],
                ['Inventory Enrolment', 'sourcing.enrolment', 'sourcing.view', 'check-circle', ['sourcing.enrolment*']],
                ['Client Handovers', 'sourcing.handovers', 'inventory.view', 'layers', ['sourcing.handovers*']],
                ['Damage & Write-offs', 'sourcing.losses', 'inventory.view', 'layers', ['sourcing.losses*']],
                ['Supplier Returns', 'sourcing.supplier-returns', 'inventory.view', 'wallet', ['sourcing.supplier-returns*']],
                ['Project Margins', 'sourcing.projects', 'sourcing.reports', 'chart', ['sourcing.projects']],
            ],
            'Inventory' => [
                ['Inventory Overview', 'stock.overview', 'inventory.view', 'dashboard', ['stock.overview']],
                ['Project Stock', 'stock.projects', 'inventory.view', 'folder', ['stock.projects']],
                ['Receiving', 'stock.receiving', 'inventory.view', 'layers', ['stock.receiving']],
                ['Stock Movements', 'stock.movements', 'inventory.view', 'layers', ['stock.movements']],
                ['Reservations', 'stock.reservations', 'inventory.view', 'check-circle', ['stock.reservations']],
                ['Transfers & Locations', 'stock.transfers', 'inventory.view', 'layers', ['stock.transfers']],
                ['Exceptions', 'stock.exceptions', 'inventory.view', 'alert', ['stock.exceptions']],
                ['Sales Dashboard', 'stock.sales', 'inventory.view', 'chart', ['stock.sales']],
                ['Inventory Reports', 'stock.reports', 'inventory.view', 'chart', ['stock.reports', 'stock.report']],
            ],
            'Product Operations' => [
                ['Product Inventory', 'product.inventory', 'inventory.view', 'layers', ['product.inventory*']],
                ['Orders & Delivery', 'product.orders', 'inventory.view', 'invoice', ['product.orders*']],
                ['Sales Analytics', 'product.analytics', 'inventory.view', 'chart', ['product.analytics']],
                ['Customer Refunds', 'product.refunds', 'inventory.view', 'wallet', ['product.refunds*']],
                ['Client Settlements', 'product.settlements', 'settlement.view', 'wallet', ['product.settlements*']],
            ],
            'Finance' => [
                ['Financial Overview', 'finance.overview', 'finance.view', 'dashboard', ['finance.overview']],
                ['Cash & Bank Accounts', 'finance.accounts', 'finance.view', 'wallet', ['finance.accounts*', 'finance.cash-allocation*']],
                ['Investment Overview', 'finance.investment', 'finance.view', 'chart', ['finance.investment']],
                ['Financial Position', 'finance.position', 'finance.view', 'layers', ['finance.position']],
                ['Profit & Loss', 'finance.pnl', 'finance.view', 'chart', ['finance.pnl']],
                ['Project Profit', 'finance.project-profit', 'finance.view', 'chart', ['finance.project-profit']],
                ['Earned Revenue', 'finance.revenue-events', 'finance.view', 'chart', ['finance.revenue-events*']],
                ['Ledger Reports', 'finance.ledger-reports', 'finance.view', 'chart', ['finance.ledger-reports']],
                ['Finance Automation', 'finance.automation', 'finance.view', 'check-circle', ['finance.automation*']],
                ['Order Commission', 'finance.orders', 'commission.view', 'invoice', ['finance.orders*']],
                ['Investments', 'finance.capital', 'finance.view', 'cash', ['finance.capital*']],
                ['Expenses', 'finance.expenses', 'finance.view', 'wallet', ['finance.expenses*', 'finance.cost-allocation', 'finance.expense-payments']],
                ['Loans', 'finance.loans', 'finance.view', 'cash', ['finance.loans*']],
                ['Pending Approvals', 'finance.approvals', 'finance.view', 'cash', ['finance.approvals*']],
                ['Financial Completeness', 'finance.completeness', 'finance.view', 'cash', ['finance.completeness*']],
                ['Account Statements', 'finance.statements', 'finance.view', 'cash', ['finance.statements*']],
                ['Historical Reconciliation', 'finance.historical', 'finance.view', 'check-circle', ['finance.historical*']],
                ['Loans Receivable', 'finance.loans-receivable', 'finance.view', 'cash', ['finance.loans-receivable*']],
                ['Payment Voids', 'finance.payment-voids', 'finance.view', 'check-circle', ['finance.payment-voids*']],
                ['Other Income', 'finance.other-income', 'finance.view', 'cash', ['finance.other-income*']],
                ['Periods & Profit', 'finance.periods', 'finance.view', 'invoice', ['finance.periods*', 'finance.allocations*']],
                ['Advances & Refunds', 'finance.revenue', 'finance.view', 'wallet', ['finance.revenue*']],
                ['Opening Balances', 'finance.opening', 'finance.view', 'layers', ['finance.opening*']],
            ],
            'Governance' => [
                ['Partners', 'finance.partners', 'finance.view', 'users', ['finance.partners*']],
                ['Policies', 'finance.policies', 'finance.view', 'layers', ['finance.policies*']],
                ['Board Resolutions', 'finance.resolutions', null, 'check-circle', ['finance.resolutions*']],
                ['Partner Payouts', 'finance.payouts', null, 'cash', ['finance.payouts*']],
                ['Partner Exits', 'finance.exits', 'finance.view', 'logout', ['finance.exits*']],
            ],
            'Reports' => [
                ['Financial Reports', 'finance.reports', 'finance.view', 'chart', ['finance.reports*']],
                ['Journal', 'finance.journal', 'finance.view', 'folder', ['finance.journal*']],
                ['Reconciliation', 'finance.reconciliation', 'finance.view', 'check-circle', ['finance.reconciliation*']],
                ['Audit Log', 'finance.audit', 'finance.view', 'eye', ['finance.audit*']],
            ],
            'Administration' => [
                ['Users & Roles', 'users.index', 'users.manage', 'users', ['users.*']],
                ['Business Settings', 'settings.edit', 'clienthub', 'settings', ['settings.*']],
            ],
        ];
    }

    /**
     * Only what this user may open, by group. Permissions are exactly the ones the sidebar always applied.
     *
     * @return Collection<string, Collection<int, array{0: string, 1: string, 2: ?string, 3: string, 4: list<string>}>>
     */
    public static function visible(User $user): Collection
    {
        $shared = $user->can('finance.view') || $user->can('own.view') || $user->can('resolution.create');

        return collect(self::groups())->map(fn (array $items) => collect($items)->filter(fn (array $i) => $i[2] === null ? $shared : $user->can($i[2]))->values())
            ->filter(fn (Collection $items) => $items->isNotEmpty());
    }
}
