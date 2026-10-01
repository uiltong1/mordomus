<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Http\Controllers\HealthController;
use Mordomus\Http\Controllers\OpenApiDocsController;

/*
|--------------------------------------------------------------------------
| Rotas da API — monólito modular
|--------------------------------------------------------------------------
| Prefixo global `api/v1` vem do bootstrap/app.php. Cada módulo declara o
| seu prefixo e as rotas vivem em routes/modules/<modulo>.php — acrescente o
| `Route::prefix(...)` abaixo quando o módulo ganhar as primeiras rotas.
|
| Escopo multitenant (`tenant`) e capabilities (`capability`) são aliases
| registrados no bootstrap/app.php.
*/

Route::get('/health', HealthController::class);
Route::get('/openapi.yaml', [OpenApiDocsController::class, 'spec']);
Route::get('/docs', [OpenApiDocsController::class, 'ui']);

Route::prefix('identity')->group(__DIR__.'/modules/identity.php');
Route::prefix('maintenance')->group(__DIR__.'/modules/maintenance.php');
Route::prefix('scheduling')->group(__DIR__.'/modules/scheduling.php');
Route::prefix('financial')->group(__DIR__.'/modules/financial.php');
