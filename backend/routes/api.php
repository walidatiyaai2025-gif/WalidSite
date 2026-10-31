<?php

use App\Http\Controllers\Api\IdentityController;
use App\Http\Controllers\Api\PublicPortfolioController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [IdentityController::class, 'login'])
    ->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/mobile/me', [IdentityController::class, 'me']);
    Route::post('/auth/logout', [IdentityController::class, 'logout']);
    Route::get('/admin/identity-check', [IdentityController::class, 'adminCheck']);
});

Route::get('/public/projects', [PublicPortfolioController::class, 'projects'])
    ->name('api.public.projects')->middleware('throttle:60,1');
Route::get('/public/projects/{slug}', [PublicPortfolioController::class, 'project'])
    ->where('slug', '[a-zA-Z0-9-]+')->name('api.public.project')->middleware('throttle:60,1');
Route::get('/public/profile', [PublicPortfolioController::class, 'profile'])
    ->name('api.public.profile')->middleware('throttle:60,1');
