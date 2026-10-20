<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Http\Controllers\HealthController;
use Mordomus\Http\Controllers\OpenApiDocsController;

Route::get('/health', HealthController::class);
Route::get('/openapi.yaml', [OpenApiDocsController::class, 'spec']);
Route::get('/docs', [OpenApiDocsController::class, 'ui']);

Route::prefix('identity')->group(__DIR__.'/modules/identity.php');
Route::prefix('maintenance')->group(__DIR__.'/modules/maintenance.php');
Route::prefix('scheduling')->group(__DIR__.'/modules/scheduling.php');
Route::prefix('financial')->group(__DIR__.'/modules/financial.php');
Route::prefix('notification')->group(__DIR__.'/modules/notification.php');
