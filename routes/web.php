<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\CapitalController;
use App\Http\Controllers\CashAllocationController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmergencyFundController;
use App\Http\Controllers\ExitController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpensePaymentController;
use App\Http\Controllers\FaviconController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinancialAccountController;
use App\Http\Controllers\FinancialCompletenessController;
use App\Http\Controllers\FinancialOverviewController;
use App\Http\Controllers\FinancialStatementsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryHandoverController;
use App\Http\Controllers\InventoryLossController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanReceivableController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpeningController;
use App\Http\Controllers\OrderCommissionController;
use App\Http\Controllers\OtherIncomeController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ProductOperationsController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectSourcingController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResolutionController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SourcingLotController;
use App\Http\Controllers\SourcingReportController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\SupplierAdvanceController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierProductController;
use App\Http\Controllers\SupplierReturnController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('brand/favicon', FaviconController::class)->name('favicon');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', HomeController::class)->name('dashboard');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/count', [NotificationController::class, 'count'])->name('notifications.count');
    Route::get('notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // Existing ClientHub screens: admin and Finance only.
    Route::middleware('can:clienthub')->group(function () {
        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/new', [ProjectController::class, 'start'])->name('projects.start');
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/new', [InvoiceController::class, 'start'])->name('invoices.start');
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('projects/{project}/payments/new', [PaymentController::class, 'chooseForProject'])->name('projects.payments.choose');

        Route::resource('clients', ClientController::class);
        Route::patch('clients/{client}/archive', [ClientController::class, 'toggleArchive'])->name('clients.archive');

        Route::resource('clients.projects', ProjectController::class)->shallow()->except('index');
        Route::patch('projects/{project}/archive', [ProjectController::class, 'toggleArchive'])->name('projects.archive');

        Route::resource('projects.segments', SegmentController::class)->shallow()->only(['store', 'edit', 'update', 'destroy']);

        Route::resource('projects.invoices', InvoiceController::class)->shallow()->except('index');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

        Route::get('segments/{segment}/payments/create', [PaymentController::class, 'createForSegment'])->name('segments.payments.create');
        Route::post('segments/{segment}/payments', [PaymentController::class, 'storeForSegment'])->name('segments.payments.store');
        Route::get('invoices/{invoice}/payments/create', [PaymentController::class, 'createForInvoice'])->name('invoices.payments.create');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'storeForInvoice'])->name('invoices.payments.store');
        Route::post('invoices/{invoice}/mark-paid', [PaymentController::class, 'markPaid'])->name('invoices.mark-paid');
        Route::get('payments/{payment}/edit', [PaymentController::class, 'edit'])->name('payments.edit');
        Route::put('payments/{payment}', [PaymentController::class, 'update'])->name('payments.update');
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // Suppliers & Sourcing. Every write route carries its own permission; reads need sourcing.view.
    Route::middleware('can:sourcing.view')->prefix('sourcing')->name('sourcing.')->group(function () {
        Route::get('/', [SourcingReportController::class, 'overview'])->name('overview');
        Route::get('reports', [SourcingReportController::class, 'reports'])->name('reports')->middleware('can:sourcing.reports');
        Route::get('payments', [SourcingReportController::class, 'payments'])->name('payments.index');
        Route::get('project-margins', [ProjectSourcingController::class, 'index'])->name('projects')->middleware('can:sourcing.reports');
        Route::get('projects/{project}', [ProjectSourcingController::class, 'show'])->name('projects.show')->middleware('can:sourcing.reports')->whereNumber('project');
        Route::get('documents/{document}', [SourcingLotController::class, 'downloadDocument'])->name('documents.download')->whereNumber('document');
        Route::post('lots/{lot}/procurement', [SourcingLotController::class, 'procurement'])->name('lots.procurement')->middleware('can:sourcing.manage');
        Route::post('lots/{lot}/details', [SourcingLotController::class, 'details'])->name('lots.details')->middleware('can:sourcing.manage');
        Route::post('costs/{cost}/service', [SourcingLotController::class, 'confirmService'])->name('costs.service')->middleware('can:sourcing.advance.approve');
        Route::post('lots/{lot}/documents', [SourcingLotController::class, 'uploadDocument'])->name('lots.documents.store')->middleware('can:sourcing.manage');
        Route::post('lines/{line}/arrival', [ProjectSourcingController::class, 'arrival'])->name('lines.arrival')->middleware('can:sourcing.manage');

        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create')->middleware('can:sourcing.manage');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store')->middleware('can:sourcing.manage');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit')->middleware('can:sourcing.manage');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update')->middleware('can:sourcing.manage');
        Route::patch('suppliers/{supplier}/archive', [SupplierController::class, 'toggleArchive'])->name('suppliers.archive')->middleware('can:sourcing.manage');

        Route::get('products', [SupplierProductController::class, 'index'])->name('products.index');
        Route::post('products', [SupplierProductController::class, 'store'])->name('products.store')->middleware('can:sourcing.manage');
        Route::get('products/{product}/edit', [SupplierProductController::class, 'edit'])->name('products.edit')->middleware('can:sourcing.manage');
        Route::put('products/{product}', [SupplierProductController::class, 'update'])->name('products.update')->middleware('can:sourcing.manage');

        Route::get('lots', [SourcingLotController::class, 'index'])->name('lots.index');
        Route::get('lots/create', [SourcingLotController::class, 'create'])->name('lots.create')->middleware('can:sourcing.manage');
        Route::post('lots', [SourcingLotController::class, 'store'])->name('lots.store')->middleware('can:sourcing.manage');
        Route::get('lots/{lot}', [SourcingLotController::class, 'show'])->name('lots.show');

        // The simple lot workflow: live numbers, a supplier's products, one-click client billing / receipt, one-step close.
        Route::get('suppliers/{supplier}/products.json', [SourcingLotController::class, 'supplierProducts'])->name('suppliers.products');
        Route::post('lots/preview', [SourcingLotController::class, 'preview'])->name('lots.preview')->middleware('can:sourcing.manage');
        Route::post('lots/{lot}/bill-client', [SourcingLotController::class, 'billClient'])->name('lots.bill-client')->middleware('can:sourcing.manage');
        Route::post('lots/{lot}/client-payment', [SourcingLotController::class, 'clientPayment'])->name('lots.client-payment')->middleware('can:clienthub');
        Route::post('lots/{lot}/close', [SourcingLotController::class, 'close'])->name('lots.close')->middleware('can:sourcing.settle');

        Route::middleware('can:sourcing.manage')->group(function () {
            Route::post('lots/{lot}/lines', [SourcingLotController::class, 'addLine'])->name('lots.lines.store');
            Route::put('lines/{line}', [SourcingLotController::class, 'updateLine'])->name('lines.update');
            Route::delete('lines/{line}', [SourcingLotController::class, 'removeLine'])->name('lines.destroy');
            Route::post('lots/{lot}/costs', [SourcingLotController::class, 'addCost'])->name('lots.costs.store');
            Route::delete('costs/{cost}', [SourcingLotController::class, 'removeCost'])->name('costs.destroy');
            Route::post('lots/{lot}/pricing', [SourcingLotController::class, 'savePricing'])->name('lots.pricing');
            Route::post('lots/{lot}/advance', [SourcingLotController::class, 'advance'])->name('lots.advance');
            Route::post('lots/{lot}/cancel', [SourcingLotController::class, 'cancel'])->name('lots.cancel');
            Route::post('lots/{lot}/allocations', [SourcingLotController::class, 'allocate'])->name('lots.allocations.store');
            Route::delete('allocations/{allocation}', [SourcingLotController::class, 'removeAllocation'])->name('allocations.destroy');
            Route::post('allocations/{allocation}/bill', [SourcingLotController::class, 'bill'])->name('allocations.bill');
        });
        Route::post('lots/{lot}/approve', [SourcingLotController::class, 'approve'])->name('lots.approve')->middleware('can:sourcing.price.approve');
        Route::post('lots/{lot}/settle', [SourcingLotController::class, 'settle'])->name('lots.settle')->middleware('can:sourcing.settle');
        Route::post('lots/{lot}/reopen', [SourcingLotController::class, 'reopen'])->name('lots.reopen')->middleware('can:sourcing.settle');

        Route::middleware('can:sourcing.payment.record')->group(function () {
            Route::post('lots/{lot}/payments', [SourcingLotController::class, 'recordPayment'])->name('lots.payments.store');
            Route::post('payments/{payment}/void', [SourcingLotController::class, 'voidPayment'])->name('payments.void');
            Route::post('costs/{cost}/pay', [SourcingLotController::class, 'payCost'])->name('costs.pay');
        });

        Route::get('advances', [SupplierAdvanceController::class, 'index'])->name('advances')->middleware('can:sourcing.reports');
        Route::post('lots/{lot}/advance-settlements', [SupplierAdvanceController::class, 'propose'])->name('advances.propose')->middleware('can:sourcing.manage');
        Route::post('advance-settlements/{settlement}/decide', [SupplierAdvanceController::class, 'decide'])->name('advances.decide')->middleware('can:sourcing.advance.approve');
        Route::post('advance-settlements/{settlement}/reverse', [SupplierAdvanceController::class, 'reverse'])->name('advances.reverse')->middleware('can:sourcing.advance.approve');
        Route::post('lots/{lot}/cost-recognition', [SupplierAdvanceController::class, 'proposeRecognition'])->name('cost-recognitions.propose')->middleware('can:sourcing.manage');
        Route::post('cost-recognitions/{recognition}/decide', [SupplierAdvanceController::class, 'decideRecognition'])->name('cost-recognitions.decide')->middleware('can:sourcing.advance.approve');
        Route::post('cost-recognitions/{recognition}/reverse', [SupplierAdvanceController::class, 'reverseRecognition'])->name('cost-recognitions.reverse')->middleware('can:sourcing.advance.approve');
        Route::post('lots/{lot}/fund', [EmergencyFundController::class, 'store'])->name('lots.fund.store')->middleware('can:sourcing.fund.request');
        Route::post('fund/{fundRequest}/decide', [EmergencyFundController::class, 'decide'])->name('fund.decide')->middleware('can:sourcing.fund.approve');
        Route::post('fund/{fundRequest}/utilise', [EmergencyFundController::class, 'utilise'])->name('fund.utilise')->middleware('can:sourcing.fund.utilize');
        Route::post('fund/{fundRequest}/reverse', [EmergencyFundController::class, 'reverse'])->name('fund.reverse')->middleware('can:sourcing.fund.utilize');
        Route::post('fund/{fundRequest}/cancel', [EmergencyFundController::class, 'cancel'])->name('fund.cancel')->middleware('can:sourcing.fund.request');

        // Client handover (transfer of control): the event that turns Sellbees-owned inventory into cost of goods sold. Reading needs inventory.view, a request
        // inventory.handover.propose, and a decision the Board's inventory.handover.approve (never the person who made the request).
        Route::get('handovers', [InventoryHandoverController::class, 'index'])->name('handovers')->middleware('can:inventory.view');
        Route::get('handovers/{handover}', [InventoryHandoverController::class, 'show'])->name('handovers.show')->middleware('can:inventory.view')->whereNumber('handover');
        Route::post('handovers', [InventoryHandoverController::class, 'store'])->name('handovers.store')->middleware('can:inventory.handover.propose');
        Route::post('handovers/{handover}/cancel', [InventoryHandoverController::class, 'cancel'])->name('handovers.cancel')->middleware('can:inventory.handover.propose');
        Route::post('handovers/{handover}/decide', [InventoryHandoverController::class, 'decide'])->name('handovers.decide')->middleware('can:inventory.handover.approve');
        Route::post('handovers/{handover}/returns', [InventoryHandoverController::class, 'storeReturn'])->name('handovers.returns.store')->middleware('can:inventory.handover.propose');
        Route::post('handover-returns/{handoverReturn}/cancel', [InventoryHandoverController::class, 'cancelReturn'])->name('handovers.returns.cancel')->middleware('can:inventory.handover.propose');
        Route::post('handover-returns/{handoverReturn}/decide', [InventoryHandoverController::class, 'decideReturn'])->name('handovers.returns.decide')->middleware('can:inventory.handover.approve');

        // Damage, impairment and write-off cases: Operations proposes (inventory.loss.propose), Finance reviews (inventory.loss.review), the Board decides (inventory.loss.approve).
        Route::get('losses', [InventoryLossController::class, 'index'])->name('losses')->middleware('can:inventory.view');
        Route::get('losses/{case}', [InventoryLossController::class, 'show'])->name('losses.show')->middleware('can:inventory.view')->whereNumber('case');
        Route::post('losses', [InventoryLossController::class, 'store'])->name('losses.store')->middleware('can:inventory.loss.propose');
        Route::post('losses/{case}/cancel', [InventoryLossController::class, 'cancel'])->name('losses.cancel')->middleware('can:inventory.loss.propose');
        Route::post('losses/{case}/review', [InventoryLossController::class, 'review'])->name('losses.review')->middleware('can:inventory.loss.review');
        Route::post('losses/{case}/decide', [InventoryLossController::class, 'decide'])->name('losses.decide')->middleware('can:inventory.loss.approve');

        // Supplier returns: Operations requests (supplier.return.propose), Finance records the supplier's answer, verifies and records settlements (supplier.return.review),
        // the Board approves (supplier.return.approve, never the requester or verifier).
        Route::get('supplier-returns', [SupplierReturnController::class, 'index'])->name('supplier-returns')->middleware('can:inventory.view');
        Route::get('supplier-returns/{supplierReturn}', [SupplierReturnController::class, 'show'])->name('supplier-returns.show')->middleware('can:inventory.view')->whereNumber('supplierReturn');
        Route::post('supplier-returns', [SupplierReturnController::class, 'store'])->name('supplier-returns.store')->middleware('can:supplier.return.propose');
        Route::post('supplier-returns/{supplierReturn}/cancel', [SupplierReturnController::class, 'cancel'])->name('supplier-returns.cancel')->middleware('can:supplier.return.propose');
        Route::post('supplier-returns/{supplierReturn}/accept', [SupplierReturnController::class, 'accept'])->name('supplier-returns.accept')->middleware('can:supplier.return.review');
        Route::post('supplier-returns/{supplierReturn}/verify', [SupplierReturnController::class, 'verify'])->name('supplier-returns.verify')->middleware('can:supplier.return.review');
        Route::post('supplier-returns/{supplierReturn}/decide', [SupplierReturnController::class, 'decide'])->name('supplier-returns.decide')->middleware('can:supplier.return.approve');
        Route::post('supplier-returns/{supplierReturn}/link', [SupplierReturnController::class, 'link'])->name('supplier-returns.link')->middleware('can:supplier.return.review');
        Route::post('supplier-returns/{supplierReturn}/settle', [SupplierReturnController::class, 'settle'])->name('supplier-returns.settle')->middleware('can:supplier.return.review');
        Route::post('supplier-return-settlements/{settlement}/decide', [SupplierReturnController::class, 'decideSettlement'])->name('supplier-returns.settlements.decide')->middleware('can:supplier.return.approve');
    });

    // Product operations: inventory, orders & delivery, client settlements. Reads need inventory.view / settlement.view; each write has its own permission.
    Route::middleware('can:inventory.view')->prefix('product')->name('product.')->group(function () {
        Route::get('inventory', [ProductOperationsController::class, 'inventory'])->name('inventory');
        Route::get('inventory/{line}', [ProductOperationsController::class, 'inventoryShow'])->name('inventory.show')->whereNumber('line');
        Route::post('inventory/{line}/adjust', [ProductOperationsController::class, 'adjust'])->name('inventory.adjust')->middleware('can:inventory.adjust')->whereNumber('line');
        Route::post('lots/{lot}/ownership', [ProductOperationsController::class, 'ownership'])->name('lots.ownership')->middleware('can:sourcing.manage');
        Route::post('lots/{lot}/area', [ProductOperationsController::class, 'area'])->name('lots.area')->middleware('can:sourcing.manage');

        Route::get('orders', [ProductOperationsController::class, 'orders'])->name('orders');
        Route::middleware('can:orders.manage')->group(function () {
            Route::post('orders', [ProductOperationsController::class, 'orderStore'])->name('orders.store');
            Route::post('orders/{order}/confirm', [ProductOperationsController::class, 'orderConfirm'])->name('orders.confirm');
            Route::post('orders/{order}/dispatch', [ProductOperationsController::class, 'orderDispatch'])->name('orders.dispatch');
            Route::post('orders/{order}/deliver', [ProductOperationsController::class, 'orderDeliver'])->name('orders.deliver');
            Route::post('orders/{order}/fail', [ProductOperationsController::class, 'orderFail'])->name('orders.fail');
            Route::post('orders/{order}/cancel', [ProductOperationsController::class, 'orderCancel'])->name('orders.cancel');
            Route::post('orders/{order}/return', [ProductOperationsController::class, 'orderReturn'])->name('orders.return');
            Route::post('order-items/{item}/inspect', [ProductOperationsController::class, 'itemInspect'])->name('items.inspect');
        });
        Route::post('orders/{order}/collect', [ProductOperationsController::class, 'orderCollect'])->name('orders.collect')->middleware('can:collection.record');

        Route::middleware('can:settlement.view')->group(function () {
            Route::get('settlements', [ProductOperationsController::class, 'settlements'])->name('settlements');
            Route::get('settlements/{client}', [ProductOperationsController::class, 'settlementShow'])->name('settlements.show');
        });
        Route::post('settlements/{client}', [ProductOperationsController::class, 'settlementStore'])->name('settlements.store')->middleware('can:settlement.record');
    });

    Route::middleware('can:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    });

    Route::prefix('finance')->name('finance.')->group(function () {
        // Own records only (partner scope is enforced by the logged-in user's partner_id).
        Route::get('me', [FinanceController::class, 'me'])->name('me')->middleware('can:own.view');
        Route::get('payouts', [PayoutController::class, 'index'])->name('payouts');
        Route::post('payouts', [PayoutController::class, 'store'])->name('payouts.store')->middleware('can:payout.request');
        Route::get('resolutions', [ResolutionController::class, 'index'])->name('resolutions');
        Route::post('resolutions', [ResolutionController::class, 'store'])->name('resolutions.store')->middleware('can:resolution.create');

        Route::post('payouts/{payout}/decide', [PayoutController::class, 'decide'])->name('payouts.decide')->middleware('can:payout.approve');
        Route::post('payouts/{payout}/pay', [PayoutController::class, 'pay'])->name('payouts.pay')->middleware('can:payout.pay');
        Route::post('resolutions/{resolution}/decide', [ResolutionController::class, 'decide'])->name('resolutions.decide')->middleware('can:resolution.decide');
        Route::post('resolutions/{resolution}/pay', [ResolutionController::class, 'pay'])->name('resolutions.pay')->middleware('can:resolution.pay');

        Route::middleware('can:finance.view')->group(function () {
            Route::get('/', [FinanceController::class, 'dashboard'])->name('dashboard');
            Route::get('partners', [FinanceController::class, 'partners'])->name('partners');
            Route::get('partners/{partner}', [PartnerController::class, 'show'])->name('partners.show');
            Route::get('journal', [FinanceController::class, 'journal'])->name('journal');
            Route::get('journal.csv', [FinanceController::class, 'journalCsv'])->name('journal.csv');
            Route::get('audit', [FinanceController::class, 'audit'])->name('audit');
            Route::get('policies', [PolicyController::class, 'index'])->name('policies');
            Route::get('capital', [CapitalController::class, 'index'])->name('capital');
            Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses');
            Route::get('periods', [PeriodController::class, 'index'])->name('periods');
            Route::get('periods/{period}', [PeriodController::class, 'show'])->name('periods.show');
            Route::get('exits', [ExitController::class, 'index'])->name('exits');
            Route::get('revenue', [RevenueController::class, 'index'])->name('revenue');
            Route::get('opening', [OpeningController::class, 'index'])->name('opening');
            Route::get('reports', [ReportController::class, 'index'])->name('reports');
            Route::get('investment', [FinancialStatementsController::class, 'investment'])->name('investment');
            Route::get('position', [FinancialStatementsController::class, 'position'])->name('position');
            Route::get('profit-loss', [FinancialStatementsController::class, 'profitLoss'])->name('pnl');
            Route::get('automation', [AutomationController::class, 'index'])->name('automation');
            Route::get('overview', [FinancialOverviewController::class, 'index'])->name('overview');
            Route::get('accounts', [FinancialAccountController::class, 'index'])->name('accounts');
            Route::get('accounts/unassigned', [FinancialAccountController::class, 'unassigned'])->name('accounts.unassigned');
            Route::get('accounts/{account}', [FinancialAccountController::class, 'show'])->name('accounts.show')->whereNumber('account');
            Route::get('cost-allocation', [ExpenseController::class, 'costAllocation'])->name('cost-allocation');
            Route::get('expense-payments', [ExpensePaymentController::class, 'index'])->name('expense-payments');
            Route::get('cash-allocation', [CashAllocationController::class, 'index'])->name('cash-allocation');
            Route::get('loans', [LoanController::class, 'index'])->name('loans');
            Route::get('loans-receivable', [LoanReceivableController::class, 'index'])->name('loans-receivable');
            Route::get('statements', [StatementController::class, 'index'])->name('statements');
            Route::get('completeness', [FinancialCompletenessController::class, 'index'])->name('completeness');
            Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals');
            Route::get('other-income', [OtherIncomeController::class, 'index'])->name('other-income');
            Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation');
            Route::get('reconciliation.csv', [ReconciliationController::class, 'csv'])->name('reconciliation.csv');
            Route::get('documents/{document}', [DocumentController::class, 'download'])->name('documents.download');
        });

        Route::get('orders', [OrderCommissionController::class, 'index'])->name('orders')->middleware('can:commission.view');
        Route::middleware('can:commission.manage')->group(function () {
            Route::post('orders', [OrderCommissionController::class, 'store'])->name('orders.store');
            Route::post('orders/{order}/success', [OrderCommissionController::class, 'success'])->name('orders.success');
            Route::post('orders/{order}/cancel', [OrderCommissionController::class, 'cancel'])->name('orders.cancel');
            Route::post('orders/{order}/adjust', [OrderCommissionController::class, 'adjust'])->name('orders.adjust');
            Route::post('orders/{order}/bill', [OrderCommissionController::class, 'bill'])->name('orders.bill');
            Route::post('orders/{order}/reduce-invoiced', [OrderCommissionController::class, 'reduceInvoiced'])->name('orders.reduce');
        });
        Route::post('commission-rates', [OrderCommissionController::class, 'rates'])->name('orders.rates')->middleware('can:commission.rates');
        Route::post('storage-settings', [OrderCommissionController::class, 'storageSettings'])->name('orders.storage')->middleware('can:commission.rates');
        Route::post('automation/run', [AutomationController::class, 'run'])->name('automation.run')->middleware('can:period.close');
        Route::post('automation/revoke/{key}', [AutomationController::class, 'revoke'])->name('automation.revoke')->middleware('can:resolution.decide');

        Route::post('policies', [PolicyController::class, 'store'])->name('policies.store')->middleware('can:policy.create');
        Route::post('policies/{policy}/approve', [PolicyController::class, 'approve'])->name('policies.approve')->middleware('can:policy.approve');
        Route::post('policies/{policy}/confirm-profit', [PolicyController::class, 'confirmProfit'])->name('policies.confirm-profit')->middleware('can:policy.approve');

        Route::post('capital', [CapitalController::class, 'store'])->name('capital.store')->middleware('can:capital.record');
        Route::post('capital/{capital}/decide', [CapitalController::class, 'decide'])->name('capital.decide')->middleware('can:capital.verify');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store')->middleware('can:expense.record');
        Route::post('expenses/{expense}/decide', [ExpenseController::class, 'decide'])->name('expenses.decide')->middleware('can:expense.verify');
        Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay')->middleware('can:expense.pay');
        Route::post('expense-payments/{expensePayment}/void', [ExpenseController::class, 'voidPayment'])->name('expenses.payments.void')->middleware('can:expense.pay');
        Route::post('expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])->name('expenses.reverse')->middleware('can:expense.record');
        Route::post('expenses/{expense}/allocate', [ExpenseController::class, 'allocate'])->name('expenses.allocate')->middleware('can:expense.record');
        Route::post('capital/{capital}/reverse', [CapitalController::class, 'reverse'])->name('capital.reverse')->middleware('can:capital.verify');
        Route::post('accounts', [FinancialAccountController::class, 'store'])->name('accounts.store')->middleware('can:account.manage');
        Route::put('accounts/{account}', [FinancialAccountController::class, 'update'])->name('accounts.update')->middleware('can:account.manage');
        Route::post('cash-allocation', [CashAllocationController::class, 'prepare'])->name('cash-allocation.prepare')->middleware('can:account.manage');
        Route::post('cash-allocation/{batch}/approve', [CashAllocationController::class, 'approve'])->name('cash-allocation.approve')->middleware('can:cashalloc.approve');
        Route::post('cash-allocation/{batch}/cancel', [CashAllocationController::class, 'cancel'])->name('cash-allocation.cancel')->middleware('can:account.manage');
        Route::post('loans', [LoanController::class, 'store'])->name('loans.store')->middleware('can:loan.record');
        Route::post('loans/{loan}/entries', [LoanController::class, 'entry'])->name('loans.entries.store')->middleware('can:loan.record');
        Route::post('loans/{loan}/interest', [LoanController::class, 'interest'])->name('loans.interest')->middleware('can:loan.record');
        Route::post('loan-entries/{entry}/decide', [LoanController::class, 'decide'])->name('loans.entries.decide')->middleware('can:loan.verify');
        Route::post('loan-entries/{entry}/reverse', [LoanController::class, 'reverse'])->name('loans.entries.reverse')->middleware('can:loan.verify');
        Route::post('completeness/payments/{payment}/classify', [FinancialCompletenessController::class, 'classify'])->name('completeness.classify')->middleware('can:reconcile.review');
        Route::post('statements', [StatementController::class, 'store'])->name('statements.store')->middleware('can:account.manage');
        Route::post('statements/{statement}/decide', [StatementController::class, 'decide'])->name('statements.decide')->middleware('can:reconcile.review');
        Route::post('loans-receivable', [LoanReceivableController::class, 'store'])->name('loans-receivable.store')->middleware('can:loan.record');
        Route::post('loans-receivable/{loan}/decide', [LoanReceivableController::class, 'decide'])->name('loans-receivable.decide')->middleware('can:loan.verify');
        Route::post('loans-receivable/{loan}/reverse', [LoanReceivableController::class, 'reverse'])->name('loans-receivable.reverse')->middleware('can:loan.verify');
        Route::post('loans-receivable/{loan}/repayments', [LoanReceivableController::class, 'repay'])->name('loans-receivable.repay')->middleware('can:loan.record');
        Route::post('loan-receivable-repayments/{repayment}/decide', [LoanReceivableController::class, 'decideRepayment'])->name('loans-receivable.repayments.decide')->middleware('can:loan.verify');
        Route::post('loan-receivable-repayments/{repayment}/reverse', [LoanReceivableController::class, 'reverseRepayment'])->name('loans-receivable.repayments.reverse')->middleware('can:loan.verify');
        Route::post('other-income', [OtherIncomeController::class, 'store'])->name('other-income.store')->middleware('can:income.record');
        Route::post('other-income/{income}/decide', [OtherIncomeController::class, 'decide'])->name('other-income.decide')->middleware('can:income.verify');
        Route::post('other-income/{income}/reverse', [OtherIncomeController::class, 'reverse'])->name('other-income.reverse')->middleware('can:income.verify');
        Route::post('reconciliation/review', [ReconciliationController::class, 'review'])->name('reconciliation.review')->middleware('can:reconcile.review');
        Route::post('transfers', [FinancialAccountController::class, 'transfer'])->name('transfers.store')->middleware('can:transfer.record');
        Route::post('transfers/{transfer}/void', [FinancialAccountController::class, 'voidTransfer'])->name('transfers.void')->middleware('can:transfer.void');

        Route::post('periods', [PeriodController::class, 'close'])->name('periods.close')->middleware('can:period.close');
        Route::post('periods/{period}/verify', [PeriodController::class, 'verify'])->name('periods.verify')->middleware('can:period.verify');
        Route::post('periods/{period}/prepare', [PeriodController::class, 'prepare'])->name('periods.prepare')->middleware('can:allocation.prepare');
        Route::post('allocations/{allocation}/approve', [PeriodController::class, 'approve'])->name('allocations.approve')->middleware('can:allocation.approve');
        Route::post('allocations/{allocation}/post', [PeriodController::class, 'post'])->name('allocations.post')->middleware('can:allocation.post');

        Route::post('exits', [ExitController::class, 'store'])->name('exits.store')->middleware('can:exit.prepare');
        Route::post('exits/{exit}/approve', [ExitController::class, 'approve'])->name('exits.approve')->middleware('can:exit.approve');
        Route::post('exits/{exit}/pay', [ExitController::class, 'pay'])->name('exits.pay')->middleware('can:exit.pay');
        Route::post('exits/{exit}/cancel', [ExitController::class, 'cancel'])->name('exits.cancel')->middleware('can:exit.prepare');
        Route::post('revenue/{payment}', [RevenueController::class, 'store'])->name('revenue.store')->middleware('can:revenue.record');
        Route::post('revenue-adjustments/{adjustment}/decide', [RevenueController::class, 'decide'])->name('revenue.decide')->middleware('can:expense.verify');
        Route::post('opening', [OpeningController::class, 'store'])->name('opening.store')->middleware('can:opening.prepare');
        Route::post('opening/{batch}/lines', [OpeningController::class, 'addLine'])->name('opening.lines')->middleware('can:opening.prepare');
        Route::post('opening/{batch}/approve', [OpeningController::class, 'approve'])->name('opening.approve')->middleware('can:opening.approve');
        Route::post('opening/{batch}/post', [OpeningController::class, 'post'])->name('opening.post')->middleware('can:opening.prepare');
        Route::post('opening/{batch}/cancel', [OpeningController::class, 'cancel'])->name('opening.cancel')->middleware('can:opening.prepare');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store')->middleware('can:document.upload');
        Route::post('documents/{document}/review', [DocumentController::class, 'review'])->name('documents.review');
        Route::post('partners', [PartnerController::class, 'store'])->name('partners.store')->middleware('can:partner.manage');
        Route::put('partners/{partner}', [PartnerController::class, 'update'])->name('partners.update')->middleware('can:partner.manage');
        Route::get('partners/{partner}/edit', [PartnerController::class, 'edit'])->name('partners.edit')->middleware('can:partner.manage');
    });
});
