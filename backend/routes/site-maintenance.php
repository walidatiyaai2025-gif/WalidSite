<?php

use App\Http\Controllers\AdminUpdateController;
use App\Http\Controllers\SiteSetupController;
use Illuminate\Support\Facades\Route;

// Web group (session + CSRF) is supplied by bootstrap/app.php.
Route::get('/setup', [SiteSetupController::class, 'show'])->name('site.setup');
Route::post('/setup/unlock', [SiteSetupController::class, 'unlock'])
    ->middleware('throttle:5,1')->name('site.setup.unlock');
Route::post('/setup/database', [SiteSetupController::class, 'database'])
    ->middleware('throttle:3,1')->name('site.setup.database');
Route::post('/setup/finish', [SiteSetupController::class, 'finish'])
    ->middleware('throttle:3,1')->name('site.setup.finish');

Route::middleware('auth:web')->group(function (): void {
    Route::get('/admin/updates', [AdminUpdateController::class, 'index'])
        ->name('admin.updates.index');
    Route::post('/admin/updates/apply', [AdminUpdateController::class, 'apply'])
        ->middleware('throttle:2,1')->name('admin.updates.apply');
});
