<?php

use App\Http\Controllers\AdminUpdateController;
use App\Http\Controllers\LatestVerifiedAndroidController;
use App\Http\Controllers\SiteSetupController;
use App\Http\Controllers\FoodexInstallController;
use App\Http\Controllers\SiteDatabaseController;
use App\Http\Middleware\RequireSecureSetup;
use Illuminate\Support\Facades\Route;

// Web group (session + CSRF) is supplied by bootstrap/app.php.

// Public permanent URL for the LAST post-deployment verified, compatible
// production-signed APK. Returns 503 without the host's PRIVATE signed
// Web↔APK pairing and byte-identical artifact. Never links to debug QA.
Route::get('/downloads/android/latest', [LatestVerifiedAndroidController::class, 'download'])
    ->middleware('throttle:3,1')->name('public.android.latest');
Route::get('/downloads/android/latest/metadata', [LatestVerifiedAndroidController::class, 'metadata'])
    ->middleware('throttle:20,1')->name('public.android.latest.metadata');

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
    Route::post('/admin/updates/fetch-signed', [AdminUpdateController::class, 'downloadSigned'])
        ->middleware('throttle:1,5')->name('admin.updates.fetch-signed');
    Route::post('/admin/updates/preflight', [AdminUpdateController::class, 'preflight'])
        ->middleware('throttle:2,1')->name('admin.updates.preflight');
    Route::post('/admin/updates/stage', [AdminUpdateController::class, 'stage'])
        ->middleware('throttle:1,1')->name('admin.updates.stage');
    Route::post('/admin/updates/install-signed', [AdminUpdateController::class, 'installSigned'])
        ->middleware('throttle:1,10')->name('admin.updates.install-signed');
    Route::post('/admin/updates/backup-now', [AdminUpdateController::class, 'backupNow'])
        ->middleware('throttle:1,5')->name('admin.updates.backup-now');
    Route::post('/admin/updates/apply', [AdminUpdateController::class, 'apply'])
        ->middleware('throttle:2,1')->name('admin.updates.apply');
});
