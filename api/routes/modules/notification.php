<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Notification\Http\Controllers\DeviceTokenController;
use Mordomus\Notification\Http\Controllers\NotificationLogController;
use Mordomus\Notification\Http\Controllers\NotificationPreferenceController;

Route::middleware('auth:jwt')->group(function () {
    Route::middleware('tenant')->group(function () {
        Route::get('/preferences', [NotificationPreferenceController::class, 'show']);
        Route::get('/devices', [DeviceTokenController::class, 'index']);
        Route::get('/logs', [NotificationLogController::class, 'index']);

        Route::middleware('capability:notifications.manage')->group(function () {
            Route::put('/preferences', [NotificationPreferenceController::class, 'update']);
            Route::post('/devices', [DeviceTokenController::class, 'store']);
            Route::delete('/devices', [DeviceTokenController::class, 'destroy']);
            Route::post('/devices/test', [DeviceTokenController::class, 'test']);
        });
    });
});
