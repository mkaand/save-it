<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');

    Route::middleware(['admin', 'auth.session'])->group(function (): void {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/analytics', AnalyticsController::class)->name('analytics');
        Route::get('/operations', [OperationsController::class, 'index'])->name('operations');
        Route::post('/operations/cleanup', [OperationsController::class, 'cleanup'])->name('operations.cleanup');
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
        Route::put('/reports', [ReportsController::class, 'update'])->name('reports.update');
        Route::post('/reports/send', [ReportsController::class, 'send'])->name('reports.send');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
        Route::get('/settings/providers', [SettingsController::class, 'providers'])->name('providers');
        Route::put('/settings/providers', [SettingsController::class, 'updateProviders'])->name('providers.update');
        Route::get('/settings/email', [SettingsController::class, 'email'])->name('email');
        Route::put('/settings/email', [SettingsController::class, 'updateEmail'])->name('email.update');
        Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])->name('email.test');
        Route::get('/settings/security', [SettingsController::class, 'security'])->name('security');
        Route::put('/settings/security', [SettingsController::class, 'updateSecurity'])->name('security.update');
        Route::delete('/settings/security/rate-limits', [SettingsController::class, 'resetLimits'])->name('security.reset-limits');
        Route::post('/settings/security/turnstile/test', [SettingsController::class, 'testTurnstile'])->name('security.test-turnstile');
        Route::get('/settings/geoip', [SettingsController::class, 'geoip'])->name('geoip');
        Route::put('/settings/geoip', [SettingsController::class, 'updateGeoip'])->name('geoip.update');
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    });
});
