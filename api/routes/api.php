<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Http\Controllers\HealthController;

/*
|--------------------------------------------------------------------------
| Rotas da API — monólito modular (ADR-011)
|--------------------------------------------------------------------------
| Prefixo global `api/v1` vem do bootstrap/app.php. Cada módulo declara o
| seu prefixo e as rotas vivem em routes/modules/<modulo>.php — acrescente o
| `Route::prefix(...)` abaixo quando o módulo ganhar as primeiras rotas.
|
| Escopo multitenant (`tenant`) e capabilities (`capability`) são aliases
| registrados no bootstrap/app.php.
*/

Route::get('/health', HealthController::class);

Route::prefix('identity')->group(__DIR__.'/modules/identity.php');
Route::prefix('maintenance')->group(__DIR__.'/modules/maintenance.php');
