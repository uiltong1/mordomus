<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Identity\Http\Controllers\AuthController;
use Mordomus\Identity\Http\Controllers\HealthController;
use Mordomus\Identity\Http\Controllers\InvitationController;
use Mordomus\Identity\Http\Controllers\MeController;
use Mordomus\Identity\Http\Controllers\MemberController;
use Mordomus\Identity\Http\Controllers\TenantController;

/*
|--------------------------------------------------------------------------
| Rotas da Identity API (prefixo /api/v1/identity — bootstrap/app.php)
|--------------------------------------------------------------------------
*/

Route::get('/health', HealthController::class);

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');

Route::middleware('auth:jwt')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/switch-tenant', [AuthController::class, 'switchTenant']);

    Route::get('/me', [MeController::class, 'show']);

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);

    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

    // exige claim `tid` no JWT + membership ativo (middleware `tenant`)
    Route::middleware('tenant')->group(function () {
        Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
        Route::patch('/tenants/{tenant}', [TenantController::class, 'update']);
        Route::get('/tenants/{tenant}/preferences', [TenantController::class, 'preferences']);
        Route::patch('/tenants/{tenant}/preferences', [TenantController::class, 'updatePreferences']);

        Route::post('/tenants/{tenant}/invitations', [InvitationController::class, 'store']);

        Route::get('/tenants/{tenant}/members', [MemberController::class, 'index']);
        Route::patch('/tenants/{tenant}/members/{membership}', [MemberController::class, 'update']);
        Route::put('/tenants/{tenant}/members/{membership}/grants', [MemberController::class, 'updateGrants']);
    });
});
