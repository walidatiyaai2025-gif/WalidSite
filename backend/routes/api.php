<?php

use App\Http\Controllers\Api\IdentityController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\PublicPortfolioController;
use App\Http\Controllers\Api\PublicArticleController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [IdentityController::class, 'login'])
    ->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/mobile/me', [IdentityController::class, 'me']);
    Route::post('/auth/logout', [IdentityController::class, 'logout']);
    Route::get('/admin/identity-check', [IdentityController::class, 'adminCheck']);

    // Explicit administrator-scoped token + role required in the controller.
    Route::get('/admin/ai/providers', [AiProviderController::class, 'index']);
    Route::post('/admin/ai/providers', [AiProviderController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/admin/ai/providers/{provider}', [AiProviderController::class, 'show']);
    Route::get('/admin/ai/providers/{provider}/audit', [AiProviderController::class, 'auditHistory'])
        ->middleware('throttle:60,1');
    Route::patch('/admin/ai/providers/{provider}', [AiProviderController::class, 'update'])->middleware('throttle:20,1');
    Route::delete('/admin/ai/providers/{provider}', [AiProviderController::class, 'destroy'])
        ->name('api.admin.ai.providers.destroy');
    Route::post('/admin/ai/providers/{provider}/test', [AiProviderController::class, 'test'])->middleware('throttle:5,1');
});

Route::get('/public/projects', [PublicPortfolioController::class, 'projects'])
    ->name('api.public.projects')->middleware('throttle:60,1');
Route::get('/public/projects/{slug}', [PublicPortfolioController::class, 'project'])
    ->where('slug', '[a-zA-Z0-9-]+')->name('api.public.project')->middleware('throttle:60,1');
Route::get('/public/profile', [PublicPortfolioController::class, 'profile'])
    ->name('api.public.profile')->middleware('throttle:60,1');

// T012 read-only editorial API: pinned, citation-checked public revisions only.
Route::get('/public/articles', [PublicArticleController::class, 'index'])
    ->name('api.public.articles')->middleware('throttle:60,1');
Route::get('/public/articles/{slug}', [PublicArticleController::class, 'show'])
    ->where('slug', '[a-zA-Z0-9-]+')->name('api.public.article')->middleware('throttle:60,1');
