<?php

use App\Http\Controllers\AdminUpdateController;
use App\Http\Controllers\SiteSetupController;
use App\Http\Controllers\FoodexInstallController;
use App\Http\Controllers\SiteDatabaseController;
use App\Http\Middleware\RequireSecureSetup;
use Illuminate\Support\Facades\Route;

// Web group (session + CSRF) is supplied by bootstrap/app.php.
// Reject all first-run HTTP requests in production before any secrets are read.
Route::middleware(RequireSecureSetup::class)->group(static function (): void {
    // FOODEX-style fifteen-stage new installation. The established /setup
    // workflow remains available for backward-compatible interrupted installs.
    Route::get('/install', [FoodexInstallController::class, 'show'])->name('site.install');
    Route::post('/install/step/{step}', [FoodexInstallController::class, 'next'])
        ->whereNumber('step')->middleware('throttle:site-setup-actions')->name('site.install.next');

    Route::get('/setup', [SiteSetupController::class, 'show'])->name('site.setup');
    Route::post('/setup/unlock', [SiteSetupController::class, 'unlock'])
        ->middleware('throttle:site-setup-unlock')->name('site.setup.unlock');
    Route::post('/setup/configure', [SiteDatabaseController::class, 'store'])
        ->middleware('throttle:site-setup-actions')->name('site.setup.configure');
    Route::post('/setup/database', [SiteSetupController::class, 'database'])
        ->middleware('throttle:site-setup-actions')->name('site.setup.database');
    Route::post('/setup/finish', [SiteSetupController::class, 'finish'])
        ->middleware('throttle:site-setup-actions')->name('site.setup.finish');

});

Route::middleware('auth:web')->group(function (): void {
    Route::get('/admin/updates', [AdminUpdateController::class, 'index'])
        ->name('admin.updates.index');
    Route::post('/admin/updates/check', [AdminUpdateController::class, 'check'])
        ->middleware('throttle:3,1')->name('admin.updates.check');
    Route::post('/admin/updates/verify-upload', [AdminUpdateController::class, 'verifyUpload'])
        ->middleware('throttle:2,1')->name('admin.updates.verify-upload');
    Route::post('/admin/updates/preflight', [AdminUpdateController::class, 'preflight'])
        ->middleware('throttle:2,1')->name('admin.updates.preflight');
    Route::post('/admin/updates/apply', [AdminUpdateController::class, 'apply'])
        ->middleware('throttle:2,1')->name('admin.updates.apply');
});
