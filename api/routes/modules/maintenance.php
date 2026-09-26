<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Maintenance\Http\Controllers\AssetController;
use Mordomus\Maintenance\Http\Controllers\RoomController;

/*
|--------------------------------------------------------------------------
| Módulo Maintenance — /api/v1/maintenance/*
|--------------------------------------------------------------------------
| Leitura é livre para qualquer morador ativo da residência; escrita
| exige a capability correspondente (só o owner as tem).
*/

Route::middleware('auth:jwt')->group(function () {
    Route::middleware('tenant')->group(function () {
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::get('/assets', [AssetController::class, 'index']);

        Route::middleware('capability:rooms.manage')->group(function () {
            Route::post('/rooms', [RoomController::class, 'store']);
            Route::put('/rooms/order', [RoomController::class, 'order']);
            Route::patch('/rooms/{room}', [RoomController::class, 'update']);
            Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
        });

        Route::middleware('capability:assets.manage')->group(function () {
            Route::post('/assets', [AssetController::class, 'store']);
            Route::patch('/assets/{asset}', [AssetController::class, 'update']);
            Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);
        });

        // {room}/{asset} resolvem dentro do controller, com o escopo de tenant
        // já ativo — o binding implícito rodaria antes do middleware `tenant`.
        Route::get('/rooms/{room}', [RoomController::class, 'show']);
        Route::get('/assets/{asset}', [AssetController::class, 'show']);
    });
});
