<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PackagedProductionController;
use App\Http\Controllers\PackagedReportController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\ProductForecastController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\TechnicalEventController;
use App\Http\Controllers\UserManagementController;
use App\Http\Middleware\DailyAlertScan;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
});

Route::get('/forgot-password', [PasswordRecoveryController::class, 'requestForm'])->name('password.request');
Route::post('/forgot-password', [PasswordRecoveryController::class, 'sendCode'])->name('password.email_code');
Route::get('/reset-password', [PasswordRecoveryController::class, 'resetForm'])->name('password.reset_form');
Route::post('/reset-password', [PasswordRecoveryController::class, 'reset'])->name('password.reset_code');

Route::get('/security-code', [AuthController::class, 'securityForm'])->name('security.form');
Route::post('/security-code', [AuthController::class, 'verify'])->name('security.verify');
Route::post('/security-code/resend', [AuthController::class, 'resend'])->name('security.resend');

Route::middleware(['auth', 'active', DailyAlertScan::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::middleware('role:administrator')->group(function () {
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/email', [ProfileController::class, 'requestEmailChange'])->name('profile.email.request');
        Route::post('/profile/email/verify', [ProfileController::class, 'verifyEmailChange'])->name('profile.email.verify');
        Route::delete('/profile/email', [ProfileController::class, 'cancelEmailChange'])->name('profile.email.cancel');
    });
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{alert}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/{alert}/open', [NotificationController::class, 'open'])->name('notifications.open');

    Route::middleware('role:administrator,manager,production_staff,inventory_staff')->group(function () {
        Route::get('/packaged/batches', [PackagedProductionController::class, 'index'])->name('packaged.batches.index');
        Route::get('/packaged/batches/{batch}', [PackagedProductionController::class, 'show'])->whereNumber('batch')->name('packaged.batches.show');
    });
    Route::middleware('role:administrator,manager,production_staff')->group(function () {
        Route::get('/packaged/batches/create', [PackagedProductionController::class, 'create'])->name('packaged.batches.create');
        Route::post('/packaged/batches', [PackagedProductionController::class, 'store'])->name('packaged.batches.store');
    });
    Route::middleware('role:administrator,manager')->group(function () {
        Route::post('/packaged/batches/{batch}/release', [PackagedProductionController::class, 'release'])->name('packaged.batches.release');
        Route::post('/packaged/batches/{batch}/quarantine', [PackagedProductionController::class, 'quarantine'])->name('packaged.batches.quarantine');
        Route::post('/packaged/batches/{batch}/dispose', [PackagedProductionController::class, 'dispose'])->name('packaged.batches.dispose');
        Route::post('/packaged/batches/{batch}/recall', [PackagedProductionController::class, 'recall'])->name('packaged.batches.recall');
        Route::post('/packaged/products/{product}/price', [PackagedProductionController::class, 'updatePrice'])->name('packaged.products.price');
    });
    Route::middleware('role:administrator,manager,sales_staff,distribution_staff')->group(function () {
        Route::get('/packaged/orders', [SalesOrderController::class, 'index'])->name('packaged.orders.index');
        Route::get('/packaged/orders/{order}', [SalesOrderController::class, 'show'])->whereNumber('order')->name('packaged.orders.show');
    });
    Route::middleware('role:administrator,manager,sales_staff')->group(function () {
        Route::get('/packaged/orders/create', [SalesOrderController::class, 'create'])->name('packaged.orders.create');
        Route::post('/packaged/orders', [SalesOrderController::class, 'store'])->name('packaged.orders.store');
        Route::post('/packaged/orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->name('packaged.orders.confirm');
        Route::post('/packaged/orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->name('packaged.orders.cancel');
        Route::post('/packaged/orders/{order}/invoice', [SalesOrderController::class, 'invoice'])->name('packaged.orders.invoice');
        Route::post('/packaged/invoices/{invoice}/payments', [SalesOrderController::class, 'payment'])->name('packaged.invoices.payment');
        Route::post('/packaged/invoices/{invoice}/returns', [SalesOrderController::class, 'recordReturn'])->name('packaged.invoices.return');
    });
    Route::middleware('role:administrator,manager,distribution_staff')->group(function () {
        Route::post('/packaged/orders/{order}/dispatch', [SalesOrderController::class, 'dispatch'])->name('packaged.orders.dispatch');
        Route::post('/packaged/orders/{order}/deliver', [SalesOrderController::class, 'deliver'])->name('packaged.orders.deliver');
    });
    Route::middleware('role:administrator,manager,auditor')->group(function () {
        Route::get('/packaged/reports', [PackagedReportController::class, 'index'])->name('packaged.reports.index');
        Route::get('/packaged/forecasts', [ProductForecastController::class, 'index'])->name('packaged.forecasts.index');
    });
    Route::middleware('role:administrator,manager')->group(function () {
        Route::post('/packaged/forecasts/run', [ProductForecastController::class, 'run'])->name('packaged.forecasts.run');
        Route::post('/packaged/forecasts/{forecast}/decision', [ProductForecastController::class, 'decision'])->name('packaged.forecasts.decision');
        Route::post('/packaged/expenses', [PackagedReportController::class, 'expense'])->name('packaged.expenses.store');
    });
    Route::middleware('role:administrator,auditor')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    });

    Route::middleware('role:administrator')->group(function () {
        Route::resource('users', UserManagementController::class)->except('show');
        Route::get('/technical-events', [TechnicalEventController::class, 'index'])->name('technical-events.index');
    });

    Route::middleware('role:administrator,manager')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    });

    Route::get('/production', [OperationsController::class, 'index'])->defaults('type', 'production')->name('production.index');
    Route::get('/inventory', [OperationsController::class, 'index'])->defaults('type', 'inventory')->name('inventory.index');
    Route::get('/distribution', [OperationsController::class, 'index'])->defaults('type', 'distribution')->name('distribution.index');
    Route::get('/sales', [OperationsController::class, 'index'])->defaults('type', 'sales')->name('sales.index');
    Route::get('/customers', [OperationsController::class, 'index'])->defaults('type', 'customers')->name('customers.index');

    Route::get('/{type}/create', [OperationsController::class, 'create'])
        ->whereIn('type', ['customers'])->name('operations.create');
    Route::post('/{type}', [OperationsController::class, 'store'])
        ->whereIn('type', ['customers'])->name('operations.store');
});
