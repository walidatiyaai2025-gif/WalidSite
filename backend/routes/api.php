<?php

use App\Http\Controllers\Api\IdentityController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [IdentityController::class, 'login'])
    ->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/mobile/me', [IdentityController::class, 'me']);
    Route::post('/auth/logout', [IdentityController::class, 'logout']);
    Route::get('/admin/identity-check', [IdentityController::class, 'adminCheck']);
});
