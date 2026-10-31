<?php

use App\Http\Controllers\Api\IdentityController;
use App\Http\Controllers\Api\AiProviderController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [IdentityController::class, 'login'])
    ->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/mobile/me', [IdentityController::class, 'me']);
    Route::post('/auth/logout', [IdentityController::class, 'logout']);
    Route::get('/admin/identity-check', [IdentityController::class, 'adminCheck']);

    // Admin-scoped bearer tokens only; controller enforces role + token ability.
    Route::get('/admin/ai/providers', [AiProviderController::class, 'index']);
    Route::post('/admin/ai/providers', [AiProviderController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/admin/ai/providers/{provider}', [AiProviderController::class, 'show']);
    Route::patch('/admin/ai/providers/{provider}', [AiProviderController::class, 'update'])->middleware('throttle:20,1');
    Route::post('/admin/ai/providers/{provider}/test', [AiProviderController::class, 'test'])->middleware('throttle:5,1');
});
