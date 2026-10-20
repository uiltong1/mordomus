<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Scheduling\Http\Controllers\OccurrenceController;
use Mordomus\Scheduling\Http\Controllers\TriggerConfigController;

Route::middleware('auth:jwt')->group(function () {
    Route::middleware('tenant')->group(function () {
        Route::get('/trigger-configs', [TriggerConfigController::class, 'index']);
        Route::get('/trigger-configs/{triggerConfig}', [TriggerConfigController::class, 'show']);
        Route::post('/preview', [TriggerConfigController::class, 'preview']);

        Route::get('/occurrences', [OccurrenceController::class, 'index']);

        Route::middleware('capability:rules.edit')->group(function () {
            Route::post('/trigger-configs', [TriggerConfigController::class, 'store']);
            Route::patch('/trigger-configs/{triggerConfig}', [TriggerConfigController::class, 'update']);
            Route::delete('/trigger-configs/{triggerConfig}', [TriggerConfigController::class, 'destroy']);
        });

        Route::middleware('capability:occurrences.complete')->group(function () {
            Route::post('/occurrences/{occurrence}/complete', [OccurrenceController::class, 'complete']);
        });

        Route::middleware('capability:occurrences.skip')->group(function () {
            Route::post('/occurrences/{occurrence}/skip', [OccurrenceController::class, 'skip']);
        });
    });
});
