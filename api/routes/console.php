<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Rotas de console do monólito
|--------------------------------------------------------------------------
| `withRouting(commands:)` só carrega este arquivo; os comandos de cada módulo
| vivem no próprio diretório `Console/Commands` (ADR-011) e entram por
| `withCommands()` no bootstrap.
*/

// O motor de agendamento é dono do tempo do produto: materializa a janela de
// ocorrências uma vez por dia (a janela padrão é longa, e repor o que falta a
// cada dia mantém o atraso de materialização em no máximo 24 h).
Schedule::command('scheduling:materialize')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// O varrimento de avisos é de 15 em 15 minutos (TECHSPEC §6.4).
Schedule::command('scheduling:publish-due')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
