<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
});

Route::get('/security-code', [AuthController::class, 'securityForm'])->name('security.form');
Route::post('/security-code', [AuthController::class, 'verify'])->name('security.verify');
Route::post('/security-code/resend', [AuthController::class, 'resend'])->name('security.resend');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:administrator')->group(function () {
        Route::resource('users', UserManagementController::class)->except('show');
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
        ->whereIn('type', ['production', 'distribution', 'sales', 'customers'])->name('operations.create');
    Route::post('/{type}', [OperationsController::class, 'store'])
        ->whereIn('type', ['production', 'distribution', 'sales', 'customers'])->name('operations.store');
    Route::post('/inventory/adjust', [OperationsController::class, 'adjust'])
        ->middleware('role:administrator,manager,inventory_staff')->name('inventory.adjust');
});
