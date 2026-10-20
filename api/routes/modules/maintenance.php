<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Maintenance\Http\Controllers\AssetController;
use Mordomus\Maintenance\Http\Controllers\AssetOccurrenceController;
use Mordomus\Maintenance\Http\Controllers\AssetScheduleController;
use Mordomus\Maintenance\Http\Controllers\RoomController;

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

        Route::middleware('capability:rules.edit')->group(function () {
            Route::post('/assets/{asset}/schedule', [AssetScheduleController::class, 'store']);
        });

        Route::middleware('capability:occurrences.complete')->group(function () {
            Route::post('/occurrences/{occurrence}/complete', [AssetOccurrenceController::class, 'complete']);
        });

        Route::middleware('capability:occurrences.skip')->group(function () {
            Route::post('/occurrences/{occurrence}/skip', [AssetOccurrenceController::class, 'skip']);
        });

        Route::get('/rooms/{room}', [RoomController::class, 'show']);
        Route::get('/assets/{asset}', [AssetController::class, 'show']);
    });
});
