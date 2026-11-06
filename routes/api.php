<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update']);
    Route::match(['get', 'put'], '/tenants/{tenant}/authentication', [TenantController::class, 'settings']);
    Route::get('/tenant/database', [TenantController::class, 'database']);
});

Route::get('/health', HealthController::class);
