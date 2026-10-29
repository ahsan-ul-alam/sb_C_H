<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FaviconController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/cc', function () {
    Artisan::call('storage:link');
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('optimize:clear');

    return 'Storage linked, migrations completed, caches cleared.';
});

Route::get('brand/favicon', FaviconController::class)->name('favicon');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

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
