<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Scheduling\Http\Controllers\TriggerConfigController;

/*
|--------------------------------------------------------------------------
| Módulo Scheduling — /api/v1/scheduling/*
|--------------------------------------------------------------------------
| Leitura é livre para qualquer morador ativo da residência; criar, alterar e
| excluir regras exige `rules.edit`. As ocorrências (listagem, conclusão e
| skip) entram com a T3.2.
*/

Route::middleware('auth:jwt')->group(function () {
    Route::middleware('tenant')->group(function () {
        Route::get('/trigger-configs', [TriggerConfigController::class, 'index']);
        Route::get('/trigger-configs/{triggerConfig}', [TriggerConfigController::class, 'show']);
        Route::post('/preview', [TriggerConfigController::class, 'preview']);

        Route::middleware('capability:rules.edit')->group(function () {
            Route::post('/trigger-configs', [TriggerConfigController::class, 'store']);
            Route::patch('/trigger-configs/{triggerConfig}', [TriggerConfigController::class, 'update']);
            Route::delete('/trigger-configs/{triggerConfig}', [TriggerConfigController::class, 'destroy']);
        });
    });
});
