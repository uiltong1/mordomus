<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Janela de materialização
    |--------------------------------------------------------------------------
    |
    | Quantos dias à frente o motor garante que a ocorrência exista. A janela
    |effective de uma regra é o maior valor entre este número, o
    | `advance_notice_days` dela e o maior offset negativo: um aviso só pode
    | disparar na data certa se a ocorrência já existir quando ele vencer.
    |
    */

    'horizon_days' => (int) env('SCHEDULING_HORIZON_DAYS', 45),

    /*
    |--------------------------------------------------------------------------
    | Filas do módulo
    |--------------------------------------------------------------------------
    |
    | Convenção `mordomus:<módulo>:<fila>`. As duas filas são distintas de
    | propósito: o ciclo de agendamento é do Scheduling, e os eventos
    | publicados são consumo do Notification (T6.1) — misturar os dois faria o
    | varrimento de 15 min esperar behind a fila de e-mail.
    |
    */

    'queues' => [
        'occurrences' => env('SCHEDULING_QUEUE', 'mordomus:scheduling:occurrences'),
        'events' => env('SCHEDULING_EVENTS_QUEUE', 'mordomus:notification:events'),
    ],

];
