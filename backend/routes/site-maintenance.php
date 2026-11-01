<?php

use App\Http\Controllers\AdminUpdateController;
use App\Http\Controllers\SiteSetupController;
use App\Http\Controllers\SiteDatabaseController;
use Illuminate\Support\Facades\Route;

// Web group (session + CSRF) is supplied by bootstrap/app.php.
Route::get('/setup', [SiteSetupController::class, 'show'])->name('site.setup');
Route::post('/setup/unlock', [SiteSetupController::class, 'unlock'])
    ->middleware('throttle:site-setup-unlock')->name('site.setup.unlock');
Route::post('/setup/configure', [SiteDatabaseController::class, 'store'])
    ->middleware('throttle:site-setup-actions')->name('site.setup.configure');
Route::post('/setup/database', [SiteSetupController::class, 'database'])
    ->middleware('throttle:site-setup-actions')->name('site.setup.database');
Route::post('/setup/finish', [SiteSetupController::class, 'finish'])
    ->middleware('throttle:site-setup-actions')->name('site.setup.finish');

Route::middleware('auth:web')->group(function (): void {
    Route::get('/admin/updates', [AdminUpdateController::class, 'index'])
        ->name('admin.updates.index');
    Route::post('/admin/updates/apply', [AdminUpdateController::class, 'apply'])
        ->middleware('throttle:2,1')->name('admin.updates.apply');
});
