<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Notification\Http\Controllers\DeviceTokenController;
use Mordomus\Notification\Http\Controllers\NotificationLogController;
use Mordomus\Notification\Http\Controllers\NotificationPreferenceController;

/*
|--------------------------------------------------------------------------
| Módulo Notification — /api/v1/notification/*
|--------------------------------------------------------------------------
| Tudo aqui é do morador, e não da casa: preferências, assinaturas e
| histórico são recorte do chamador, e por isso a leitura é livre para
| qualquer morador ativo.
|
| A escrita é `notifications.manage`, que o papel `member` já tem — quem
| decide quando quer ser acordado não é só o dono da casa.
|
| O consumo da fila `mordomus:notification:events` não mora aqui: ele é
| disparado pelo `PublishEvent`, que é o contrato do Scheduling (ADR-011).
*/

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
