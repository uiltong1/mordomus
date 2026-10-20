<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Financial\Http\Controllers\BillController;
use Mordomus\Financial\Http\Controllers\BillOccurrenceController;
use Mordomus\Financial\Http\Controllers\BillSummaryController;
use Mordomus\Financial\Http\Controllers\SplitController;
use Mordomus\Financial\Http\Controllers\SplitRuleController;

Route::middleware('auth:jwt')->group(function () {
    Route::middleware('tenant')->group(function () {
        Route::get('/bills', [BillController::class, 'index']);
        Route::get('/bills/{bill}', [BillController::class, 'show']);
        Route::get('/occurrences', [BillOccurrenceController::class, 'index']);
        Route::get('/summary', [BillSummaryController::class, 'summary']);
        Route::get('/split-rules', [SplitRuleController::class, 'index']);
        Route::get('/occurrences/{billOccurrence}/split', [SplitController::class, 'show']);

        Route::middleware('capability:bills.manage')->group(function () {
            Route::post('/bills', [BillController::class, 'store']);
            Route::patch('/bills/{bill}', [BillController::class, 'update']);
            Route::post('/occurrences', [BillOccurrenceController::class, 'store']);
        });

        Route::middleware('capability:splits.manage')->group(function () {
            Route::put('/split-rules', [SplitRuleController::class, 'update']);
            Route::patch('/occurrences/{billOccurrence}/split', [SplitController::class, 'settle']);
        });

        Route::middleware('capability:bills.pay')->group(function () {
            Route::post('/occurrences/{billOccurrence}/paid', [BillOccurrenceController::class, 'pay']);
        });
    });
});
