<?php

use App\Auth\AuthPluginManager;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\MultifactorController;
use App\Http\Controllers\TenantController;
use App\Http\Middleware\AuthenticationBoundary;
use Illuminate\Support\Facades\Route;

Route::get('/auth/providers', fn () => app(AuthPluginManager::class)->listing());
Route::post('/auth/tenant', [TenantController::class, 'select'])->middleware('throttle:auth-actions');
Route::get('/auth/multifactor/redirect', [MultifactorController::class, 'redirect'])->middleware('throttle:auth-actions');
Route::get('/auth/multifactor/callback', [MultifactorController::class, 'callback'])->middleware('throttle:auth-actions')->block(10, 10);
Route::middleware(['auth:sanctum', AuthenticationBoundary::class])->group(function () {
    Route::get('/auth/me', [AuthSessionController::class, 'current']);
    Route::get('/auth/sessions', [AuthSessionController::class, 'index']);
    Route::delete('/auth/sessions/{id}', [AuthSessionController::class, 'destroy'])->middleware('throttle:auth-actions');
});

Route::get('/', function () {
    return response()->json([
        'service' => 'rline-api',
        'status' => 'ok',
    ]);
});
