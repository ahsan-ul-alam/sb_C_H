<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\CapitalController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmergencyFundController;
use App\Http\Controllers\ExitController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FaviconController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinancialStatementsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpeningController;
use App\Http\Controllers\OrderCommissionController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResolutionController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SourcingLotController;
use App\Http\Controllers\SourcingReportController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierProductController;
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

        Route::post('lots/{lot}/fund', [EmergencyFundController::class, 'store'])->name('lots.fund.store')->middleware('can:sourcing.fund.request');
        Route::post('fund/{fundRequest}/decide', [EmergencyFundController::class, 'decide'])->name('fund.decide')->middleware('can:sourcing.fund.approve');
        Route::post('fund/{fundRequest}/utilise', [EmergencyFundController::class, 'utilise'])->name('fund.utilise')->middleware('can:sourcing.fund.utilize');
        Route::post('fund/{fundRequest}/reverse', [EmergencyFundController::class, 'reverse'])->name('fund.reverse')->middleware('can:sourcing.fund.utilize');
        Route::post('fund/{fundRequest}/cancel', [EmergencyFundController::class, 'cancel'])->name('fund.cancel')->middleware('can:sourcing.fund.request');
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
        Route::post('automation/run', [AutomationController::class, 'run'])->name('automation.run')->middleware('can:period.close');
        Route::post('automation/revoke/{key}', [AutomationController::class, 'revoke'])->name('automation.revoke')->middleware('can:resolution.decide');

        Route::post('policies', [PolicyController::class, 'store'])->name('policies.store')->middleware('can:policy.create');
        Route::post('policies/{policy}/approve', [PolicyController::class, 'approve'])->name('policies.approve')->middleware('can:policy.approve');
        Route::post('policies/{policy}/confirm-profit', [PolicyController::class, 'confirmProfit'])->name('policies.confirm-profit')->middleware('can:policy.approve');

        Route::post('capital', [CapitalController::class, 'store'])->name('capital.store')->middleware('can:capital.record');
        Route::post('capital/{capital}/decide', [CapitalController::class, 'decide'])->name('capital.decide')->middleware('can:capital.verify');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store')->middleware('can:expense.record');
        Route::post('expenses/{expense}/decide', [ExpenseController::class, 'decide'])->name('expenses.decide')->middleware('can:expense.verify');

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
